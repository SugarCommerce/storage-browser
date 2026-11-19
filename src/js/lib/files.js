/**
 * What can be done to one file: download, delete, details, copy a link,
 * rename and move.
 */

/* eslint-disable no-alert -- confirm() and prompt() are how the browser has always asked. */

import { request } from './api';
import { globalConfig, t } from './config';
import {
	clearBusy,
	element,
	fadeOut,
	html,
	notify,
	on,
	reloadSoon,
	setBusy,
} from './dom';
import {
	closeModal,
	openModal,
	setModalLoading,
	showModalError,
} from './modal';
import { fileDetails } from './templates';
import { folderTree } from './tree';

/**
 * The file a row action is about.
 *
 * @param {Element} link The action's link.
 * @return {{filename: string, bucket: string, key: string, prefix: string}} The file.
 */
const fileOf = ( link ) => ( {
	filename: link.dataset.filename || '',
	bucket: link.dataset.bucket || '',
	key: link.dataset.key || '',
	prefix: link.dataset.prefix || '',
} );

/**
 * Fetch a download URL and open it.
 *
 * Minted on click rather than rendered into every row: signing each one up
 * front is a SigV4 derivation per file, and it would put a working,
 * hour-long URL for every object into the page source.
 *
 * @param {Element} link The Download link.
 */
function download( link ) {
	if ( link.dataset.s3Fetching ) {
		return;
	}

	link.dataset.s3Fetching = '1';

	// Opened before the request so the click still counts; popup blockers
	// refuse window.open() from an async callback.
	const target = window.open( '', '_blank' );

	request( 'downloadUrl', {
		bucket: link.dataset.bucket,
		key: link.dataset.key,
	} )
		.then( ( answer ) => {
			if ( target ) {
				target.location = answer.url;
			} else {
				window.location = answer.url;
			}
		} )
		.catch( ( error ) => {
			target?.close();
			notify( error.message, 'error' );
		} )
		.finally( () => {
			delete link.dataset.s3Fetching;
		} );
}

/**
 * The bulleted list of what uses a file, for a confirm() box.
 *
 * @param {Array<Object>} references { label } each.
 * @param {string}        one        The heading for one.
 * @param {string}        many       The heading for several, with %d.
 * @return {string} Text.
 */
const referenceText = ( references, one, many ) =>
	`${ ( references.length === 1 ? one : many ).replace( '%d', String( references.length ) ) }\n\n${ references
		.map( ( reference ) => `• ${ reference.label }` )
		.join( '\n' ) }\n\n`;

/**
 * Delete a file, after saying what depends on it.
 *
 * A rename can be followed and repaired; a delete cannot, and whatever
 * names the object stops working the moment it goes.
 *
 * @param {Element} link The Delete link.
 */
function remove( link ) {
	const file = fileOf( link );

	request( 'objectReferences', { bucket: file.bucket, key: file.key } )
		// A lookup that fails must not block the delete; the warning is just
		// the ordinary one.
		.then(
			( answer ) => answer.references || [],
			() => []
		)
		.then( ( references ) => {
			let message = t( 'files', 'confirmDelete' )
				.replace( '{filename}', file.filename )
				.replace( /\\n/g, '\n' );

			if ( references.length ) {
				message =
					referenceText(
						references,
						t( 'files', 'deleteBreaksOne' ),
						t( 'files', 'deleteBreaksMany' )
					) + message;
			}

			if ( ! window.confirm( message ) ) {
				return;
			}

			setBusy( link, t( 'ui', 'deleting' ) );

			request( 'deleteObject', { bucket: file.bucket, key: file.key } )
				.then( ( answer ) => {
					notify(
						answer.message || t( 'files', 'deleteSuccess' ),
						'success'
					);
					fadeOut( link.closest( 'tr' ) ).then( () =>
						reloadSoon( 500 )
					);
				} )
				.catch( ( error ) => {
					notify( error.message, 'error' );
					clearBusy( link );
				} );
		} );
}

/**
 * Open the details dialog for a row's file.
 *
 * The element carrying every data-* attribute is read, not the first with
 * a key: the checkbox and the action links carry data-key too.
 *
 * @param {Element} link The Details link.
 */
