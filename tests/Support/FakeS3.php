<?php
/**
 * A bucket in memory that answers the S3 protocol.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Support;

use WP_Error;

/**
 * Installed as FakeHttp's responder, it answers requests the way S3 and R2
 * do, from state it keeps: objects, multipart uploads and their parts, and a
 * CORS policy. So a test drives an upload end to end -- start, parts, an
 * interruption, a resume, assembly -- and then looks at what the bucket
 * holds, rather than at a queue of canned answers in the order the code
 * happened to ask.
 *
 * The rules that catch real mistakes are kept:
 *
 *   - Content-MD5 and a signed x-amz-content-sha256 are checked against the
 *     body (BadDigest, XAmzContentSHA256Mismatch).
 *   - Assembly needs every named part, with its ETag, in ascending order;
 *     every part but the last at least 5 MiB and, as R2 insists, all of the
 *     same size.
 *   - ListParts and ListMultipartUploads page, and stop being truncated.
 *   - A finished or aborted upload is gone (NoSuchUpload).
 *
 * Faults are injected with fail(): an answer instead of the real one, or
 * the real work done and the answer lost, which is what a timed-out request
 * looks like from the side that sent it.
 */
final class FakeS3 {

	/**
	 * Objects, key => bytes.
	 *
	 * @var array<string, string>
	 */
	public array $objects = [];

	/**
	 * Object types, key => Content-Type.
	 *
	 * @var array<string, string>
	 */
	public array $types = [];

	/**
	 * Unfinished uploads, upload id => [ key, type, initiated, parts => [ number => bytes ] ].
	 *
	 * @var array<string, array{key: string, type: string, initiated: int, parts: array<int, string>}>
	 */
	public array $uploads = [];

	/**
	 * The CORS policy document, or '' for none.
	 *
	 * @var string
	 */
	public string $cors = '';

	/**
	 * The clock, for Initiated times.
	 *
	 * @var int
	 */
	public int $now = 1800000000;

	/**
	 * Smallest part but the last.
	 *
	 * @var int
	 */
	public int $min_part = 5 * 1048576;

	/**
	 * Parts ListParts answers with at most, whatever is asked.
	 *
	 * @var int
	 */
	public int $parts_page = 1000;

	/**
	 * Uploads ListMultipartUploads answers with at most, whatever is asked.
	 *
	 * @var int
	 */
	public int $uploads_page = 1000;

	/**
	 * Faults still to inject, in the order they were added.
	 *
	 * @var array<int, array{match: callable, answer: array|WP_Error|null, times: int}>
	 */
	private array $faults = [];

	/**
	 * Requests as [ method, key, query, headers, body ].
	 *
	 * @var array<int, array{method: string, key: string, query: array<string, string>, headers: array<string, string>, body: string}>
	 */
	public array $log = [];

	/**
	 * Uploads started so far, for ids.
	 *
	 * @var int
	 */
	private int $serial = 0;

	/**
	 * Answer every request from here on.
	 *
	 * @param string $bucket The bucket's name; anything else is NoSuchBucket.
	 *
	 * @return self
	 */
	public static function install( string $bucket = 'bucket' ): self {
		$fake = new self( $bucket );
		FakeHttp::respond_with( [ $fake, 'handle' ] );

		return $fake;
	}

	/**
	 * A bucket.
	 *
	 * @param string $bucket Its name.
	 */
	public function __construct( private string $bucket = 'bucket' ) {}

	/**
	 * Make matching requests go wrong.
	 *
	 * @param callable            $match  fn( string $method, string $key, array $query, array $headers ): bool.
	 * @param array|WP_Error|null $answer What to answer instead, or null to
	 *                                    do the real work and then lose the
	 *                                    answer (a transport error).
	 * @param int                 $times  How many matching requests.
	 *
	 * @return void
	 */
	public function fail( callable $match, array|WP_Error|null $answer = null, int $times = 1 ): void {
		$this->faults[] = [
			'match'  => $match,
			'answer' => $answer,
			'times'  => $times,
		];
	}

	/**
	 * Requests of one kind, from the log.
	 *
	 * @param string $method HTTP method.
	 * @param string $query  A query parameter the request had, or ''.
	 *
	 * @return array<int, array>
	 */
	public function requests( string $method, string $query = '' ): array {
		return array_values( array_filter(
			$this->log,
			static fn( array $entry ): bool => $method === $entry['method'] && ( '' === $query || array_key_exists( $query, $entry['query'] ) )
		) );
	}

