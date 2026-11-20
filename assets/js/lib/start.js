/**
 * Whether a build's browser starts on this page.
 *
 * Another plugin may bundle its own copy of this library, and its browser
 * script may be on the same page. Each copy starts once, and only where its
 * own build drew a browser: MediaLibrary marks the container with the
 * browser script's handle, `data-storage-browser="{handle}"`.
 */

/**
 * Whether to start.
 *
 * @param {Object}   entry This build's registry entry; `started` once it has.
 * @param {string}   key   This build's browser script handle.
 * @param {Document} doc   The document.
 * @return {boolean} Whether to start.
 */
export function shouldStart( entry, key, doc = document ) {
	if ( ! entry || entry.started || ! key ) {
		return false;
	}

	return Array.from( doc.querySelectorAll( '[data-storage-browser]' ) ).some(
		( element ) => element.dataset.storageBrowser === key
	);
}