function details( link ) {
	const row = link.closest( 'tr' );
	const source =
		row?.querySelector( '[data-size-bytes]' ) ||
		row?.querySelector( '[data-key]' );

	if ( ! source ) {
		notify( 'Could not find file data', 'error' );
		return;
	}

	const data = source.dataset;
	const bucket =
		row.querySelector( '[data-bucket]' )?.dataset.bucket ||
		globalConfig().defaultBucket ||
		'';
	const file = {
		filename: data.filename,
		key: data.key,
		sizeBytes: data.sizeBytes,
		sizeFormatted: data.sizeFormatted,
		modified: data.modified,
		modifiedFormatted: data.modifiedFormatted,
		etag: data.etag,
		md5: data.md5,
		isMultipart: data.isMultipart === 'true',
		storageClass: data.storageClass,
		mimeType: data.mimeType,
		category: data.category,
		partCount: data.partCount || null,
	};

	openModal( {
		id: 's3DetailsModal',
		title: t( 'fileDetails', 'title' ),
		content: fileDetails( file ),
		buttons: [
			{
				text: t( 'ui', 'cancel' ),
				action: 'close',
				callback: () => closeModal( 's3DetailsModal' ),
			},
			{
				text: t( 'copyLink', 'copyLink' ),
				action: 'copy_link',
				classes: 'button-primary',
				callback: () =>
					closeModal( 's3DetailsModal' ).then( () =>
						copyLink( {
							filename: file.filename,
							bucket,
							key: file.key,
						} )
					),
			},
		],
	} );
}

/**
 * Put text on the clipboard, and say whether it went.
 *
 * Runs after a request, and a browser only allows a clipboard write while
 * the click that started it still counts. A refusal is expected rather than
 * exceptional: the URL is already selected, and the notice says to copy it.
 *
 * @param {string} text The URL.
 */
function toClipboard( text ) {
	const copied = () => notify( t( 'copyLink', 'linkCopied' ), 'success' );
	const fallback = () => {
		const area = document.createElement( 'textarea' );

		area.value = text;
		area.style.position = 'fixed';
		area.style.top = '-999px';
		document.body.append( area );
		area.select();

		let ok = false;

		try {
			ok = document.execCommand( 'copy' );
		} catch {
			ok = false;
		}

		area.remove();

		if ( ok ) {
			copied();
		} else {
			notify( t( 'copyLink', 'copyFailed' ), 'info' );
		}
	};

	if ( window.navigator.clipboard?.writeText ) {
		window.navigator.clipboard.writeText( text ).then( copied, fallback );
	} else {
		fallback();
	}
}

/**
 * The Copy Link dialog: choose how long, sign, copy.
 *
 * @param {Object} file { filename, bucket, key }.
 */
export function copyLink( file ) {
	const id = 's3CopyLinkModal';

	const generate = () => {
		const input = document.getElementById( 's3ExpiresInput' );
		const minutes = parseInt( input.value, 10 ) || 60;

		if ( minutes < 1 || minutes > 10080 ) {
			showModalError( id, t( 'copyLink', 'invalidDuration' ) );
			return;
		}

		setModalLoading( id, true, t( 'copyLink', 'generatingLink' ) );

		request( 'downloadUrl', {
			bucket: file.bucket,
			key: file.key,
			expires_minutes: minutes,
		} )
			.then( ( answer ) => {
				const output = document.getElementById( 's3GeneratedUrl' );
				const expires = new Date( answer.expires_at * 1000 );
				const note = document.querySelector(
					`#${ id } .s3-generated-note`
				);

				output.value = answer.url;
				output.focus();
				output.select();
				toClipboard( answer.url );
				setModalLoading( id, false );

				note.replaceChildren(
					element(
						html`<span
							><strong
								>${ t( 'copyLink', 'linkGenerated' ) || 'Link generated successfully!' }</strong
							><br />${ (
								t( 'copyLink', 'linkExpiresAt' ) ||
								'Link expires at {time}'
							).replace(
								'{time}',
								expires.toLocaleString()
							) }</span
						>`
					)
				);
			} )
			.catch( ( error ) => showModalError( id, error.message ) );
	};

	openModal( {
		id,
		title: t( 'copyLink', 'copyLink' ),
		content: html`
			<div class="s3-modal-field">
				<label for="s3ExpiresInput"
					>${ t( 'copyLink', 'linkDuration' ) }</label
				>
				<input
					type="number"
					id="s3ExpiresInput"
					min="1"
					max="10080"
					value="60"
				/>
				<p class="description">
					${ t( 'copyLink', 'linkDurationHelp' ) }
				</p>
			</div>
			<div class="s3-modal-field">
				<label for="s3GeneratedUrl"
					>${ t( 'copyLink', 'generatedLink' ) }</label
				>
				<textarea
					id="s3GeneratedUrl"
					rows="4"
					readonly
					placeholder="${ t( 'copyLink', 'generateLinkFirst' ) }"
				></textarea>
				<p class="description s3-generated-note"></p>
			</div>
		`,
		buttons: [
			{
				text: t( 'ui', 'cancel' ),
				action: 'cancel',
				callback: () => closeModal( id ),
			},
			{
				text: t( 'copyLink', 'generateLink' ),
				action: 'generate',
				classes: 'button-primary',
				callback: generate,
			},
		],
	} );

	document.getElementById( 's3ExpiresInput' )?.select();
}