	/**
	 * Answer one request.
	 *
	 * @param string $url  Requested URL.
	 * @param array  $args wp_remote_request() arguments.
	 *
	 * @return array|WP_Error
	 */
	public function handle( string $url, array $args ): array|WP_Error {
		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$parts   = parse_url( $url );
		$path    = explode( '/', ltrim( (string) ( $parts['path'] ?? '' ), '/' ), 2 );
		$bucket  = rawurldecode( $path[0] );
		$key     = rawurldecode( $path[1] ?? '' );

		// Virtual-hosted: the bucket is the first label of the host.
		if ( str_starts_with( (string) ( $parts['host'] ?? '' ), $this->bucket . '.' ) ) {
			$bucket = $this->bucket;
			$key    = rawurldecode( ltrim( (string) ( $parts['path'] ?? '' ), '/' ) );
		}
		$headers = array_change_key_case( array_map( 'strval', (array) ( $args['headers'] ?? [] ) ), CASE_LOWER );
		$body    = (string) ( $args['body'] ?? '' );
		$query   = [];

		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		// parse_str() reads "?uploads" as an empty value, which is what S3
		// means by it.
		$query = array_map( 'strval', $query );

		$this->log[] = compact( 'method', 'key', 'query', 'headers', 'body' );

		foreach ( $this->faults as $index => $fault ) {
			if ( $fault['times'] > 0 && ( $fault['match'] )( $method, $key, $query, $headers ) ) {
				--$this->faults[ $index ]['times'];

				if ( null !== $fault['answer'] ) {
					return $fault['answer'];
				}

				$this->answer( $bucket, $method, $key, $query, $headers, $body );

				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 300000 milliseconds' );
			}
		}

		return $this->answer( $bucket, $method, $key, $query, $headers, $body );
	}

	/**
	 * The real answer.
	 *
	 * @param string $bucket  Bucket named.
	 * @param string $method  Method.
	 * @param string $key     Object key.
	 * @param array  $query   Query.
	 * @param array  $headers Request headers, lower-cased.
	 * @param string $body    Body.
	 *
	 * @return array
	 */
	private function answer( string $bucket, string $method, string $key, array $query, array $headers, string $body ): array {
		if ( $bucket !== $this->bucket ) {
			return self::error( 404, 'NoSuchBucket', 'The specified bucket does not exist.' );
		}

		if ( 'OPTIONS' === $method ) {
			return $this->preflight( $headers );
		}

		$digest = self::digest_problem( $headers, $body );

		if ( null !== $digest ) {
			return $digest;
		}

		return match ( true ) {
			'PUT' === $method && isset( $query['partNumber'], $query['uploadId'] ) => $this->upload_part( $key, $query, $body ),
			'PUT' === $method && isset( $query['cors'] )                           => $this->put_cors( $body ),
			'PUT' === $method                                                      => $this->put( $key, $headers, $body ),
			'POST' === $method && isset( $query['uploads'] )                       => $this->create( $key, $headers ),
			'POST' === $method && isset( $query['uploadId'] )                      => $this->complete( $key, $query['uploadId'], $body ),
			'DELETE' === $method && isset( $query['uploadId'] )                    => $this->abort( $query['uploadId'] ),
			'DELETE' === $method && isset( $query['cors'] )                        => $this->delete_cors(),
			'DELETE' === $method                                                   => $this->delete( $key ),
			'GET' === $method && isset( $query['uploadId'] )                       => $this->list_parts( $key, $query ),
			'GET' === $method && isset( $query['uploads'] )                        => $this->list_uploads( $query ),
			'GET' === $method && isset( $query['cors'] )                           => $this->get_cors(),
			'GET' === $method && isset( $query['list-type'] )                      => $this->list_objects( $query ),
			'GET' === $method                                                      => $this->get( $key, $headers ),
			'HEAD' === $method                                                     => $this->head( $key ),
			default                                                                => self::error( 405, 'MethodNotAllowed', 'Not here.' ),
		};
	}

