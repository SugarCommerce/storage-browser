<?php
/**
 * REST Controller
 *
 * The browser's REST surface: route registration, permission checks, argument
 * schemas and the request handlers behind them.
 *
 * This was two traits mixed into Browser, which meant the routes could only be
 * reasoned about with the whole browser -- its admin hooks, its asset
 * enqueueing, its media-library tab -- standing behind them. Nothing here
 * needs any of that. It needs a client, who is allowed to call it, and which
 * buckets it may address.
 *
 * @package     SugarCommerce\StorageBrowser\Rest
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Rest;

use SugarCommerce\StorageBrowser\Utils\Runtime;
use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Multipart;
use SugarCommerce\S3\Probe;
use SugarCommerce\S3\ProbeStep;
use SugarCommerce\S3\Transport;
use SugarCommerce\S3\Upload;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Picker\Listing;
use SugarCommerce\S3\Cors\Origin;
use SugarCommerce\StorageBrowser\Tables\Objects;
use SugarCommerce\StorageBrowser\Utils\Directory;
use SugarCommerce\StorageBrowser\Utils\Sanitize;
use SugarCommerce\StorageBrowser\Utils\Timestamp;
use SugarCommerce\StorageBrowser\Utils\Validate;
use SugarCommerce\Storage\Files\Filename;
use Closure;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Controller
 */
class Controller {

	/**
	 * Build a controller for one browser instance.
	 *
	 * @param Client  $client          Client the handlers act through.
	 * @param string  $provider_id     Provider identifier, used in filter names.
	 * @param string  $capability      Capability required to call any route.
	 * @param string  $route_base      Segment identifying this instance's routes.
	 * @param Closure $allowed_buckets Resolver for the bucket allow-list.
	 * @param string  $default_bucket  Bucket the browser opens on, or ''.
	 */
	public function __construct(
		private Client $client,
		private string $provider_id,
		private string $capability,
		private string $route_base,
		// Resolved per request rather than captured once: the allow-list runs
		// through a filter, so an integration can scope it by user or context
		// and that has to be honoured at call time, not at registration time.
		private Closure $allowed_buckets,
		// The bucket the browser opens on, which the connection test checks
		// when no fallback bucket is filtered in.
		private string $default_bucket = ''
	) {
	}

	/**
	 * Most part URLs one request may ask for: enough to keep a browser busy
	 * for a while, few enough that a URL is not left waiting long enough to
	 * expire.
	 */
	public const PARTS_PER_SIGNING = 100;

	/**
	 * Seconds a part URL works for.
	 */
	private const PART_URL_SECONDS = 3600;

	/**
	 * Explicit REST namespace override, if the consumer set one.
	 *
	 * @var string
	 */
	protected string $rest_namespace = '';

	/**
	 * Route bases already claimed in this request, as "namespace|base".
	 *
	 * Static so it spans every Browser instance in the process, including
	 * instances belonging to entirely different plugins that each bundle their
	 * own prefixed copy of this library.
	 *
	 * @var array<string, string>
	 */
	private static array $claimed_routes = [];

	/**
	 * Set an explicit REST namespace for this browser
	 *
	 * Only needed when the automatic derivation is not distinct enough — see
	 * get_rest_namespace().
	 *
	 * @param string $namespace Namespace, e.g. 'my-plugin/v1'.
	 *
	 * @return self
	 */
	public function set_rest_namespace( string $namespace ): self {
		$this->rest_namespace = trim( $namespace, '/' );

		return $this;
	}

	/**
	 * Get the REST namespace for this browser
	 *
	 * A REST route is a public URL, which means it lives in a global space that
	 * Strauss cannot isolate. Strauss rewrites PHP symbols; it does not rewrite
	 * runtime strings like route paths, option names or action names. So if an
	 * EDD plugin and a WooCommerce plugin each bundle this library, each gets
	 * its own PHP namespace but both would otherwise register the *same* route.
	 *
	 * That is not merely wasteful. WP_REST_Server::register_route() merges
	 * same-path registrations with array_merge() on a numerically-indexed
	 * handler list, so the handlers are appended rather than replaced, and
	 * dispatch runs the first one whose methods match. The plugin that
	 * registered first would therefore answer the other plugin's requests —
	 * using its own credentials, its own capability, and its own bucket
	 * allow-list.
	 *
	 * The default derivation exploits the very thing Strauss *does* rewrite:
	 * the package's namespace, which in a prefixed build names the consumer
	 * ({@see Runtime::build()}) and so is unique per plugin by construction,
	 * with no configuration required.
	 *
	 * @return string
	 */
	public function get_rest_namespace(): string {
		if ( '' !== $this->rest_namespace ) {
			return $this->rest_namespace;
		}

		// Unprefixed (plain Composer install, or development) there is no
		// build name, and the routes are the library's own.
		$build = Runtime::build();

		return ( '' === $build ? 's3-browser' : $build . '-s3-browser' ) . '/v1';
	}

	/**
	 * Get the path this instance's routes live under
	 *
	 * Namespace and base joined, which is what a caller building a URL wants.
	 * Both halves separately is how the browser's asset config came to call a
	 * method that had moved, and to do it in a code path no test covers.
	 *
	 * @return string
	 */
	public function route_path(): string {
		return $this->get_rest_namespace() . '/' . $this->get_rest_route_base();
	}