/**
 * Whether a typed name will do for a rename.
 *
 * @param {string} stem     The name without its extension.
 * @param {string} full     With the original extension put back.
 * @param {string} original The current name.
 * @return {{valid: boolean, message: string}} The verdict.
 */
export function validateFilename( stem, full, original ) {
	const say = ( key ) => ( { valid: false, message: t( 'files', key ) } );

	if ( ! stem.length ) {
		return say( 'filenameRequired' );
	}

	if ( full.length > 255 ) {
		return say( 'filenameTooLong' );
	}

	if (
		/[<>:"|?*\/\\]/.test( stem ) ||
		/^[.\-_]/.test( stem ) ||
		stem.includes( '..' )
	) {
		return say( 'filenameInvalid' );
	}

	if ( full === original ) {
		return say( 'filenameSame' );
	}

	return { valid: true, message: '' };
}

/**
 * A name's stem and extension.
 *
 * @param {string} name A filename.
 * @return {{stem: string, extension: string}} Its parts.
 */
export function splitName( name ) {
	const dot = name.lastIndexOf( '.' );

	return dot > 0
		? { stem: name.slice( 0, dot ), extension: name.slice( dot + 1 ) }
		: { stem: name, extension: '' };
}

/**
 * Warn, inside a dialog, if anything refers to this object by its key.
 *
 * A stored key does not follow the object, so a product pointing at the old
 * one stops working at the moment a customer downloads it. The server asks
 * its consumers; nothing here knows what holds keys.
 *
 * @param {Element} overlay The dialog.
 * @param {Object}  file    { bucket, key }.
 */
function showReferences( overlay, file ) {
	const panel = overlay.querySelector( '.s3-object-references' );

	request( 'objectReferences', { bucket: file.bucket, key: file.key } )
		.then( ( answer ) => {
			const references = answer.references || [];

			if ( ! references.length || ! panel ) {
				return;
			}

			const heading = (
				references.length === 1
					? t( 'files', 'referencedByOne' )
					: t( 'files', 'referencedByMany' )
			).replace( '%d', String( references.length ) );

			panel.replaceChildren(
				element( html`
					<div class="notice notice-warning inline">
						<p><strong>${ heading }</strong></p>
						<ul>
							${ references.map( ( reference ) =>
								reference.edit_url
									? html`<li>
											<a
												href="${ reference.edit_url }"
												target="_blank"
												rel="noopener"
												>${ reference.label }</a
											>
										</li>`
									: html`<li>${ reference.label }</li>`
							) }
						</ul>
						<p>${ t( 'files', 'referencesUpdated' ) }</p>
					</div>
				` )
			);
			panel.hidden = false;
		} )
		// Advice only: the rename works with or without it.
		.catch( () => {} );
}

/**
 * The Rename dialog. The extension stays: only the stem is edited.
 *
 * @param {Element} link The Rename link.
 */
function rename( link ) {
	const id = 's3RenameModal';
	const file = fileOf( link );
	const { stem, extension } = splitName( file.filename );
	const fullName = ( typed ) =>
		typed + ( extension ? `.${ extension }` : '' );

	const submit = () => {
		const typed = document.getElementById( 's3RenameInput' ).value.trim();
		const verdict = validateFilename(
			typed,
			fullName( typed ),
			file.filename
		);

		if ( ! verdict.valid ) {
			showModalError( id, verdict.message );
			return;
		}

		setModalLoading( id, true, t( 'files', 'renamingFile' ) );

		request( 'renameObject', {
			bucket: file.bucket,
			current_key: file.key,
			new_filename: fullName( typed ),
		} )
			.then( ( answer ) => {
				notify(
					answer.message || t( 'files', 'renameSuccess' ),
					'success'
				);
				closeModal( id );
				reloadSoon();
			} )
			.catch( ( error ) => showModalError( id, error.message ) );
	};

	const overlay = openModal( {
		id,
		title: t( 'files', 'renameFile' ),
		content: html`
			<div class="s3-modal-field">
				<label for="s3RenameInput"
					>${ t( 'files', 'filenameLabel' ) }</label
				>
				<input type="text" id="s3RenameInput" maxlength="255" />
				<p class="description">${ t( 'files', 'filenameHelp' ) }</p>
			</div>
			<div class="s3-object-references" hidden></div>
		`,
		buttons: [
			{
				text: t( 'ui', 'cancel' ),
				action: 'cancel',
				callback: () => closeModal( id ),
			},
			{
				text: t( 'files', 'renameFile' ),
				action: 'submit',
				classes: 'button-primary',
				callback: submit,
			},
		],
	} );

	const input = overlay.querySelector( '#s3RenameInput' );
	const button = overlay.querySelector( 'button[data-action="submit"]' );
	const error = overlay.querySelector( '.s3-modal-error' );

	// A property, not an attribute in markup: quotes in a name stay inert.
	input.value = stem;
	input.select();
	button.disabled = true;

	input.addEventListener( 'input', () => {
		const typed = input.value.trim();
		const verdict = validateFilename(
			typed,
			fullName( typed ),
			file.filename
		);

		error.hidden = verdict.valid || ! typed;
		error.textContent = verdict.valid ? '' : verdict.message;
		button.disabled = ! verdict.valid;
	} );

	input.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Enter' ) {
			event.preventDefault();

			if ( ! button.disabled ) {
				submit();
			}
		}
	} );

	showReferences( overlay, file );
}