	/**
	 * Content-MD5 and a signed SHA-256, checked as storage checks them.
	 *
	 * @param array  $headers Request headers.
	 * @param string $body    Body.
	 *
	 * @return array|null A refusal, or null when they agree.
	 */
	private static function digest_problem( array $headers, string $body ): ?array {
		if ( isset( $headers['content-md5'] ) && base64_encode( md5( $body, true ) ) !== $headers['content-md5'] ) {
			return self::error( 400, 'BadDigest', 'The Content-MD5 you specified did not match what we received.' );
		}

		$sha = $headers['x-amz-content-sha256'] ?? 'UNSIGNED-PAYLOAD';

		if ( 'UNSIGNED-PAYLOAD' !== $sha && hash( 'sha256', $body ) !== $sha ) {
			return self::error( 400, 'XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 header does not match what was computed.' );
		}

		return null;
	}

	/**
	 * PutObject.
	 *
	 * @param string $key     Key.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 *
	 * @return array
	 */
	private function put( string $key, array $headers, string $body ): array {
		$this->objects[ $key ] = $body;
		$this->types[ $key ]   = $headers['content-type'] ?? 'application/octet-stream';

		return FakeHttp::response( 200, '', [ 'ETag' => '"' . md5( $body ) . '"' ] + $this->exposed( $headers ) );
	}

	/**
	 * The CORS headers an actual (not preflight) response carries for an Origin.
	 *
	 * @param array $headers Request headers.
	 *
	 * @return array<string, string>
	 */
	private function exposed( array $headers ): array {
		$origin = $headers['origin'] ?? '';

		if ( '' === $origin || '' === $this->cors || ! str_contains( $this->cors, $origin ) && ! str_contains( $this->cors, '<AllowedOrigin>*</AllowedOrigin>' ) ) {
			return [];
		}

		preg_match_all( '#<ExposeHeader>([^<]+)</ExposeHeader>#', $this->cors, $expose );

		return [
			'Access-Control-Allow-Origin'   => $origin,
			'Access-Control-Expose-Headers' => implode( ', ', $expose[1] ),
		];
	}

	/**
	 * CreateMultipartUpload.
	 *
	 * @param string $key     Key.
	 * @param array  $headers Headers.
	 *
	 * @return array
	 */
	private function create( string $key, array $headers ): array {
		$id                   = 'upload-' . ( ++$this->serial ) . '-' . substr( md5( $key . $this->serial ), 0, 8 );
		$this->uploads[ $id ] = [
			'key'       => $key,
			'type'      => $headers['content-type'] ?? 'application/octet-stream',
			'initiated' => $this->now,
			'parts'     => [],
		];

		return FakeHttp::response( 200, '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult><Bucket>' . $this->bucket . '</Bucket><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>' );
	}

	/**
	 * UploadPart.
	 *
	 * @param string $key   Key.
	 * @param array  $query Query.
	 * @param string $body  Body.
	 *
	 * @return array
	 */
	private function upload_part( string $key, array $query, string $body ): array {
		$id     = $query['uploadId'];
		$number = (int) $query['partNumber'];

		if ( ! isset( $this->uploads[ $id ] ) || $this->uploads[ $id ]['key'] !== $key ) {
			return self::error( 404, 'NoSuchUpload', 'The specified multipart upload does not exist.' );
		}

		if ( $number < 1 || $number > 10000 ) {
			return self::error( 400, 'InvalidArgument', 'Part number must be an integer between 1 and 10000, inclusive.' );
		}

		$this->uploads[ $id ]['parts'][ $number ] = $body;

		return FakeHttp::response( 200, '', [ 'ETag' => '"' . md5( $body ) . '"' ] );
	}

