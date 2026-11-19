/**
 * The browser's dialogs: one shape, keyboard-reachable, and closed by
 * Escape, the close button or a click outside.
 *
 * A dialog is labelled by its title, takes focus when it opens, keeps Tab
 * inside itself while open, and hands focus back to whatever opened it.
 */

import { __ } from '@wordpress/i18n';

import { t } from './config';
import { element, fadeOut, html, icon, raw } from './dom';

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const open = new Map();

/**
 * Keep Tab inside a dialog.
 *
 * @param {KeyboardEvent} event  The key.
 * @param {Element}       dialog The dialog.
 */
export function trapTab( event, dialog ) {
	const focusable = Array.from( dialog.querySelectorAll( FOCUSABLE ) ).filter(
		( node ) => ! node.hidden && node.offsetParent !== null
	);

	if ( event.key !== 'Tab' || ! focusable.length ) {
		return;
	}

	const first = focusable[ 0 ];
	const last = focusable[ focusable.length - 1 ];
	const active = dialog.ownerDocument.activeElement;

	if (
		event.shiftKey &&
		( active === first || ! dialog.contains( active ) )
	) {
		event.preventDefault();
		last.focus();
	} else if ( ! event.shiftKey && active === last ) {
		event.preventDefault();
		first.focus();
	}
}

/**
 * Close a dialog.
 *
 * @param {string} id The dialog's id.
 * @return {Promise<void>} Settles once it has gone.
 */
export function closeModal( id ) {
	const entry = open.get( id );

	if ( ! entry ) {
		return Promise.resolve();
	}

	open.delete( id );
	document.removeEventListener( 'keydown', entry.onKey, true );

	if ( entry.opener && entry.opener.isConnected ) {
		entry.opener.focus();
	}

	return fadeOut( entry.overlay, 200, true );
}

/**
 * Open a dialog.
 *
 * @param {Object}        options         The dialog.
 * @param {string}        options.id      Its id; opening one with the same id replaces it.
 * @param {string}        options.title   Its title.
 * @param {*}             options.content Its body: `html` output.
 * @param {Array<Object>} options.buttons { text, action, classes, callback } each.
 * @return {Element} The dialog element (`.s3-modal`'s overlay).
 */
export function openModal( { id, title, content, buttons = [] } ) {
	document.getElementById( id )?.remove();

	const titleId = `${ id }-title`;
	const overlay = element( html`
		<div id="${ id }" class="s3-modal-overlay">
			<div
				class="s3-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby="${ titleId }"
			>
				<div class="s3-modal-header">
					<h2 id="${ titleId }">${ title }</h2>
					<button
						type="button"
						class="s3-modal-close"
						aria-label="${ __( 'Close', 'sugarcommerce' ) }"
					>
						${ icon( 'close' ).markup ? icon( 'close' ) : raw( '&times;' ) }
					</button>
				</div>
				<div class="s3-modal-body">
					<div class="s3-modal-error" role="alert" hidden></div>
					${ content }
					<div class="s3-modal-loading" hidden>
						<span class="spinner is-active"></span
						><span class="loading-text"></span>
					</div>
				</div>
				<div class="s3-modal-footer">
					${ buttons.map(
						( button ) =>
							html`<button
								type="button"
								class="button ${ button.classes || 'button-secondary' }"
								data-action="${ button.action }"
							>
								${ button.text }
							</button>`
					) }
				</div>
			</div>
		</div>
	` );

	const dialog = overlay.querySelector( '.s3-modal' );

	document.body.append( overlay );

	// Read once the dialog is in the page, whose document it now belongs
	// to, and before anything in it takes focus.
	const opener = overlay.ownerDocument.activeElement;

	overlay.addEventListener( 'click', ( event ) => {
		if (
			event.target === overlay ||
			event.target.closest( '.s3-modal-close' )
		) {
			closeModal( id );
			return;
		}

		const trigger = event.target.closest( 'button[data-action]' );
		const button =
			trigger &&
			buttons.find( ( b ) => b.action === trigger.dataset.action );

		if ( button && button.callback && ! trigger.disabled ) {
			button.callback( overlay );
		}
	} );

	const onKey = ( event ) => {
		if ( ! overlay.isConnected ) {
			return;
		}

		if ( event.key === 'Escape' ) {
			event.preventDefault();
			closeModal( id );
			return;
		}

		trapTab( event, dialog );
	};

	document.addEventListener( 'keydown', onKey, true );
	open.set( id, { overlay, onKey, opener } );

	// The first field in the body, else the footer's first button: Escape
	// and the close button are always there for leaving.
	const first =
		dialog.querySelector( '.s3-modal-body' ).querySelector( FOCUSABLE ) ||
		dialog.querySelector( '.s3-modal-footer button' );
	first?.focus();

	return overlay;
}

/**
 * Show a dialog working: a spinner and a line, its buttons off.
 *
 * @param {string}  id      The dialog.
 * @param {boolean} loading Whether it is working.
 * @param {string}  text    What it is doing.
 */
export function setModalLoading( id, loading, text = '' ) {
	const overlay = document.getElementById( id );

	if ( ! overlay ) {
		return;
	}

	const panel = overlay.querySelector( '.s3-modal-loading' );
	panel.querySelector( '.loading-text' ).textContent =
		text || t( 'loading', 'loadingText' );
	panel.hidden = ! loading;
	overlay
		.querySelectorAll( '.s3-modal-footer button' )
		.forEach( ( button ) => {
			button.disabled = loading;
		} );

	if ( loading ) {
		overlay.querySelector( '.s3-modal-error' ).hidden = true;
	}
}

/**
 * Show what went wrong inside a dialog.
 *
 * @param {string} id      The dialog.
 * @param {string} message The error.
 */
export function showModalError( id, message ) {
	const overlay = document.getElementById( id );

	if ( ! overlay ) {
		return;
	}

	const error = overlay.querySelector( '.s3-modal-error' );
	error.textContent = message;
	error.hidden = false;
	setModalLoading( id, false );
}

/**
 * Show a spinner over the page with a line of text.
 *
 * @param {string} message What is happening.
 */
export function showProgress( message ) {
	hideProgress( true );
	document.body.append(
		element( html`
			<div class="s3-progress-overlay" role="status" aria-live="polite">
				<div class="s3-progress-modal">
					<div class="s3-progress-content">
						<div class="spinner is-active"></div>
						<div class="s3-progress-message">${ message }</div>
					</div>
				</div>
			</div>
		` )
	);
}

/**
 * Change what the spinner says.
 *
 * @param {string} message What is happening now.
 */
export function updateProgress( message ) {
	const line = document.querySelector(
		'.s3-progress-overlay .s3-progress-message'
	);

	if ( line ) {
		line.textContent = message;
	}
}

/**
 * Take the spinner away.
 *
 * @param {boolean} now Without fading.
 */
export function hideProgress( now = false ) {
	document
		.querySelectorAll( '.s3-progress-overlay' )
		.forEach( ( overlay ) => {
			if ( now ) {
				overlay.remove();
			} else {
				fadeOut( overlay, 200, true );
			}
		} );
}