/**
 * Whether two prefixes name the same folder.
 *
 * @param {string} a A prefix.
 * @param {string} b Another.
 * @return {boolean} Whether they match.
 */
export const samePrefix = ( a, b ) =>
	String( a ).replace( /^\/+|\/+$/g, '' ) ===
	String( b ).replace( /^\/+|\/+$/g, '' );

/**
 * The Move dialog: a folder tree loaded a level at a time.
 *
 * @param {Element} link The Move link.
 */
function move( link ) {
	const id = 's3MoveModal';
	const file = fileOf( link );
	let target = null;

	const overlay = openModal( {
		id,
		title: t( 'files', 'moveFile' ),
		content: html`
			<p class="description">${ t( 'files', 'moveTo' ) }</p>
			<div class="s3-folder-tree-slot"></div>
			<div class="s3-object-references" hidden></div>
		`,
		buttons: [
			{
				text: t( 'ui', 'cancel' ),
				action: 'cancel',
				callback: () => closeModal( id ),
			},
			{
				text: t( 'files', 'moveHere' ),
				action: 'submit',
				classes: 'button-primary',
				callback: () => {
					setModalLoading( id, true, t( 'files', 'movingFile' ) );

					request( 'moveObject', {
						bucket: file.bucket,
						key: file.key,
						target_prefix: target ?? '',
					} )
						.then( ( answer ) => {
							closeModal( id );
							notify(
								answer.message || t( 'files', 'moveSuccess' ),
								'success'
							);
							reloadSoon();
						} )
						.catch( ( error ) =>
							showModalError( id, error.message )
						);
				},
			},
		],
	} );

	const submit = overlay.querySelector( 'button[data-action="submit"]' );

	// Nothing is chosen until a folder is, and the file's own folder is
	// never a destination.
	submit.disabled = true;

	overlay.querySelector( '.s3-folder-tree-slot' ).replaceWith(
		folderTree( {
			bucket: file.bucket,
			rootLabel: t( 'files', 'moveRoot' ),
			onSelect: ( prefix ) => {
				target = prefix;
				submit.disabled = samePrefix( prefix, file.prefix );
			},
		} )
	);

	showReferences( overlay, file );
}

/**
 * Wire the row actions up.
 */
export function initFiles() {
	on( document, 'click', '.wp-list-table .row-actions a', ( event, link ) => {
		const actions = {
			's3-download-file': download,
			's3-delete-file': remove,
			's3-rename-file': rename,
			's3-move-file': move,
			's3-copy-link': ( l ) => copyLink( fileOf( l ) ),
			's3-show-details': details,
		};
		const name = Object.keys( actions ).find( ( cls ) =>
			link.classList.contains( cls )
		);

		if ( name ) {
			event.preventDefault();
			actions[ name ]( link );
		}
	} );
}