	/**
	 * CompleteMultipartUpload.
	 *
	 * @param string $key  Key.
	 * @param string $id   Upload id.
	 * @param string $body The parts document.
	 *
	 * @return array
	 */
	private function complete( string $key, string $id, string $body ): array {
		if ( ! isset( $this->uploads[ $id ] ) || $this->uploads[ $id ]['key'] !== $key ) {
			return self::error( 404, 'NoSuchUpload', 'The specified multipart upload does not exist.' );
		}

		preg_match_all( '#<Part><PartNumber>(\d+)</PartNumber><ETag>([^<]*)</ETag></Part>#', $body, $named, PREG_SET_ORDER );

		if ( [] === $named ) {
			return self::error( 400, 'MalformedXML', 'The XML you provided was not well-formed.' );
		}

		$held   = $this->uploads[ $id ]['parts'];
		$last   = 0;
		$bytes  = '';
		$sizes  = [];
		$count  = count( $named );

		foreach ( $named as $index => [ , $number, $etag ] ) {
			$number = (int) $number;
			$etag   = trim( html_entity_decode( $etag, ENT_QUOTES | ENT_XML1 ), '"' );

			if ( $number <= $last ) {
				return self::error( 400, 'InvalidPartOrder', 'The list of parts was not in ascending order.' );
			}

			if ( ! isset( $held[ $number ] ) || md5( $held[ $number ] ) !== $etag ) {
				return self::error( 400, 'InvalidPart', 'One or more of the specified parts could not be found.' );
			}

			if ( $index < $count - 1 ) {
				if ( strlen( $held[ $number ] ) < $this->min_part ) {
					return self::error( 400, 'EntityTooSmall', 'Your proposed upload is smaller than the minimum allowed object size.' );
				}

				$sizes[] = strlen( $held[ $number ] );
			}

			$bytes .= $held[ $number ];
			$last   = $number;
		}

		// R2's rule: every part but the last the same size.
		if ( count( array_unique( $sizes ) ) > 1 ) {
			return self::error( 400, 'InvalidPart', 'All non-trailing parts must have the same length.' );
		}

		$this->objects[ $key ] = $bytes;
		$this->types[ $key ]   = $this->uploads[ $id ]['type'];
		unset( $this->uploads[ $id ] );

		return FakeHttp::response( 200, '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><ETag>&quot;' . md5( $bytes ) . '-' . $count . '&quot;</ETag></CompleteMultipartUploadResult>' );
	}

	/**
	 * AbortMultipartUpload.
	 *
	 * @param string $id Upload id.
	 *
	 * @return array
	 */
	private function abort( string $id ): array {
		if ( ! isset( $this->uploads[ $id ] ) ) {
			return self::error( 404, 'NoSuchUpload', 'The specified multipart upload does not exist.' );
		}

		unset( $this->uploads[ $id ] );

		return FakeHttp::response( 204 );
	}

	/**
	 * ListParts.
	 *
	 * @param string $key   Key.
	 * @param array  $query Query.
	 *
	 * @return array
	 */
	private function list_parts( string $key, array $query ): array {
		$id = $query['uploadId'];

		if ( ! isset( $this->uploads[ $id ] ) || $this->uploads[ $id ]['key'] !== $key ) {
			return self::error( 404, 'NoSuchUpload', 'The specified multipart upload does not exist.' );
		}

		$parts  = $this->uploads[ $id ]['parts'];
		$marker = (int) ( $query['part-number-marker'] ?? 0 );
		$max    = min( $this->parts_page, max( 1, (int) ( $query['max-parts'] ?? 1000 ) ) );

		ksort( $parts );

		$after = array_filter( $parts, static fn( int $number ): bool => $number > $marker, ARRAY_FILTER_USE_KEY );
		$page  = array_slice( $after, 0, $max, true );
		$more  = count( $after ) > count( $page );
		$xml   = '<?xml version="1.0" encoding="UTF-8"?><ListPartsResult><Bucket>' . $this->bucket . '</Bucket><UploadId>' . $id . '</UploadId>';

		foreach ( $page as $number => $bytes ) {
			$xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>&quot;' . md5( $bytes ) . '&quot;</ETag><Size>' . strlen( $bytes ) . '</Size></Part>';
		}

		$xml .= '<IsTruncated>' . ( $more ? 'true' : 'false' ) . '</IsTruncated>';
		$xml .= $more ? '<NextPartNumberMarker>' . array_key_last( $page ) . '</NextPartNumberMarker>' : '';

		return FakeHttp::response( 200, $xml . '</ListPartsResult>' );
	}

