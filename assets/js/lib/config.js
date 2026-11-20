/**
 * What PHP handed the scripts.
 *
 * The only module that reads the page's configuration. Each build of this
 * library -- the plain one, or a Strauss-prefixed copy inside another plugin
 * -- publishes into `window.SugarCommerceStorageBrowser`, keyed by its
 * script's handle (Admin\Assets, Picker\Picker). Two plugins' copies load
 * two scripts, and a shared bare global would leave whichever printed last
 * owning the REST URL, nonce and insert token for both.
 *
 * An entry holds `global` (Admin\Assets::global_config(), filtered by
 * `browser_global_config`), `browser` in the browser frame (filtered by
 * `browser_config`, with the strings `browser_translations` filters -- which
 * is why those come from PHP rather than `__()`), `pickers` for the picker,
 * and whatever the script itself keeps there.
 *
 * A script finds its own entry from the id WordPress stamps on its <script>
 * element, "{handle}-js".
 */

/**
 * The registry's name. Shared by every build; its keys are not.
 */
export const REGISTRY = 'SugarCommerceStorageBrowser';

/**
 * The handle a script's element names.
 *
 * @param {Element|null} element The <script> element, or null.
 * @return {string} The handle, or '' without one.
 */
export const handleOf = ( element ) =>
	element && element.id ? element.id.replace( /-js$/, '' ) : '';

// Read while the script runs. Once it has, document.currentScript is null.
const script = document.currentScript;

/**
 * This script's handle: its build's, and its key in the registry.
 */
export const handle = handleOf( script );

/**
 * A build's entry in the registry, made when there is none yet.
 *
 * @param {string} key A script handle; this script's by default.
 * @return {Object} The entry.
 */
export function entryFor( key = handle ) {
	const registry = ( window[ REGISTRY ] = window[ REGISTRY ] || {} );

	return ( registry[ key ] = registry[ key ] || {} );
}

/**
 * The page's configuration for this build.
 *
 * @return {Object} The entry's `global`, or an empty object.
 */
export function globalConfig() {
	return entryFor().global || {};
}

/**
 * The browser frame's own configuration.
 *
 * @return {Object} The entry's `browser`, or an empty object.
 */
export function browserConfig() {
	return entryFor().browser || {};
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
