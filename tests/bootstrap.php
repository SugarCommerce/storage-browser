<?php
/**
 * PHPUnit bootstrap.
 *
 * Enough of WordPress, stubbed, for the whole package to run without one: an
 * option and transient store, hooks that record and run, the HTTP API answered
 * by {@see \SugarCommerce\StorageBrowser\Tests\Support\FakeHttp}, core's own MIME
 * table, uploads under the system temporary directory and a $wpdb that
 * understands LIKE.
 *
 * One store for every area, reset by wp_test_reset() in each test's setUp().
 * An area that needs a stub of its own adds a file to tests/stubs/, loaded
 * after this one, with every function guarded by function_exists().
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

// phpcs:disable

/*
 * A throwaway WordPress root.
 *
 * The bucket browser's list tables require
 * wp-admin/includes/class-wp-list-table.php at file scope, so the classes
 * cannot be loaded -- and their traits cannot be linked -- without that file
 * existing.
 */
if ( ! defined( 'ABSPATH' ) ) {
	$wp_stub_root = sys_get_temp_dir() . '/sugarcommerce-storage-browser-tests/site/';

	if ( ! is_dir( $wp_stub_root . 'wp-admin/includes' ) ) {
		mkdir( $wp_stub_root . 'wp-admin/includes', 0777, true );
	}

	file_put_contents(
		$wp_stub_root . 'wp-admin/includes/class-wp-list-table.php',
		"<?php\nif ( ! class_exists( 'WP_List_Table' ) ) {\n"
		. "\tclass WP_List_Table {\n"
		. "\t\tpublic \$items = [];\n"
		. "\t\tprotected \$_pagination_args = [];\n"
		. "\t\tprotected \$_column_headers = [];\n"
		. "\t\tpublic \$screen;\n"
		. "\t\tpublic function __construct( \$args = [] ) { \$this->screen = convert_to_screen( \$args['screen'] ?? '' ); }\n"
		. "\t\tpublic function set_pagination_args( \$args ) { \$this->_pagination_args = \$args; }\n"
		. "\t\tpublic function row_actions( \$actions, \$always_visible = false ) { return ''; }\n"
		. "\t\tpublic function get_pagenum() { return 1; }\n"
		. "\t\tpublic function display() {}\n"
		. "\t\tpublic function get_columns() { return []; }\n"
		. "\t\tpublic function prepare_items() {}\n"
		. "\t}\n}\n"
	);

	// As core: the constructor above asks convert_to_screen(), which is in
	// template.php -- an admin include a REST request does not load.
	file_put_contents(
		$wp_stub_root . 'wp-admin/includes/template.php',
		"<?php\nfunction convert_to_screen( \$hook_name ) { return (object) [ 'id' => (string) \$hook_name ]; }\n"
	);
	file_put_contents( $wp_stub_root . 'wp-admin/includes/class-wp-screen.php', "<?php\n" );
	file_put_contents( $wp_stub_root . 'wp-admin/includes/screen.php', "<?php\n" );

	define( 'ABSPATH', $wp_stub_root );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

/*
 * This checkout, as a plugin symlinked into the stub site. That is how
 * SugarCommerce\Plugin\Assets -- which finds the package's icons, styles and
 * scripts -- maps a folder outside wp-content back to a URL, and without a URL
 * it finds nothing.
 */
$GLOBALS['wp_plugin_paths'][ WP_CONTENT_DIR . '/plugins/storage-browser' ] = dirname( __DIR__ );

foreach ( [ 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'KB_IN_BYTES' => 1024, 'MB_IN_BYTES' => 1048576, 'GB_IN_BYTES' => 1073741824 ] as $constant => $value ) {
	if ( ! defined( $constant ) ) {
		define( $constant, $value );
	}
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function wp_test_reset(): void {
	$GLOBALS['wp_test_options']           = [];
	$GLOBALS['wp_test_meta']              = [];
	$GLOBALS['wp_test_hooks']             = [];
	$GLOBALS['wp_test_filters']           = [];
	$GLOBALS['wp_test_apache_modules']    = [];
	$GLOBALS['wp_test_did_action']        = [];
	$GLOBALS['wp_test_uploads']           = sys_get_temp_dir() . '/sugarcommerce-storage-browser-tests/uploads';
	$GLOBALS['wp_test_post_types']        = [];
	$GLOBALS['wp_test_current_post_type'] = false;
	$GLOBALS['wp_test_screen']            = null;
	$GLOBALS['wp_test_is_admin']          = true;
	$GLOBALS['wp_test_is_ssl']            = false;
	$GLOBALS['wp_test_home_url']          = 'https://example.test';
	$GLOBALS['test_user_can']             = true;
	$GLOBALS['test_user_id']              = 1;
	$GLOBALS['wp_test_cron']              = [];
	$GLOBALS['registered_rest_routes']    = [];
	$GLOBALS['doing_it_wrong']            = [];
	$GLOBALS['wpdb']                      = new Test_WPDB();

	$_SERVER  = array_diff_key( $_SERVER, [ 'HTTP_RANGE' => 1, 'HTTP_ORIGIN' => 1 ] );
	$_REQUEST = [];
	$_GET     = [];
	$_POST    = [];

	if ( class_exists( \SugarCommerce\StorageBrowser\Tests\Support\FakeHttp::class ) ) {
		\SugarCommerce\StorageBrowser\Tests\Support\FakeHttp::reset();
	}
}

/**
 * A $wpdb that answers the prefix queries Cleanup makes.
 */
class Test_WPDB {
	public string $options  = 'wp_options';
	public string $postmeta = 'wp_postmeta';
	public string $prefix   = 'wp_';

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		// The backslashes esc_like() added are kept, so the LIKE can tell an
		// escaped underscore from a wildcard one.
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', "'" . str_replace( '\\', '\\\\', (string) $arg ) . "'", (string) $query, 1 );
		}

		return $query;
	}

	public function get_col( $query ) {
		if ( ! preg_match( "/LIKE '([^']*)'/", (string) $query, $m ) ) {
			return [];
		}

		$source = str_contains( (string) $query, 'meta_key' ) ? $GLOBALS['wp_test_meta'] : $GLOBALS['wp_test_options'];
		$found  = [];

		foreach ( array_keys( $source ) as $name ) {
			if ( self::like( (string) $name, $m[1] ) ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * SQL LIKE, including the part that matters.
	 *
	 * `%` is any run of characters and `_` is any single one. Modelling the
	 * underscore is the whole point: without it a stub cannot tell an escaped
	 * prefix from an unescaped one, and the test that exists to catch
	 * "myplugin_" also matching "mypluginX" passes either way.
	 */
	private static function like( string $subject, string $pattern ): bool {
		$regex = '';

		for ( $i = 0; $i < strlen( $pattern ); $i++ ) {
			$char = $pattern[ $i ];

			if ( '\\' === $char && isset( $pattern[ $i + 1 ] ) ) {
				$regex .= preg_quote( $pattern[ ++$i ], '#' );
				continue;
			}

			$regex .= match ( $char ) {
				'%'     => '.*',
				'_'     => '.',
				default => preg_quote( $char, '#' ),
			};
		}

		return 1 === preg_match( '#^' . $regex . '$#', $subject );
	}
}

/* ─── Translation and escaping ─────────────────────────────────────── */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		// A test can hand in translations, by domain, to see a string is translated whole.
		return (string) ( $GLOBALS['storage_test_translations'][ $domain ][ $text ] ?? $text );
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return (string) $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? (string) $single : (string) $plural;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ): void {
		echo $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return addcslashes( (string) $text, "'\"\\\n\r" );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ): void {
		echo esc_attr( $text );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return (string) $text;
	}
}

/* ─── Sanitising ───────────────────────────────────────────────────── */

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Core's, near enough: separators and anything odd removed, whitespace
	 * to hyphens, dots and hyphens trimmed from the ends.
	 */
	function sanitize_file_name( $filename ) {
		$filename = str_replace( [ "\0", '/', '\\' ], '', (string) $filename );
		$filename = preg_replace( '/[^A-Za-z0-9 _.-]/', '', $filename ) ?? '';

		return trim( preg_replace( '/[\s-]+/', '-', $filename ) ?? '', '.-' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

/* ─── Paths and URLs ───────────────────────────────────────────────── */

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path ) ?? $path;

		return str_starts_with( $path, '//' ) ? '/' . ltrim( $path, '/' ) : $path;
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return rtrim( (string) $GLOBALS['wp_test_home_url'], '/' ) . ( '' === (string) $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return home_url( $path );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return home_url( 'wp-admin/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return home_url( 'wp-json/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return home_url( 'wp-content/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return content_url( 'plugins/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
		return [
			'basedir' => $GLOBALS['wp_test_uploads'],
			'baseurl' => content_url( 'uploads' ),
			'path'    => $GLOBALS['wp_test_uploads'],
			'url'     => content_url( 'uploads' ),
			'subdir'  => '',
			'error'   => false,
		];
	}
}

if ( ! function_exists( 'apache_mod_loaded' ) ) {
	/**
	 * Core's, with the module list a test sets in place of Apache's.
	 */
	function apache_mod_loaded( $mod, $default_value = false ) {
		$software = (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' );

		if ( ! str_contains( $software, 'Apache' ) && ! str_contains( $software, 'LiteSpeed' ) ) {
			return false;
		}

		return in_array( $mod, (array) ( $GLOBALS['wp_test_apache_modules'] ?? [] ), true ) ? true : $default_value;
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $path ) {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $path ) {
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '', $dir = '' ) {
		return (string) tempnam( '' !== $dir ? $dir : sys_get_temp_dir(), 'wpt' );
	}
}

if ( ! function_exists( 'get_temp_dir' ) ) {
	function get_temp_dir() {
		return trailingslashit( sys_get_temp_dir() );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, (int) $component );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = $args[1] ?? '';
		} else {
			$params = [ $args[0] => $args[1] ];
			$url    = $args[2] ?? '';
		}

		$url   = (string) $url;
		$parts = explode( '?', $url, 2 );
		$query = [];

		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}

		$query = array_filter( array_merge( $query, $params ), static fn( $value ) => false !== $value && null !== $value );

		return $parts[0] . ( [] === $query ? '' : '?' . http_build_query( $query ) );
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( $keys, $url = '' ) {
		$parts = explode( '?', (string) $url, 2 );
		$query = [];

		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
		}

		foreach ( (array) $keys as $key ) {
			unset( $query[ $key ] );
		}

		return $parts[0] . ( [] === $query ? '' : '?' . http_build_query( $query ) );
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return (bool) $GLOBALS['wp_test_is_ssl'];
	}
}

/* ─── Options, transients and meta ─────────────────────────────────── */

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['wp_test_options'] ) ? $GLOBALS['wp_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['wp_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
		if ( array_key_exists( $name, $GLOBALS['wp_test_options'] ) ) {
			return false;
		}

		$GLOBALS['wp_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['wp_test_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['wp_test_options'][ $name ] );

		return true;
	}
}