	/**
	 * ListMultipartUploads.
	 *
	 * @param array $query Query.
	 *
	 * @return array
	 */
	private function list_uploads( array $query ): array {
		$all = [];

		foreach ( $this->uploads as $id => $upload ) {
			if ( str_starts_with( $upload['key'], (string) ( $query['prefix'] ?? '' ) ) ) {
				$all[] = [ $upload['key'], $id, $upload['initiated'] ];
			}
		}

		usort( $all, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

		$key_marker = (string) ( $query['key-marker'] ?? '' );
		$id_marker  = (string) ( $query['upload-id-marker'] ?? '' );

		if ( '' !== $key_marker ) {
			$all = array_values( array_filter( $all, static fn( array $u ): bool => [ $u[0], $u[1] ] > [ $key_marker, $id_marker ] ) );
		}

		$max  = min( $this->uploads_page, max( 1, (int) ( $query['max-uploads'] ?? 1000 ) ) );
		$page = array_slice( $all, 0, $max );
		$more = count( $all ) > count( $page );
		$xml  = '<?xml version="1.0" encoding="UTF-8"?><ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>' . $this->bucket . '</Bucket>';

		foreach ( $page as [ $key, $id, $initiated ] ) {
			$xml .= '<Upload><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><UploadId>' . $id . '</UploadId><Initiated>' . gmdate( 'Y-m-d\TH:i:s.000\Z', $initiated ) . '</Initiated></Upload>';
		}

		$xml .= '<IsTruncated>' . ( $more ? 'true' : 'false' ) . '</IsTruncated>';

		if ( $more ) {
			$tail = $page[ count( $page ) - 1 ];
			$xml .= '<NextKeyMarker>' . htmlspecialchars( $tail[0], ENT_XML1 ) . '</NextKeyMarker><NextUploadIdMarker>' . $tail[1] . '</NextUploadIdMarker>';
		}

		return FakeHttp::response( 200, $xml . '</ListMultipartUploadsResult>' );
	}

	/**
	 * ListObjectsV2, enough of it.
	 *
	 * @param array $query Query.
	 *
	 * @return array
	 */
	private function list_objects( array $query ): array {
		$prefix    = (string) ( $query['prefix'] ?? '' );
		$delimiter = (string) ( $query['delimiter'] ?? '' );
		$max       = max( 1, (int) ( $query['max-keys'] ?? 1000 ) );
		$start     = (int) ( $query['continuation-token'] ?? 0 );
		$entries   = [];
		$keys      = array_map( 'strval', array_keys( $this->objects ) );

		sort( $keys, SORT_STRING );

		// Keys and, with a delimiter, the folders they roll up into, in order.
		foreach ( $keys as $key ) {
			if ( ! str_starts_with( $key, $prefix ) ) {
				continue;
			}

			$rest = substr( $key, strlen( $prefix ) );
			$cut  = '' === $delimiter ? false : strpos( $rest, $delimiter );

			if ( false !== $cut ) {
				$entries[ 'p:' . $prefix . substr( $rest, 0, $cut + 1 ) ] = [ 'prefix', $prefix . substr( $rest, 0, $cut + 1 ) ];
				continue;
			}

			$entries[ 'k:' . $key ] = [ 'key', $key ];
		}

		$entries   = array_values( $entries );
		$page      = array_slice( $entries, $start, $max );
		$truncated = $start + $max < count( $entries );
		$xml       = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>' . $this->bucket . '</Name><IsTruncated>' . ( $truncated ? 'true' : 'false' ) . '</IsTruncated>';

		if ( $truncated ) {
			$xml .= '<NextContinuationToken>' . ( $start + $max ) . '</NextContinuationToken>';
		}

		foreach ( $page as [ $type, $name ] ) {
			$xml .= 'prefix' === $type
				? '<CommonPrefixes><Prefix>' . htmlspecialchars( $name, ENT_XML1 ) . '</Prefix></CommonPrefixes>'
				: '<Contents><Key>' . htmlspecialchars( $name, ENT_XML1 ) . '</Key><LastModified>2026-08-21T14:29:00.000Z</LastModified><Size>' . strlen( $this->objects[ $name ] ) . '</Size><ETag>&quot;' . md5( $this->objects[ $name ] ) . '&quot;</ETag></Contents>';
		}

		return FakeHttp::response( 200, $xml . '</ListBucketResult>' );
	}

	/**
	 * GetObject, with a Range.
	 *
	 * @param string $key     Key.
	 * @param array  $headers Headers.
	 *
	 * @return array
	 */
	private function get( string $key, array $headers ): array {
		if ( ! isset( $this->objects[ $key ] ) ) {
			return self::error( 404, 'NoSuchKey', 'The specified key does not exist.' );
		}

		$bytes = $this->objects[ $key ];
		$total = strlen( $bytes );

		if ( 1 === preg_match( '/^bytes=(\d+)-(\d+)$/', $headers['range'] ?? '', $range ) ) {
			$from = (int) $range[1];
			$to   = min( (int) $range[2], $total - 1 );

			return FakeHttp::response( 206, substr( $bytes, $from, $to - $from + 1 ), [
				'Content-Range' => 'bytes ' . $from . '-' . $to . '/' . $total,
				'ETag'          => '"' . md5( $bytes ) . '"',
			] );
		}

		return FakeHttp::response( 200, $bytes, [
			'Content-Length' => (string) $total,
			'Content-Type'   => $this->types[ $key ] ?? 'application/octet-stream',
		] );
	}

	/**
	 * HeadObject.
	 *
	 * @param string $key Key.
	 *
	 * @return array
	 */
	private function head( string $key ): array {
		if ( ! isset( $this->objects[ $key ] ) ) {
			return FakeHttp::response( 404 );
		}

		return FakeHttp::response( 200, '', [
			'Content-Length' => (string) strlen( $this->objects[ $key ] ),
			'Content-Type'   => $this->types[ $key ] ?? 'application/octet-stream',
		] );
	}

	/**
	 * DeleteObject: 204 whether or not it was there.
	 *
	 * @param string $key Key.
	 *
	 * @return array
	 */
	private function delete( string $key ): array {
		unset( $this->objects[ $key ], $this->types[ $key ] );

		return FakeHttp::response( 204 );
	}

	/**
	 * GetBucketCors.
	 *
	 * @return array
	 */
	private function get_cors(): array {
		return '' === $this->cors
			? self::error( 404, 'NoSuchCORSConfiguration', 'The CORS configuration does not exist' )
			: FakeHttp::response( 200, $this->cors );
	}

	/**
	 * PutBucketCors.
	 *
	 * @param string $body The policy.
	 *
	 * @return array
	 */
	private function put_cors( string $body ): array {
		$this->cors = $body;

		return FakeHttp::response( 200 );
	}

	/**
	 * DeleteBucketCors.
	 *
	 * @return array
	 */
	private function delete_cors(): array {
		$this->cors = '';

		return FakeHttp::response( 204 );
	}

	/**
	 * A browser's preflight, answered from the stored policy.
	 *
	 * @param array $headers Request headers.
	 *
	 * @return array
	 */
	private function preflight( array $headers ): array {
		$origin    = $headers['origin'] ?? '';
		$method    = strtoupper( $headers['access-control-request-method'] ?? '' );
		$requested = array_filter( array_map( 'trim', explode( ',', strtolower( $headers['access-control-request-headers'] ?? '' ) ) ) );

		preg_match_all( '#<CORSRule>(.*?)</CORSRule>#s', $this->cors, $rules );

		foreach ( $rules[1] as $rule ) {
			preg_match_all( '#<AllowedOrigin>([^<]+)</AllowedOrigin>#', $rule, $origins );
			preg_match_all( '#<AllowedMethod>([^<]+)</AllowedMethod>#', $rule, $methods );
			preg_match_all( '#<AllowedHeader>([^<]+)</AllowedHeader>#', $rule, $allowed );

			$allowed = array_map( 'strtolower', $allowed[1] );
			$headers_ok = in_array( '*', $allowed, true ) || [] === array_diff( $requested, $allowed );

			if ( ( in_array( $origin, $origins[1], true ) || in_array( '*', $origins[1], true ) ) && in_array( $method, $methods[1], true ) && $headers_ok ) {
				return FakeHttp::response( 200, '', [
					'Access-Control-Allow-Origin'  => $origin,
					'Access-Control-Allow-Methods' => implode( ', ', $methods[1] ),
					'Access-Control-Allow-Headers' => implode( ', ', $requested ),
				] );
			}
		}

		return self::error( 403, 'AccessForbidden', 'CORSResponse: This CORS request is not allowed.' );
	}

	/**
	 * An S3 error document.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    S3 code.
	 * @param string $message S3 message.
	 *
	 * @return array
	 */
	public static function error( int $status, string $code, string $message ): array {
		return FakeHttp::response( $status, '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . $message . '</Message></Error>' );
	}
}
