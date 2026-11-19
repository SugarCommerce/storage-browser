<?php
/**
 * Script and style registration, recorded: enough of WP_Scripts to see what
 * a page was given and in what order.
 *
 * @package SugarCommerce\StorageBrowser
 */

declare( strict_types=1 );

// phpcs:disable

$GLOBALS['wp_test_scripts'] = [];

/**
 * Forget every script and style a previous test registered.
 *
 * @return void
 */
function wp_test_reset_scripts(): void {
	$GLOBALS['wp_test_scripts'] = [];
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $args = [] ) {
		if ( isset( $GLOBALS['wp_test_scripts']['script'][ $handle ] ) ) {
			return false;
		}

		$GLOBALS['wp_test_scripts']['script'][ $handle ] = [
			'src'          => $src,
			'deps'         => $deps,
			'ver'          => $ver,
			'enqueued'     => false,
			'inline'       => [],
			'localized'    => [],
			'translations' => null,
		];

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
		if ( ! isset( $GLOBALS['wp_test_scripts']['script'][ $handle ] ) ) {
			wp_register_script( $handle, $src, $deps, $ver, $args );
		}

		$GLOBALS['wp_test_scripts']['script'][ $handle ]['enqueued'] = true;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		$script = $GLOBALS['wp_test_scripts']['script'][ $handle ] ?? null;

		return match ( $status ) {
			'registered' => null !== $script,
			'localized'  => null !== $script && [] !== $script['localized'],
			default      => null !== $script && $script['enqueued'],
		};
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		if ( ! isset( $GLOBALS['wp_test_scripts']['script'][ $handle ] ) ) {
			return false;
		}

		$GLOBALS['wp_test_scripts']['script'][ $handle ]['inline'][] = [ $position, $data ];

		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ) {
		if ( ! isset( $GLOBALS['wp_test_scripts']['script'][ $handle ] ) ) {
			return false;
		}

		$GLOBALS['wp_test_scripts']['script'][ $handle ]['localized'][ $name ] = $data;

		return true;
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
		$GLOBALS['wp_test_scripts']['script'][ $handle ]['translations'] = $domain;

		return true;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['wp_test_scripts']['style'][ $handle ] ??= [ 'src' => $src, 'enqueued' => false ];

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
		wp_register_style( $handle, $src, $deps, $ver, $media );
		$GLOBALS['wp_test_scripts']['style'][ $handle ]['enqueued'] = true;
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		$style = $GLOBALS['wp_test_scripts']['style'][ $handle ] ?? null;

		return 'registered' === $status ? null !== $style : ( null !== $style && $style['enqueued'] );
	}
}