/*
 * Transients are stored as core stores them without an object cache: an
 * option called `_transient_{key}`, so Cleanup's LIKE query can find them.
 */
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['wp_test_options'][ '_transient_' . $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['wp_test_options'][ '_transient_' . $key ] = $value;

		if ( $ttl > 0 ) {
			$GLOBALS['wp_test_options'][ '_transient_timeout_' . $key ] = time() + (int) $ttl;
		}

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		if ( ! array_key_exists( '_transient_' . $key, $GLOBALS['wp_test_options'] ) ) {
			return false;
		}

		unset( $GLOBALS['wp_test_options'][ '_transient_' . $key ], $GLOBALS['wp_test_options'][ '_transient_timeout_' . $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache( $using = null ) {
		return false;
	}
}

if ( ! function_exists( '_get_meta_table' ) ) {
	function _get_meta_table( $type ) {
		// Core's shape: $wpdb->{$type}meta, or false when there is no such
		// property -- the real guard against an arbitrary type reaching an
		// interpolated table name.
		return in_array( $type, [ 'post', 'user', 'term', 'comment' ], true ) ? 'wp_' . $type . 'meta' : false;
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	function delete_metadata( $type, $id, $key, $value = '', $all = false ) {
		if ( ! array_key_exists( $key, $GLOBALS['wp_test_meta'] ) ) {
			return false;
		}

		unset( $GLOBALS['wp_test_meta'][ $key ] );

		return true;
	}
}

/* ─── Hooks ────────────────────────────────────────────────────────── */

/*
 * Registration is recorded in wp_test_hooks, and apply_filters() runs what
 * was registered, in order, and then the single callable a test may have put
 * in wp_test_filters[ $hook ].
 */
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_test_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		foreach ( $GLOBALS['wp_test_hooks'][ $hook ] ?? [] as $index => $registered ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['wp_test_hooks'][ $hook ][ $index ] );

				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		return remove_filter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		return ! empty( $GLOBALS['wp_test_hooks'][ $hook ] ) || isset( $GLOBALS['wp_test_filters'][ $hook ] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['wp_test_hooks'][ $hook ] ?? [] as $callback ) {
			if ( is_callable( $callback ) ) {
				$value = $callback( $value, ...$args );
			}
		}

		$single = $GLOBALS['wp_test_filters'][ $hook ] ?? null;

		return is_callable( $single ) ? $single( $value, ...$args ) : $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ): void {
		$GLOBALS['wp_test_did_action'][ $hook ] = ( $GLOBALS['wp_test_did_action'][ $hook ] ?? 0 ) + 1;
		$GLOBALS['wp_test_actions'][]           = [ $hook, $args ];

		foreach ( $GLOBALS['wp_test_hooks'][ $hook ] ?? [] as $callback ) {
			if ( is_callable( $callback ) ) {
				$callback( ...$args );
			}
		}
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return (int) ( $GLOBALS['wp_test_did_action'][ $hook ] ?? 0 );
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = null ) {
		return false;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function, $message, $version ): void {
		$GLOBALS['doing_it_wrong'][] = $function;
	}
}

/* ─── Users, screens, posts ────────────────────────────────────────── */

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		$can = $GLOBALS['test_user_can'] ?? true;

		// A list is the capabilities this user holds; a bool, all or none.
		return is_array( $can ) ? in_array( $capability, $can, true ) : (bool) $can;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return (bool) $GLOBALS['wp_test_is_admin'];
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return $GLOBALS['wp_test_screen'];
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		if ( is_int( $post ) || ( is_string( $post ) && ctype_digit( $post ) ) ) {
			return $GLOBALS['wp_test_post_types'][ (int) $post ] ?? false;
		}

		return $GLOBALS['wp_test_current_post_type'];
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '7.1' : 'Test Site';
	}
}

