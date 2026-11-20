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

use Closure;
use SugarCommerce\S3\Bucket;
use SugarCommerce\StorageBrowser\Client;
use SugarCommerce\StorageBrowser\Traits\Controller\BucketRoutes;
use SugarCommerce\StorageBrowser\Traits\Controller\ConnectionRoutes;
use SugarCommerce\StorageBrowser\Traits\Controller\CorsRoutes;
use SugarCommerce\StorageBrowser\Traits\Controller\FolderRoutes;
use SugarCommerce\StorageBrowser\Traits\Controller\ObjectRoutes;
use SugarCommerce\StorageBrowser\Traits\Controller\Permissions;
use SugarCommerce\StorageBrowser\Traits\Controller\Routes;
use SugarCommerce\StorageBrowser\Traits\Controller\UploadRoutes;
use SugarCommerce\StorageBrowser\Utils\Runtime;
use WP_Error;
use WP_REST_Response;

/**
 * Class Controller
 *
 * The routes, their permission checks and their handlers are traits in
 * Traits/Controller, one per job; the namespace and route base are here.
 */
class Controller {

	use Routes;
	use Permissions;
	use ConnectionRoutes;
	use UploadRoutes;
	use BucketRoutes;
	use ObjectRoutes;
	use FolderRoutes;
	use CorsRoutes;

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
	public const int PARTS_PER_SIGNING = 100;

	/**
	 * Seconds a part URL works for.
	 */
	private const int PART_URL_SECONDS = 3600;

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
}
