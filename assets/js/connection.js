/**
 * The Test Connection button on a settings screen.
 *
 * Lists the account's buckets, then runs the whole check against one bucket
 * and lists each step with what to change.
 */

import domReady from '@wordpress/dom-ready';

import { request } from './lib/api';
import { globalConfig, handle } from './lib/config';
import { clearBusy, html, on, setBusy } from './lib/dom';

/**
 * The connection strings PHP sent: loading, validation and ui, merged.
 *
 * @return {Object} Strings.
 */
function strings() {
	const i18n = globalConfig().i18n || {};

	return {
		...( i18n.loading || {} ),
		...( i18n.validation || {} ),
		...( i18n.ui || {} ),
	};
}

/**
 * The element a button's result goes in: `-test-result` beside its
 * `-test-connection` id, or the id with `-result` after it.
 *
 * @param {Element} button The button.
 * @return {Element|null} The result element.
 */
export function resultFor( button ) {
	const id = button.id;

	if ( ! id ) {
		return null;
	}

	for ( const candidate of [
		id.replace( '-test-connection', '-test-result' ),
		id.replace( 'test-connection', 'test-result' ),
		`${ id }-result`,
	] ) {
		const found = document.getElementById( candidate );

		if ( found && found !== button ) {
			return found;
		}
	}

	return null;
}

/**
 * Show a failure.
 *
 * @param {Element} result  The result element.
 * @param {string}  message What failed.
 * @param {string}  detail  More.
 */
function showError( result, message, detail = '' ) {
	result.classList.remove( 'loading', 'success', 'notice' );
	result.classList.add( 'error' );
	result.innerHTML = html`✗
	${ message }${ detail && html`<br /><span class="s3-test-details">${ detail }</span>` }`.markup;
}

/**
 * Run the whole check against one bucket and list each step.
 *
 * @param {Element} result The result element.
 * @param {string}  bucket The bucket.
 */
function fullCheck( result, bucket ) {
	const i18n = globalConfig().i18n || {};
	const list = document.createElement( 'div' );

	list.className = 's3-check';
	list.textContent = ( i18n.checking || 'Checking "{bucket}"…' ).replace(
		'{bucket}',
		bucket
	);
	result.append( list );

	request( 'connectionCheck', { bucket } )
		.then( ( payload ) => {
			list.innerHTML = html`
				<strong>${ payload.message || '' }</strong>
				<ul class="s3-check-list">
					${ ( payload.checks || [] ).map( ( check ) => {
						const skipped = check.fault === 'skipped';
						let state = check.ok ? 'ok' : 'failed';
						let mark = check.ok ? '✓' : '✗';

						if ( ! check.ok && skipped ) {
							state = 'skipped';
							mark = '–';
						}

						const words = check.ok ? check.note : check.message;

						return html`<li class="s3-check-${ state }">
							${ mark }
							${ check.label }${ words && html`<br /><span class="s3-test-details">${ words }</span>` }
						</li>`;
					} ) }
				</ul>
			`.markup;

			if ( payload.status === 'failed' ) {
				result.classList.remove( 'success', 'notice' );
				result.classList.add( 'error' );
			}
		} )
		.catch( ( error ) => {
			list.textContent = `✗ ${ error.message }`;
		} );
}

/**
 * Test the connection a button is for.
 *
 * @param {Element} button The Test Connection button.
 */
function test( button ) {
	const result = resultFor( button );

	if ( ! result ) {
		window.console.error(
			'S3 Connection Test: no result element for',
			button.id
		);
		return;
	}

	const s = strings();

	setBusy( button, s.testing || 'Testing…' );
	result.classList.remove( 'success', 'error', 'notice' );
	result.classList.add( 'loading' );
	result.textContent = '';

	request( 'connectionTest' )
		.then( ( data ) => {
			const state = data.status || 'ok';
			const marks = { ok: '✓', needs_bucket: 'ℹ', failed: '✗' };
			const classes = {
				ok: 'success',
				needs_bucket: 'notice',
				failed: 'error',
			};
			const buckets = data.buckets || [];

			result.classList.remove( 'loading' );
			result.classList.add( classes[ state ] || 'success' );
			result.innerHTML = html`${ marks[ state ] || marks.ok }
			${ data.message || s.connectionSuccess || 'Connection successful.' }${
				data.summary && html`<br /><strong>${ data.summary }</strong>`
			}${
				buckets.length > 0 &&
				html`<br /><span class="s3-bucket-list"
						>${ buckets.length > 5 ? 'First 5 buckets: ' : 'Buckets: ' }${ buckets
							.slice( 0, 5 )
							.join(
								', '
							) }${ buckets.length > 5 ? '...' : '' }</span
					>`
			}`.markup;

			if ( state !== 'failed' && data.check_bucket ) {
				fullCheck( result, data.check_bucket );
			}
		} )
		.catch( ( error ) => {
			if ( error.status === 0 && ! error.code ) {
				showError(
					result,
					s.networkError || 'Network error occurred',
					'Please check your internet connection'
				);
			} else {
				showError(
					result,
					error.message ||
						s.connectionFailed ||
						'Connection test failed'
				);
			}
		} )
		.finally( () => clearBusy( button ) );
}

domReady( () => {
	// Only the buttons this build drew (Utils\Admin marks them): another
	// plugin's copy may be on the page, with its own REST route and nonce.
	on( document, 'click', '.s3-test-connection', ( event, button ) => {
		if ( button.dataset.storageBrowser !== handle ) {
			return;
		}

		event.preventDefault();
		test( button );
	} );
} );
