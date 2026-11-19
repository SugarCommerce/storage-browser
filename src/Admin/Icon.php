<?php
/**
 * Browser Icons
 *
 * @package     SugarCommerce\StorageBrowser
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Admin;

use SugarCommerce\Plugin\Assets;

/**
 * Class Icon
 *
 * Every icon the browser draws, in one place, as an inline Heroicon (outline,
 * MIT, assets/icons/heroicons).
 *
 * The browser used to borrow these from a form library when one happened to
 * be installed and fall back to Dashicons when not, so how it looked depended
 * on what else the site ran. Twelve small SVGs are cheaper than that
 * uncertainty. Each carries the class `arraypress-icon`, the one other
 * libraries' icons carry, so a single stylesheet rule sizes them all.
 */
final class Icon {

	/**
	 * The class every icon carries.
	 */
	public const ICON_CLASS = 'arraypress-icon';

	/**
	 * The icons, by what they stand for, as Heroicon file names.
	 *
	 * The file-type keys are Mime::category()'s answers, so a row's icon is
	 * looked up by its category; 'file' is everything else.
	 *
	 * @var array<string, string>
	 */
	private const ICONS = [
		'bucket'   => 'circle-stack',
		'folder'   => 'folder',
		'image'    => 'photo',
		'video'    => 'film',
		'audio'    => 'musical-note',
		'document' => 'document-text',
		'archive'  => 'archive-box',
		'file'     => 'document',
		'close'    => 'x-mark',
		'upload'   => 'arrow-up-tray',
		'cancel'   => 'x-mark',
		'done'     => 'check',
		'failed'   => 'exclamation-circle',
		'search'   => 'magnifying-glass',
		'chevron'  => 'chevron-right',
	];

	/**
	 * The SVG files, once read.
	 *
	 * @var array<string, string>
	 */
	private static array $svg = [];

	/**
	 * Draw one icon.
	 *
	 * @param string $name  A key of ICONS; an unknown one is drawn as 'file'.
	 * @param string $class Extra classes for the element.
	 *
	 * @return string Decorative (`aria-hidden`) markup.
	 */
	public static function render( string $name, string $class = '' ): string {
		$file = self::ICONS[ $name ] ?? self::ICONS['file'];
		$svg  = self::svg( $file );

		if ( '' === $svg ) {
			return '';
		}

		// The files are the library's own, read from disk, so the only thing
		// added is the opening tag's attributes -- 20 by 20, a Dashicon's own
		// box, as attributes so a stylesheet still overrides them.
		$attributes = sprintf(
			'<svg class="%s" width="20" height="20" aria-hidden="true" focusable="false"',
			esc_attr( trim( self::ICON_CLASS . ' ' . $class ) )
		);

		return (string) preg_replace( '/^<svg/', $attributes, $svg, 1 );
	}

	/**
	 * The icon for a file, by its category.
	 *
	 * Carries `s3-file-icon` and `s3-file-icon--{category}`, which is what
	 * the stylesheet colours by.
	 *
	 * @param string $category Mime::category() of the file.
	 *
	 * @return string
	 */
	public static function file( string $category ): string {
		$key = in_array( $category, [ 'image', 'video', 'audio', 'document', 'archive' ], true ) ? $category : 'file';

		return self::render( $key, 's3-file-icon s3-file-icon--' . $key );
	}

	/**
	 * The icons the browser's JavaScript draws, already drawn.
	 *
	 * The scripts insert these as they are rather than building markup, so
	 * which icon is used is decided here, once.
	 *
	 * @return array<string, string>
	 */
	public static function for_scripts(): array {
		return [
			'bucket' => self::render( 'bucket', 's3-folder-icon' ),
			'folder' => self::render( 'folder', 's3-folder-icon' ),
			'close'  => self::render( 'close' ),
			'cancel' => self::render( 'cancel' ),
			'done'   => self::render( 'done' ),
			'failed' => self::render( 'failed' ),
		];
	}

	/**
	 * The icons the picker draws: its controls, and one per kind of file.
	 *
	 * @return array<string, string> Name => markup.
	 */
	public static function for_picker(): array {
		$icons = [
			'bucket'  => self::render( 'bucket' ),
			'folder'  => self::render( 'folder' ),
			'close'   => self::render( 'close' ),
			'search'  => self::render( 'search', 'sc-storage-picker__search-icon' ),
			'chevron' => self::render( 'chevron' ),
		];

		foreach ( [ 'image', 'video', 'audio', 'document', 'archive', 'file' ] as $category ) {
			$icons[ $category ] = self::render( $category );
		}

		return $icons;
	}

	/**
	 * One Heroicon's markup, trimmed.
	 *
	 * @param string $file The Heroicon's name.
	 *
	 * @return string '' when the file is missing.
	 */
	private static function svg( string $file ): string {
		if ( ! isset( self::$svg[ $file ] ) ) {
			self::$svg[ $file ] = trim( (string) Assets::contents( __FILE__, 'icons/heroicons/' . $file . '.svg' ) );
		}

		return self::$svg[ $file ];
	}
}