	/**
	 * Get the route base identifying this browser instance
	 *
	 * @return string
	 */
	protected function get_rest_route_base(): string {
		return sanitize_key( $this->route_base );
	}

	/**
	 * Register REST routes for this browser instance
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$namespace = $this->get_rest_namespace();
		$base      = $this->get_rest_route_base();
		$claim     = $namespace . '|' . $base;

		// Refuse to double-register rather than let WordPress silently merge
		// two instances' handlers onto one path.
		if ( isset( self::$claimed_routes[ $claim ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: 1: REST namespace, 2: route base */
					esc_html__( 'REST routes for "%1$s/%2$s" are already registered by another S3 Browser instance. Give this instance a distinct context, or call set_rest_namespace() with a unique namespace. Skipping registration to avoid serving requests with the wrong credentials.', 'sugarcommerce' ),
					esc_html( $namespace ),
					esc_html( $base )
				),
				'1.2.0'
			);

			return;
		}

		self::$claimed_routes[ $claim ] = $claim;

		$bucket_arg = [
			'bucket' => [
				'description'       => __( 'Bucket name.', 'sugarcommerce' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => [ $this, 'rest_validate_bucket' ],
				'sanitize_callback' => 'sanitize_text_field',
			],
		];

		$object_key_arg = [
			'key' => [
				'description'       => __( 'Object key.', 'sugarcommerce' ),
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
			],
		];

		// --- Connection test -------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/connection', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_connection_test' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			],
		] );

		// --- Connection test, in full, for one bucket -------------------------
		register_rest_route( $namespace, '/' . $base . '/connection/check', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_connection_check' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg,
			],
		] );

		// --- Large uploads, in parts -----------------------------------------
		$upload_arg = [
			'id' => [
				'description' => __( 'Upload id.', 'sugarcommerce' ),
				'type'        => 'string',
				'required'    => true,
				'pattern'     => '^[a-f0-9]{32}$',
			],
		];

		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/uploads', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_start_upload' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg + [
					'size'        => [
						'description' => __( 'Bytes in the file.', 'sugarcommerce' ),
						'type'        => 'integer',
						'required'    => true,
						'minimum'     => 1,
					],
					'mime_type'   => [
						'type'              => 'string',
						'default'           => 'application/octet-stream',
						'sanitize_callback' => 'sanitize_mime_type',
					],
					'fingerprint' => [
						'description'       => __( 'What identifies the file to the browser, so the same file dropped again resumes.', 'sugarcommerce' ),
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 255,
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
		] );

		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_upload_status' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $upload_arg,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_abort_upload' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $upload_arg,
			],
		] );

		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})/parts', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_sign_upload_parts' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $upload_arg + [
					'parts' => [
						'description' => __( 'Part numbers to sign.', 'sugarcommerce' ),
						'type'        => 'array',
						'required'    => true,
						'minItems'    => 1,
						'maxItems'    => self::PARTS_PER_SIGNING,
						'items'       => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Bucket::MAX_PARTS,
						],
					],
				],
			],
		] );

		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/uploads/(?P<id>[a-f0-9]{32})/complete', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_complete_upload' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $upload_arg,
			],
		] );

		// --- Cache -----------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/cache', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_clear_cache' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			],
		] );

		// --- Buckets, for the picker -----------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_list_buckets' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			],
		] );

		// --- A folder, or a search beneath it, as JSON for the picker -------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/browse', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_browse' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'prefix'             => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
					'search'             => [
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 200,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'continuation_token' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		] );

		// --- Bucket details --------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_bucket_details' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg,
			],
		] );

		// --- Objects ---------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_list_objects' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'prefix'             => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
					'continuation_token' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_object' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg,
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'rest_rename_object' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'current_key'  => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
					'new_filename' => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_text' ],
					],
				],
			],
		] );

		// --- Presigned download URL ------------------------------------------
		// --- Move ------------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects/move', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_move_object' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg + [
					'target_prefix' => [
						'description'       => __( 'Folder to move the object into. Empty moves it to the bucket root.', 'sugarcommerce' ),
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
		] );

		// --- What else points at an object -----------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects/references', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_object_references' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg,
			],
		] );

		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects/download-url', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_get_download_url' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg + [
					'expires_minutes' => [
						'type'    => 'integer',
						'default' => 60,
						'minimum' => 1,
						'maximum' => 10080,
					],
				],
			],
		] );

		// --- Presigned upload URL --------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/objects/upload-url', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_get_upload_url' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + $object_key_arg,
			],
		] );

		// --- Folders ----------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/folders', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_list_folders' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'prefix' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_create_folder' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'folder_name' => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_text' ],
					],
					'prefix'      => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_folder' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'folder_path' => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'sanitize_callback' => [ $this, 'rest_sanitize_object_key' ],
					],
				],
			],
		] );

		// --- CORS -------------------------------------------------------------
		register_rest_route( $namespace, '/' . $base . '/buckets/(?P<bucket>[^/]+)/cors', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'rest_setup_cors' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg + [
					'origin' => [
						'type'              => 'string',
						'default'           => '',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'rest_delete_cors' ],
				'permission_callback' => [ $this, 'rest_bucket_permission_check' ],
				'args'                => $bucket_arg,
			],
		] );
	}

	/**
	 * Base permission check: capability only
	 *
	 * Nonce verification is deliberately absent. For cookie-authenticated REST
	 * requests WordPress validates the X-WP-Nonce header itself, in
	 * rest_cookie_check_errors(), before any permission_callback runs — so
	 * re-checking here would be redundant, and checking a *different* nonce
	 * would break legitimate clients using application passwords.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_permission_check() {
		if ( ! current_user_can( $this->capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Permission check for bucket-scoped routes
	 *
	 * Authorization lives here rather than in the handler so that every
	 * bucket-scoped route is gated by construction: a route cannot be added
	 * without a permission_callback, and this one cannot pass without the
	 * bucket being allowed.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_bucket_permission_check( WP_REST_Request $request ) {
		$allowed = $this->rest_permission_check();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$bucket = (string) $request['bucket'];

		if ( ! $this->is_bucket_allowed( $bucket ) ) {
			return new WP_Error(
				'rest_bucket_forbidden',
				__( 'You do not have permission to access this bucket.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Check whether a bucket may be addressed through this browser
	 *
	 * Every AJAX endpoint takes the bucket straight from $_POST, so without a
	 * check here the capability gate ('upload_files' by default — Author and
	 * up) grants read, write, rename and delete over *every* bucket the
	 * configured credentials can reach, not just the one the browser is
	 * pointed at. S3 credentials are usually account-wide, so that is a real
	 * privilege boundary and not a theoretical one.
	 *
	 * An empty allow-list preserves the historical behaviour of permitting any
	 * bucket. Sites that only ever use one bucket should set it — see
	 * Browser::set_allowed_buckets() and the 'browser_allowed_buckets'
	 * filter.
	 *
	 * @param string $bucket Bucket name from the request
	 *
	 * @return bool True if the bucket may be used
	 */
	protected function is_bucket_allowed( string $bucket ): bool {
		if ( '' === $bucket ) {
			return false;
		}

		// Reject anything that is not a syntactically valid bucket name before
		// it reaches URL building — the value ends up in a Host header or a
		// request path.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/i', $bucket ) ) {
			return false;
		}

		$allowed = ( $this->allowed_buckets )();

		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $bucket, $allowed, true );
	}

	/**
	 * Validate a bucket name argument
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool|WP_Error
	 */
	public function rest_validate_bucket( $value ) {
		if ( ! is_string( $value ) || ! $this->is_bucket_allowed( $value ) ) {
			return new WP_Error(
				'rest_invalid_bucket',
				__( 'Invalid bucket name.', 'sugarcommerce' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Sanitize an object key or prefix
	 *
	 * Object keys legitimately contain spaces, quotes, brackets and unicode, so
	 * they get slash-stripping and control-character removal rather than
	 * sanitize_text_field(), which would mangle them. Traversal is rejected
	 * downstream by S3\Endpoint::request_key().
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public function rest_sanitize_object_key( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = wp_unslash( (string) $value );

		// Strip control characters (including NUL) but keep everything else.
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
	}

	/**
	 * Sanitize a plain text argument
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public function rest_sanitize_text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/**
	 * Build a success response
	 *
	 * @param array $data   Payload.
	 * @param int   $status HTTP status.
	 *
	 * @return WP_REST_Response
	 */
	private function rest_ok( array $data = [], int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Build an error response from a failed S3 operation
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return WP_Error
	 */
	private function rest_fail( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Test the storage connection
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_connection_test() {
		$answer = $this->connection_answer();
		$data   = $answer->get_data();

		// The bucket the full check (connection/check) should try next, for
		// a script that shows each step.
		if ( is_array( $data ) && 'failed' !== ( $data['status'] ?? '' ) ) {
			$bucket = $this->check_bucket();

			if ( '' !== $bucket ) {
				$data['check_bucket'] = $bucket;
				$answer               = $this->rest_ok( $data );
			}
		}

		return $answer;
	}

	/**
	 * Run the whole connection test against one bucket
	 *
	 * What a store will do with real files, done with a tiny one: list,
	 * write, read back, delete, start and abort a multipart upload, and ask
	 * whether a browser on this site may upload straight to the bucket. Each
	 * step comes back with what went wrong and what to change.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_connection_check( WP_REST_Request $request ): WP_REST_Response {
		$bucket = (string) $request['bucket'];
		$origin = Origin::current();
		$steps  = ( new Probe() )->run( $this->client->bucket( $bucket ), '' === $origin ? [] : [ $origin ] );
		$failed = Probe::failure( $steps );
		$notes  = array_values( array_filter( array_map( static fn( ProbeStep $step ): string => $step->note, $steps ) ) );

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'status'  => null === $failed ? 'ok' : 'failed',
			'bucket'  => $bucket,
			'message' => null === $failed
				/* translators: %s: bucket name */
				? sprintf( __( '"%s" passed every check.', 'sugarcommerce' ), $bucket )
				: __( 'The connection test found a problem.', 'sugarcommerce' ),
			'summary' => null === $failed ? '' : $failed->message,
			'notes'   => $notes,
			'checks'  => array_map( static fn( ProbeStep $step ): array => $step->to_array(), $steps ),
		] );
	}

	/**
	 * The bucket the full connection check tries
	 *
	 * The fallback bucket a consumer filters in, else the bucket the browser
	 * opens on, else the one bucket it is confined to.
	 *
	 * @return string '' when there is none to try.
	 */
	private function check_bucket(): string {
		$bucket = (string) apply_filters( Runtime::hook( 's3_connection_test_fallback_bucket' ), '' );

		if ( '' === $bucket ) {
			$allowed = ( $this->allowed_buckets )();
			$bucket  = '' !== $this->default_bucket ? $this->default_bucket : ( 1 === count( $allowed ) ? (string) reset( $allowed ) : '' );
		}

		return $this->is_bucket_allowed( $bucket ) ? $bucket : '';
	}

	/**
	 * The first half of the connection test: the account and its buckets
	 *
	 * @return WP_REST_Response
	 */
	private function connection_answer(): WP_REST_Response {
		// Account-level ListBuckets works for admin / master tokens.
		$result = $this->client->get_bucket_count();

		if ( ! is_wp_error( $result ) ) {
			$data = $result;

			return $this->rest_ok( [
				'status'  => 'ok',
				'message' => __( 'Connection successful.', 'sugarcommerce' ),
				'summary' => sprintf(
					/* translators: %1$d: count */
					_n( 'Found %d accessible bucket', 'Found %d accessible buckets', $data['count'], 'sugarcommerce' ),
					$data['count']
				),
				'buckets' => $data['buckets'] ?? [],
				'count'   => $data['count'],
			] );
		}

		// A token scoped to specific buckets cannot list them. That is not a
		// failure — Cloudflare recommends scoping R2 tokens — so it must not be
		// reported as one. The only thing missing is the bucket name, which the
		// consumer supplies through this filter.
		// Nothing answered, so nothing has been learned about the credentials.
		// Falling through would try the bucket next, fail the same way, and
		// report a reachability problem as a problem with the bucket.
		if ( Transport::is_transport_error( (string) $result->get_error_code() ) ) {
			return $this->rest_ok( [
				'status'  => 'failed',
				'message' => __( 'Could not reach the storage provider.', 'sugarcommerce' ),
				'summary' => $result->get_error_message(),
			] );
		}

		$scoped          = 'bucket_listing_forbidden' === $result->get_error_code();
		$fallback_bucket = (string) apply_filters( Runtime::hook( 's3_connection_test_fallback_bucket' ), '' );

		if ( '' !== $fallback_bucket ) {
			$exists = $this->client->bucket_exists( $fallback_bucket, false );

			if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
				return $this->rest_ok( [
					'status'  => 'ok',
					'message' => __( 'Connection successful.', 'sugarcommerce' ),
					'summary' => sprintf(
						/* translators: %s: bucket name */
						__( 'Verified access to "%s". This token is scoped to specific buckets, so it cannot list the others.', 'sugarcommerce' ),
						$fallback_bucket
					),
					'buckets' => [ $fallback_bucket ],
					'count'   => 1,
					'scoped'  => true,
				] );
			}

			// The name is wrong, or the token cannot reach it. Say which
			// bucket was tried — otherwise the admin has no way to tell a typo
			// from a permissions problem.
			return $this->rest_ok( [
				'status'  => 'failed',
				'message' => __( 'Could not reach that bucket.', 'sugarcommerce' ),
				'summary' => sprintf(
					/* translators: 1: bucket name, 2: error from the provider */
					__( 'The credentials work, but "%1$s" could not be reached: %2$s', 'sugarcommerce' ),
					$fallback_bucket,
					is_wp_error( $exists ) ? $exists->get_error_message() : __( 'The bucket does not exist.', 'sugarcommerce' )
				),
				'scoped'  => $scoped,
			] );
		}

		if ( $scoped ) {
			// Credentials are fine; the configuration is simply incomplete.
			return $this->rest_ok( [
				'status'  => 'needs_bucket',
				'message' => __( 'Credentials accepted.', 'sugarcommerce' ),
				'summary' => __( 'This token is scoped to specific buckets, so it cannot list them — which is the recommended setup. Enter the bucket name to finish configuring and verify access.', 'sugarcommerce' ),
				'scoped'  => true,
			] );
		}

		// Anything else really is a credential or endpoint problem, and the
		// provider's own message is more useful than a generic one.
		return $this->rest_ok( [
			'status'  => 'failed',
			'message' => __( 'Connection failed.', 'sugarcommerce' ),
			'summary' => $result->get_error_message(),
		] );
	}

	/**
	 * Start a large upload, or find the one this file already began
	 *
	 * The same file dropped again -- after a reload, a closed tab, a lost
	 * connection -- has the same key, size and fingerprint, so its upload is
	 * picked up where storage says it stopped rather than started over.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_start_upload( WP_REST_Request $request ) {
		$bucket    = (string) $request['bucket'];
		$key       = (string) $request['key'];
		$size      = (int) $request['size'];
		$owner     = get_current_user_id();
		$multipart = $this->multipart( $bucket );
		$upload    = $multipart->match( $key, $size, (string) $request['fingerprint'], $owner );
		$resumed   = null !== $upload;

		if ( null !== $upload ) {
			$upload = $multipart->resume( $upload );

			// Storage no longer has it: start again.
			if ( is_wp_error( $upload ) ) {
				$upload  = null;
				$resumed = false;
			}
		}

		if ( null === $upload ) {
			$upload = $multipart->begin( $key, $size, [
				'mime_type'   => (string) $request['mime_type'],
				'fingerprint' => (string) $request['fingerprint'],
				'owner'       => $owner,
			] );
		}

		if ( is_wp_error( $upload ) ) {
			return $this->upload_error( $upload );
		}

		return $this->rest_ok( $this->upload_payload( $upload, $resumed ) + [ 'checksums' => $this->checksums_allowed( $bucket ) ], $resumed ? 200 : 201 );
	}

	/**
	 * Whether the bucket lets this site's browser send a part's Content-MD5
	 *
	 * Asked the way the browser asks, by a preflight, and kept for an hour.
	 * A bucket that refuses the header refuses the part outright, which
	 * looks like a dropped connection to the script; so the script is told
	 * up front and leaves the header off. A bucket that allows no uploads
	 * from here at all fails the upload either way, so that is a yes.
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return bool
	 */
	private function checksums_allowed( string $bucket ): bool {
		$origin = Origin::current();

		if ( '' === $origin ) {
			return true;
		}

		$cache  = $this->client->cache();
		$key    = $cache->key( 'upload_checksums', [ 'origin' => $origin ], $bucket );
		$cached = $cache->get( $key );

		if ( is_array( $cached ) ) {
			return (bool) $cached['allowed'];
		}

		$url     = $this->client->signer()->presign_put( $bucket, Probe::PREFIX . 'preflight.txt', 300 );
		$allowed = Probe::preflight( $url, $origin, 'content-type,content-md5' ) || ! Probe::preflight( $url, $origin, 'content-type' );

		$cache->set( $key, [ 'allowed' => $allowed ], HOUR_IN_SECONDS );

		return $allowed;
	}

	/**
	 * Where a large upload stands, by storage's own list of parts
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_upload_status( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$upload = $this->multipart( (string) $request['bucket'] )->resume( $upload );

		return is_wp_error( $upload ) ? $this->upload_error( $upload ) : $this->rest_ok( $this->upload_payload( $upload, true ) );
	}

	/**
	 * Presigned URLs for some of an upload's parts
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_sign_upload_parts( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$numbers = array_slice( array_map( 'intval', (array) $request['parts'] ), 0, self::PARTS_PER_SIGNING );
		$urls    = $this->multipart( (string) $request['bucket'] )->sign( $upload, $numbers, self::PART_URL_SECONDS );

		return $this->rest_ok( [
			'id'         => $upload->id,
			// An object, not a list: the keys are part numbers.
			'urls'       => (object) $urls,
			'expires_in' => self::PART_URL_SECONDS,
		] );
	}

	/**
	 * Put a large upload together once every part is in storage
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_complete_upload( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$bucket  = (string) $request['bucket'];
		$address = $this->multipart( $bucket )->finish( $upload );

		if ( is_wp_error( $address ) ) {
			return $this->upload_error( $address );
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'id'      => $upload->id,
			'bucket'  => $bucket,
			'key'     => $upload->key,
			'address' => $address,
			'size'    => $upload->size,
			'message' => __( 'Upload complete.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Give a large upload up, freeing its parts in storage
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_abort_upload( WP_REST_Request $request ) {
		$upload = $this->owned_upload( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		if ( ! $this->multipart( (string) $request['bucket'] )->abort( $upload ) ) {
			return $this->rest_fail( 'rest_upload_abort_failed', __( 'Storage would not cancel the upload. It will be cleared away automatically within a week.', 'sugarcommerce' ), 502 );
		}

		return $this->rest_ok( [
			'id'      => $upload->id,
			'aborted' => true,
		] );
	}

	/**
	 * Uploads to one bucket
	 *
	 * @param string $bucket Bucket name.
	 *
	 * @return Multipart
	 */
	private function multipart( string $bucket ): Multipart {
		return new Multipart( $this->client->bucket( $bucket ) );
	}

	/**
	 * The upload a request names, if it is the current user's
	 *
	 * An upload id is not a secret anybody else should be able to spend: it
	 * is the current user's, in the bucket the route names, or nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return Upload|WP_Error
	 */
	private function owned_upload( WP_REST_Request $request ): Upload|WP_Error {
		$upload = $this->multipart( (string) $request['bucket'] )->find( (string) $request['id'] );

		if ( null === $upload ) {
			return $this->rest_fail( 'rest_upload_not_found', __( 'That upload is finished, cancelled or was never started.', 'sugarcommerce' ), 404 );
		}

		if ( $upload->owner !== get_current_user_id() ) {
			return $this->rest_fail( 'rest_upload_forbidden', __( 'That upload was started by somebody else.', 'sugarcommerce' ), rest_authorization_required_code() );
		}

		return $upload;
	}

	/**
	 * What the script needs to send an upload's parts
	 *
	 * @param Upload $upload  The upload.
	 * @param bool   $resumed Whether it was begun before.
	 *
	 * @return array<string, mixed>
	 */
	private function upload_payload( Upload $upload, bool $resumed ): array {
		return [
			'id'         => $upload->id,
			'key'        => $upload->key,
			'size'       => $upload->size,
			'part_size'  => $upload->part_size,
			'part_count' => $upload->part_count(),
			'done'       => array_map( 'intval', array_keys( $upload->parts ) ),
			'progress'   => $upload->progress(),
			'resumed'    => $resumed,
		];
	}

	/**
	 * An upload failure, with a status a REST response can carry
	 *
	 * The upload's own errors keep theirs; storage's refusals become a 502
	 * with storage's words, since storage refusing is not this site
	 * refusing the browser.
	 *
	 * @param WP_Error $error The failure.
	 *
	 * @return WP_Error
	 */
	private function upload_error( WP_Error $error ): WP_Error {
		$data = (array) $error->get_error_data();

		if ( str_starts_with( (string) $error->get_error_code(), 's3_upload_' ) && isset( $data['status'] ) ) {
			return $error;
		}

		$reason = (string) ( $data['reason'] ?? '' );

		return new WP_Error(
			(string) $error->get_error_code(),
			'' === $reason
				? $error->get_error_message()
				/* translators: %s: storage's own words for why it refused. */
				: sprintf( __( 'Storage refused: %s', 'sugarcommerce' ), $reason ),
			[
				'status'     => 502,
				'error_code' => (string) ( $data['error_code'] ?? '' ),
			]
		);
	}

	/**
	 * Clear all cached S3 data
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_clear_cache() {
		if ( ! $this->client->cache()->flush() ) {
			return $this->rest_fail( 'rest_cache_clear_failed', __( 'Failed to clear cache', 'sugarcommerce' ), 500 );
		}

		return $this->rest_ok( [ 'message' => __( 'Cache cleared successfully', 'sugarcommerce' ) ] );
	}

	/**
	 * Get details for a single bucket
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_bucket_details( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$data = $this->client->get_bucket_details( $bucket, Origin::current(), false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// The debug payload carries raw provider error strings; it is a
		// development aid, not something to ship to every browser.
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			unset( $data['debug'] );
		}

		return $this->rest_ok( $data );
	}

	/**
	 * List a page of objects
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_objects( WP_REST_Request $request ) {
		$page = Objects::get_page_data(
			$this->client,
			$this->provider_id,
			(string) $request['bucket'],
			(string) $request['prefix'],
			(string) $request['continuation_token']
		);

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return $this->rest_ok( $page );
	}

	/**
	 * The buckets the picker may open
	 *
	 * The ones this browser is confined to when it is; otherwise every bucket
	 * the key can list.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_buckets() {
		$names = array_values( array_unique( array_filter( array_map( 'strval', ( $this->allowed_buckets )() ) ) ) );

		if ( ! $names ) {
			$listed = $this->client->get_buckets();

			if ( is_wp_error( $listed ) ) {
				return $listed;
			}

			$names = array_map( static fn( array $bucket ): string => (string) $bucket['name'], $listed['buckets'] );
		}

		return $this->rest_ok( [
			'buckets' => array_map( static fn( string $name ): array => [ 'name' => $name ], $names ),
			'default' => $this->default_bucket,
		] );
	}

	/**
	 * A folder, or a search beneath it, for the picker
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_browse( WP_REST_Request $request ) {
		$page = Listing::browse(
			$this->client,
			(string) $request['bucket'],
			(string) $request['prefix'],
			(string) $request['search'],
			(string) $request['continuation_token']
		);

		return is_wp_error( $page ) ? $page : $this->rest_ok( $page );
	}

	/**
	 * Delete a single object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_object( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$result = $this->client->delete_object( $bucket, $key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'message' => __( 'File deleted successfully', 'sugarcommerce' ),
			'bucket'  => $bucket,
			'key'     => $key,
		] );
	}

	/**
	 * Rename an object
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_rename_object( WP_REST_Request $request ) {
		$bucket       = (string) $request['bucket'];
		$current_key  = (string) $request['current_key'];
		$new_filename = (string) $request['new_filename'];

		$problem = Filename::problem( $new_filename );
		if ( '' !== $problem ) {
			return $this->rest_fail( 'rest_invalid_filename', $problem );
		}

		if ( Directory::is_rename_same_key( $current_key, $new_filename ) ) {
			return $this->rest_fail(
				'rest_rename_noop',
				__( 'The new filename is the same as the current filename', 'sugarcommerce' )
			);
		}

		$new_key = Directory::build_rename_key( $current_key, $new_filename );

		$exists = $this->client->object_exists( $bucket, $new_key, false );
		if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
			return $this->rest_fail(
				'rest_rename_conflict',
				/* translators: %s: new filename */
				sprintf( __( 'A file named "%s" already exists in this location', 'sugarcommerce' ), $new_filename ),
				409
			);
		}

		$result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		/**
		 * Fires after an object has been renamed.
		 *
		 * A key stored elsewhere does not follow the object, so anything
		 * holding one has to be told. Runs only after the rename succeeded.
		 *
		 * @param string $bucket  Bucket name.
		 * @param string $old_key Key the object had.
		 * @param string $new_key Key the object now has.
		 */
		do_action( Runtime::hook( 's3_object_renamed' ), $bucket, $current_key, $new_key );

		return $this->rest_ok( [
			/* translators: %s: new filename */
			'message'      => sprintf( __( 'File renamed to "%s" successfully', 'sugarcommerce' ), $new_filename ),
			'bucket'       => $bucket,
			'old_key'      => $current_key,
			'new_key'      => $new_key,
			'new_filename' => $new_filename,
		] );
	}

	/**
	 * Move an object into another folder
	 *
	 * A move is a rename that keeps the filename and changes the folder, so it
	 * goes through the same copy-then-delete, the same collision check, and
	 * fires the same renamed action -- anything holding the old key has to
	 * follow it here exactly as it does for a rename.
	 *
	 * One object at a time, deliberately. A batch of these is a batch of
	 * non-atomic copy-and-delete pairs, and a failure halfway leaves the admin
	 * working out which of them moved.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_move_object( WP_REST_Request $request ) {
		$bucket        = (string) $request['bucket'];
		$current_key   = (string) $request['key'];
		$target_prefix = (string) $request['target_prefix'];

		$new_key = Directory::build_move_key( $current_key, $target_prefix );

		if ( $new_key === $current_key ) {
			return $this->rest_fail(
				'rest_move_noop',
				__( 'The file is already in that folder', 'sugarcommerce' )
			);
		}

		$exists = $this->client->object_exists( $bucket, $new_key, false );

		if ( ! is_wp_error( $exists ) && $exists['exists'] ) {
			return $this->rest_fail(
				'rest_move_conflict',
				sprintf(
					/* translators: %s: name of the destination folder */
					__( 'A file with this name already exists in "%s"', 'sugarcommerce' ),
					$target_prefix ?: __( 'the bucket root', 'sugarcommerce' )
				),
				409
			);
		}

		$result = $this->client->rename_object( $bucket, $current_key, $new_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		/** This action is documented in rest_rename_object(). */
		do_action( Runtime::hook( 's3_object_renamed' ), $bucket, $current_key, $new_key );

		return $this->rest_ok( [
			'message' => sprintf(
				/* translators: %s: name of the destination folder */
				__( 'File moved to "%s"', 'sugarcommerce' ),
				$target_prefix ?: __( 'the bucket root', 'sugarcommerce' )
			),
			'bucket'  => $bucket,
			'old_key' => $current_key,
			'new_key' => $new_key,
		] );
	}

	/**
	 * Report what else points at an object
	 *
	 * A key stored elsewhere -- a product's download file, a post meta value --
	 * does not follow the object when it is renamed or moved. Nothing in this
	 * library knows what those places are, so it asks: a consumer answers the
	 * filter with whatever it holds, and the browser warns before renaming.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_object_references( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		/**
		 * Filter the things referring to an object.
		 *
		 * @param array  $references List of [ 'label' => string, 'edit_url' => string ].
		 * @param string $bucket     Bucket name.
		 * @param string $key        Object key.
		 */
		$references = (array) apply_filters( Runtime::hook( 's3_object_references' ), [], $bucket, $key );

		$clean = [];

		foreach ( $references as $reference ) {
			$label = trim( (string) ( $reference['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$clean[] = [
				'label'    => $label,
				'edit_url' => esc_url_raw( (string) ( $reference['edit_url'] ?? '' ) ),
			];
		}

		return $this->rest_ok( [
			'bucket'     => $bucket,
			'key'        => $key,
			'references' => $clean,
			'count'      => count( $clean ),
		] );
	}

	/**
	 * Mint a presigned download URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_download_url( WP_REST_Request $request ) {
		$bucket  = (string) $request['bucket'];
		$key     = (string) $request['key'];
		$minutes = Sanitize::minutes( (int) $request['expires_minutes'] );

		$exists = $this->client->object_exists( $bucket, $key, false );
		if ( is_wp_error( $exists ) ) {
			return $this->rest_fail( 'rest_object_check_failed', __( 'Error checking if file exists', 'sugarcommerce' ), 502 );
		}

		if ( ! $exists['exists'] ) {
			return $this->rest_fail( 'rest_object_not_found', __( 'File does not exist', 'sugarcommerce' ), 404 );
		}

		$url = $this->client->get_presigned_url( $bucket, $key, $minutes );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return $this->rest_ok( [
			'url'        => $url,
			'expires_at' => Timestamp::in_minutes( $minutes ),
			'expires_in' => $minutes,
			'bucket'     => $bucket,
			'key'        => $key,
			'message'    => sprintf(
				/* translators: %1$d: count */
				__( 'Link generated successfully (expires in %d minutes)', 'sugarcommerce' ),
				$minutes
			),
		] );
	}

	/**
	 * Mint a presigned upload URL
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_upload_url( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$key    = (string) $request['key'];

		$url = $this->client->get_presigned_upload_url( $bucket, $key );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'url'     => $url,
			'expires' => Timestamp::in_minutes( 15 ),
		] );
	}

	/**
	 * List the folders directly inside a prefix
	 *
	 * One level, not a tree. A delimited listing returns the immediate child
	 * prefixes and nothing deeper, so a folder picker can expand a node at a
	 * time and a bucket with a deep layout costs one request per node the
	 * admin actually opens, rather than a walk of the whole thing.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_folders( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$prefix = (string) $request['prefix'];

		$result = $this->client->get_objects( $bucket, 1000, $prefix, '/' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$folders = [];

		foreach ( $result['prefixes'] as $folder ) {
			$folders[] = [
				'name'   => Directory::name( $folder ),
				'prefix' => $folder,
			];
		}

		return $this->rest_ok( [
			'bucket'  => $bucket,
			'prefix'  => $prefix,
			'folders' => $folders,
		] );
	}

	/**
	 * Create a folder
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_create_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$prefix      = (string) $request['prefix'];
		$folder_name = (string) $request['folder_name'];

		$validation = Validate::folder_name( $folder_name );
		if ( ! $validation['valid'] ) {
			return $this->rest_fail( 'rest_invalid_folder_name', $validation['message'] );
		}

		$folder_key = Directory::build_folder_key( $prefix, $folder_name );
		$result     = $this->client->create_folder( $bucket, $folder_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			/* translators: %1$s: folder name */
			'message'    => sprintf( __( 'Folder "%s" created successfully', 'sugarcommerce' ), $folder_name ),
			'folder_key' => $folder_key,
			'bucket'     => $bucket,
			'prefix'     => $prefix,
		], 201 );
	}

	/**
	 * Delete a folder and everything under it
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_folder( WP_REST_Request $request ) {
		$bucket      = (string) $request['bucket'];
		$folder_path = (string) $request['folder_path'];

		// Refuse to recursively delete the bucket root. After normalization a
		// folder_path of '' or '/' resolves to depth 0, which would wipe the
		// entire bucket, so require at least one path segment.
		if ( Directory::depth( $folder_path ) < 1 ) {
			return $this->rest_fail(
				'rest_refuse_bucket_root',
				__( 'Refusing to delete bucket root. Specify a folder.', 'sugarcommerce' )
			);
		}

		$data = $this->client->delete_folder( $bucket, $folder_path );

		$this->client->cache()->flush_bucket( $bucket );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $this->rest_ok( [
			'message'     => $this->format_folder_deletion_message( $data ),
			'bucket'      => $bucket,
			'folder_path' => $data['folder_path'] ?? Directory::normalize( $folder_path ),
			'data'        => $data,
		] );
	}

	/**
	 * Configure CORS for browser uploads
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_setup_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$origin = (string) $request['origin'] ?: Origin::current();

		if ( '' === $origin ) {
			return $this->rest_fail( 'rest_origin_required', __( 'Origin is required for CORS setup', 'sugarcommerce' ) );
		}

		$this->client->cache()->flush_bucket( $bucket );

		$result = $this->client->set_cors_scenario( $bucket, 'upload_only', [ $origin ] );

		if ( is_wp_error( $result ) ) {
			// AccessDenied on PutBucketCors is not a transient failure and no
			// amount of retrying fixes it: R2 API tokens carry a permission
			// level, and a token with Object Read & Write can upload objects
			// while being refused any change to bucket configuration. That is
			// the recommended way to scope a token, so it is a normal state
			// rather than a misconfiguration — but it does mean CORS has to be
			// set once in the provider's console. Saying "setup failed" sends
			// people to retry something that cannot succeed.
			$denied = in_array(
				$result->get_error_code(),
				[ 'AccessDenied', 'access_denied' ],
				true
			) || 403 === ( ( (array) $result->get_error_data() )['status'] ?? 0 );

			return $this->rest_fail(
				$denied ? 'rest_cors_permission_denied' : 'rest_cors_setup_failed',
				$denied
					? __( 'This API token can read and write objects but is not permitted to change bucket settings, so CORS has to be configured once in your provider\'s console. The rule to add is below.', 'sugarcommerce' )
					: $result->get_error_message(),
				502
			);
		}

		$this->client->cache()->flush_bucket( $bucket );

		$verification = $this->client->cors_allows_upload( $bucket, $origin, false );
		$verified     = ! is_wp_error( $verification ) && $verification['allows_upload'];

		return $this->rest_ok( [
			'bucket'              => $bucket,
			'origin'              => $origin,
			'verification_passed' => $verified,
			'message'             => $verified
				? __( 'CORS configured successfully for uploads', 'sugarcommerce' )
				: __( 'CORS was saved but is not active yet — it can take a moment to propagate.', 'sugarcommerce' ),
		] );
	}

	/**
	 * Remove the bucket's CORS configuration
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_cors( WP_REST_Request $request ) {
		$bucket = (string) $request['bucket'];
		$result = $this->client->delete_cors_configuration( $bucket );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->client->cache()->flush_bucket( $bucket );

		return $this->rest_ok( [
			'bucket'  => $bucket,
			'message' => __( 'CORS configuration removed', 'sugarcommerce' ),
		] );
	}

	/**
	 * Format the folder deletion success message
	 *
	 * @param array $data Deletion result data.
	 *
	 * @return string
	 */
	private function format_folder_deletion_message( array $data ): string {
		if ( ! empty( $data['deleted_count'] ) ) {
			/* translators: %1$d: count */
			return sprintf( __( 'Folder deleted successfully (%d items removed)', 'sugarcommerce' ), $data['deleted_count'] );
		}

		if ( ! empty( $data['success_count'] ) ) {
			/* translators: %1$d: count */
			return sprintf( __( 'Folder deleted successfully (%d objects removed)', 'sugarcommerce' ), $data['success_count'] );
		}

		return __( 'Folder deleted successfully', 'sugarcommerce' );
	}
}
