<?php
/**
 * Canned HTTP responses for the WordPress HTTP API stubs.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Tests\Support;

use WP_Error;

/**
 * Stands in for the WordPress HTTP API, so a request can be driven all the way
 * through the package against a real provider payload, with no network.
 *
 * Every request is recorded. Each is answered by, in order: the responder a
 * test installed, the front of the queue, or a 200 with no headers and no
 * body.
 */
final class FakeHttp {

	/**
	 * Queued answers.
	 *
	 * @var array<int, array<string, mixed>|WP_Error>
	 */
	private static array $queue = [];

	/**
	 * Requests made since the last reset.
	 *
	 * @var array<int, array{url:string, args:array}>
	 */
	private static array $requests = [];

	/**
	 * A function that answers every request, when set.
	 *
	 * @var callable|null
	 */
	private static $responder = null;

	/**
	 * Queue the next response.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $body    Response body.
	 * @param array  $headers Response headers.
	 *
	 * @return void
	 */
	public static function queue( int $status, string $body = '', array $headers = [] ): void {
		self::$queue[] = self::response( $status, $body, $headers );
	}

	/**
	 * Queue a response body loaded from tests/fixtures.
	 *
	 * @param string $name   Fixture filename.
	 * @param int    $status HTTP status.
	 *
	 * @return void
	 */
	public static function queue_fixture( string $name, int $status = 200 ): void {
		self::queue( $status, (string) file_get_contents( __DIR__ . '/../fixtures/' . $name ) );
	}

	/**
	 * Queue a transport failure.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	public static function queue_error( string $code = 'http_request_failed', string $message = 'cURL error 6: Could not resolve host' ): void {
		self::$queue[] = new WP_Error( $code, $message );
	}

	/**
	 * Answer every request with a function of ( $url, $args ).
	 *
	 * It returns a WordPress-shaped response array or a WP_Error. Null
	 * removes it.
	 *
	 * @param callable|null $responder The function.
	 *
	 * @return void
	 */
	public static function respond_with( ?callable $responder ): void {
		self::$responder = $responder;
	}

	/**
	 * A WordPress-shaped response array.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 *
	 * @return array<string, mixed>
	 */
	public static function response( int $status, string $body = '', array $headers = [] ): array {
		return [
			'response' => [ 'code' => $status, 'message' => '' ],
			'body'     => $body,
			'headers'  => $headers,
		];
	}

	/**
	 * Record a request and answer it.
	 *
	 * @param string $url  Requested URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function respond( string $url, array $args ): array|WP_Error {
		self::$requests[] = [ 'url' => $url, 'args' => $args ];

		if ( null !== self::$responder ) {
			return ( self::$responder )( $url, $args );
		}

		return array_shift( self::$queue ) ?? self::response( 200 );
	}

	/**
	 * Every request made since the last reset.
	 *
	 * @return array<int, array{url:string, args:array}>
	 */
	public static function requests(): array {
		return self::$requests;
	}

	/**
	 * The most recent request.
	 *
	 * @return array{url:string, args:array}|null
	 */
	public static function last(): ?array {
		return [] === self::$requests ? null : self::$requests[ count( self::$requests ) - 1 ];
	}

	/**
	 * Clear queued responses, the responder and recorded requests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$queue     = [];
		self::$requests  = [];
		self::$responder = null;
	}
}