/* ─── Time and numbers ─────────────────────────────────────────────── */

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'timestamp', $gmt = 0 ) {
		if ( 'timestamp' === $type || 'U' === $type ) {
			return time();
		}

		return gmdate( 'mysql' === $type ? 'Y-m-d H:i:s' : (string) $type );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		return gmdate( (string) $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = false, $gmt = false ) {
		return gmdate( (string) $format, $timestamp ?: time() );
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) {
		return '2 days';
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$bytes = (float) $bytes;
		$i     = $bytes > 0 ? (int) floor( log( $bytes, 1024 ) ) : 0;
		$i     = min( $i, count( $units ) - 1 );

		return round( $bytes / ( 1024 ** $i ), (int) $decimals ) . ' ' . $units[ $i ];
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$password = '';

		for ( $i = 0; $i < (int) $length; $i++ ) {
			$password .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $password;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}

/* ─── Errors ───────────────────────────────────────────────────────── */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * The parts of WP_Error this package uses.
	 */
	class WP_Error {
		public array $errors     = [];
		public array $error_data = [];

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->add( $code, $message, $data );
		}

		public function add( $code, $message, $data = '' ): void {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data && null !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );

			return $codes[0] ?? '';
		}

		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		public function get_error_message( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return (string) ( $this->errors[ $code ][0] ?? '' );
		}

		public function get_error_messages( $code = '' ): array {
			if ( '' === $code ) {
				return array_merge( ...array_values( $this->errors ?: [ [] ] ) );
			}

			return $this->errors[ $code ] ?? [];
		}

		public function get_error_data( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors(): bool {
			return [] !== $this->errors;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/* ─── HTTP, through FakeHttp ───────────────────────────────────────── */

require_once __DIR__ . '/Support/FakeHttp.php';

use SugarCommerce\StorageBrowser\Tests\Support\FakeHttp;

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = [] ) {
		return FakeHttp::respond( (string) $url, (array) $args );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		return FakeHttp::respond( (string) $url, (array) $args + [ 'method' => 'GET' ] );
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( $url, $args = [] ) {
		return FakeHttp::respond( (string) $url, (array) $args + [ 'method' => 'GET' ] );
	}
}

if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( $url, $args = [] ) {
		return FakeHttp::respond( (string) $url, (array) $args + [ 'method' => 'HEAD' ] );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		return FakeHttp::respond( (string) $url, (array) $args + [ 'method' => 'POST' ] );
	}
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args = [] ) {
		return FakeHttp::respond( (string) $url, (array) $args + [ 'method' => 'POST' ] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_message' ) ) {
	function wp_remote_retrieve_response_message( $response ) {
		return is_array( $response ) ? (string) ( $response['response']['message'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( $response ) {
		return is_array( $response ) ? ( $response['headers'] ?? [] ) : [];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		if ( ! is_array( $response ) ) {
			return '';
		}

		foreach ( $response['headers'] ?? [] as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, (string) $header ) ) {
				return $value;
			}
		}

		return '';
	}
}

/* ─── MIME types: core's own table ─────────────────────────────────── */

if ( ! function_exists( 'wp_get_mime_types' ) ) {
	/**
	 * Core's table, dumped from a running WordPress 7.1 rather than
	 * abbreviated.
	 *
	 * The abbreviation mattered: an earlier stub knew 23 of core's 130
	 * extensions, so the test asserting that Mime's own table holds nothing
	 * core already knows was checking under a fifth of the claim.
	 */
	function wp_get_mime_types() {
		return [
			'jpg|jpeg|jpe'                 => 'image/jpeg',
			'gif'                          => 'image/gif',
			'png'                          => 'image/png',
			'bmp'                          => 'image/bmp',
			'tiff|tif'                     => 'image/tiff',
			'webp'                         => 'image/webp',
			'avif'                         => 'image/avif',
			'ico'                          => 'image/x-icon',
			'heic'                         => 'image/heic',
			'heif'                         => 'image/heif',
			'heics'                        => 'image/heic-sequence',
			'heifs'                        => 'image/heif-sequence',
			'asf|asx'                      => 'video/x-ms-asf',
			'wmv'                          => 'video/x-ms-wmv',
			'wmx'                          => 'video/x-ms-wmx',
			'wm'                           => 'video/x-ms-wm',
			'avi'                          => 'video/avi',
			'divx'                         => 'video/divx',
			'flv'                          => 'video/x-flv',
			'mov|qt'                       => 'video/quicktime',
			'mpeg|mpg|mpe'                 => 'video/mpeg',
			'mp4|m4v'                      => 'video/mp4',
			'ogv'                          => 'video/ogg',
			'webm'                         => 'video/webm',
			'mkv'                          => 'video/x-matroska',
			'3gp|3gpp'                     => 'video/3gpp',
			'3g2|3gp2'                     => 'video/3gpp2',
			'txt|asc|c|cc|h|srt'           => 'text/plain',
			'csv'                          => 'text/csv',
			'tsv'                          => 'text/tab-separated-values',
			'ics'                          => 'text/calendar',
			'rtx'                          => 'text/richtext',
			'css'                          => 'text/css',
			'htm|html'                     => 'text/html',
			'vtt'                          => 'text/vtt',
			'dfxp'                         => 'application/ttaf+xml',
			'mp3|m4a|m4b'                  => 'audio/mpeg',
			'aac'                          => 'audio/aac',
			'ra|ram'                       => 'audio/x-realaudio',
			'wav|x-wav'                    => 'audio/wav',
			'ogg|oga'                      => 'audio/ogg',
			'flac'                         => 'audio/flac',
			'mid|midi'                     => 'audio/midi',
			'wma'                          => 'audio/x-ms-wma',
			'wax'                          => 'audio/x-ms-wax',
			'mka'                          => 'audio/x-matroska',
			'rtf'                          => 'application/rtf',
			'js'                           => 'application/javascript',
			'pdf'                          => 'application/pdf',
			'swf'                          => 'application/x-shockwave-flash',
			'class'                        => 'application/java',
			'tar'                          => 'application/x-tar',
			'zip'                          => 'application/zip',
			'gz|gzip'                      => 'application/x-gzip',
			'rar'                          => 'application/rar',
			'7z'                           => 'application/x-7z-compressed',
			'exe'                          => 'application/x-msdownload',
			'psd'                          => 'application/octet-stream',
			'xcf'                          => 'application/octet-stream',
			'doc'                          => 'application/msword',
			'pot|pps|ppt'                  => 'application/vnd.ms-powerpoint',
			'wri'                          => 'application/vnd.ms-write',
			'xla|xls|xlt|xlw'              => 'application/vnd.ms-excel',
			'mdb'                          => 'application/vnd.ms-access',
			'mpp'                          => 'application/vnd.ms-project',
			'docx'                         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'docm'                         => 'application/vnd.ms-word.document.macroEnabled.12',
			'dotx'                         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
			'dotm'                         => 'application/vnd.ms-word.template.macroEnabled.12',
			'xlsx'                         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xlsm'                         => 'application/vnd.ms-excel.sheet.macroEnabled.12',
			'xlsb'                         => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
			'xltx'                         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
			'xltm'                         => 'application/vnd.ms-excel.template.macroEnabled.12',
			'xlam'                         => 'application/vnd.ms-excel.addin.macroEnabled.12',
			'pptx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'pptm'                         => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
			'ppsx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
			'ppsm'                         => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
			'potx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.template',
			'potm'                         => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
			'ppam'                         => 'application/vnd.ms-powerpoint.addin.macroEnabled.12',
			'sldx'                         => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
			'sldm'                         => 'application/vnd.ms-powerpoint.slide.macroEnabled.12',
			'onetoc|onetoc2|onetmp|onepkg' => 'application/onenote',
			'oxps'                         => 'application/oxps',
			'xps'                          => 'application/vnd.ms-xpsdocument',
			'odt'                          => 'application/vnd.oasis.opendocument.text',
			'odp'                          => 'application/vnd.oasis.opendocument.presentation',
			'ods'                          => 'application/vnd.oasis.opendocument.spreadsheet',
			'odg'                          => 'application/vnd.oasis.opendocument.graphics',
			'odc'                          => 'application/vnd.oasis.opendocument.chart',
			'odb'                          => 'application/vnd.oasis.opendocument.database',
			'odf'                          => 'application/vnd.oasis.opendocument.formula',
			'wp|wpd'                       => 'application/wordperfect',
			'key'                          => 'application/vnd.apple.keynote',
			'numbers'                      => 'application/vnd.apple.numbers',
			'pages'                        => 'application/vnd.apple.pages',
		];
	}
}

if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types( $user = null ) {
		return array_diff_key( wp_get_mime_types(), [ 'swf' => 1, 'exe' => 1, 'htm|html' => 1, 'js' => 1 ] );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		$mimes = $mimes ?? wp_get_mime_types();

		foreach ( $mimes as $extensions => $type ) {
			if ( preg_match( '!\.(' . $extensions . ')$!i', (string) $filename, $found ) ) {
				return [ 'ext' => strtolower( $found[1] ), 'type' => $type ];
			}
		}

		return [ 'ext' => false, 'type' => false ];
	}
}

wp_test_reset();

foreach ( glob( __DIR__ . '/stubs/*.php' ) ?: [] as $stub ) {
	require_once $stub;
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = [] ) {
		throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
	}
}

if ( ! function_exists( 'wp_iframe' ) ) {
	function wp_iframe( $callback, ...$args ) {
		$GLOBALS['wp_test_iframe'] = $callback;
	}
}
