<?php
/**
 * Every route this browser answers, with its arguments and its permission check.
 *
 * @package   SugarCommerce\StorageBrowser
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Traits\Controller;

use SugarCommerce\S3\Bucket;
use SugarCommerce\S3\Upload;
use SugarCommerce\StorageBrowser\Tables\Objects;

/**
 * Trait Routes
 *
 * Every route this browser answers, with its arguments and its permission check.
 *
 * Each bucket-scoped route is gated by rest_bucket_permission_check() --
 * or, for the bucket's own settings, rest_settings_permission_check() -- so
 * one cannot be added that skips the allow-list.
 *
 * @since 1.0.0
 */
trait Routes {

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
				'permission_callback' => [ $this, 'rest_settings_permission_check' ],
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
				'permission_callback' => [ $this, 'rest_settings_permission_check' ],
				'args'                => $bucket_arg,
			],
		] );
	}
}
