/**
 * What PHP handed the scripts.
 *
 * The only module that reads the page's globals. `S3BrowserGlobalConfig` is
 * one per page (Admin\Assets::enqueue_global_config(), filtered by
 * `browser_global_config`); `s3BrowserConfig` is the browser frame's own
 * (filtered by `browser_config`), and carries the strings
 * `browser_translations` filters -- which is why those strings come from
 * PHP rather than from `__()`: a plugin may change them.
 */

/**
 * The page's shared configuration.
 *
 * @return {Object} S3BrowserGlobalConfig, or an empty object.
 */
export function globalConfig() {
	return window.S3BrowserGlobalConfig || {};
}

/**
 * The browser frame's own configuration.
 *
 * @return {Object} s3BrowserConfig, or an empty object.
 */
export function browserConfig() {
	return window.s3BrowserConfig || {};
}

/**
 * One of the browser's filterable strings.
 *
 * @param {string} group A group in Admin\Translations::all().
 * @param {string} key   A key in that group.
 * @return {string} The string, or '' when PHP did not send it.
 */
export function t( group, key ) {
	const strings = browserConfig().i18n || {};
	const value = ( strings[ group ] || {} )[ key ];

	return typeof value === 'string' ? value : '';
}

/**
 * Put values into a string's `{name}` placeholders.
 *
 * @param {string} text   The string.
 * @param {Object} values Name => value.
 * @return {string} The string with each placeholder replaced.
 */
export function fill( text, values ) {
	return Object.keys( values ).reduce(
		( out, name ) =>
			out.split( `{${ name }}` ).join( String( values[ name ] ) ),
		String( text || '' )
	);
}
