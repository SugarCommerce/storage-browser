/**
 * Folders: open, create and delete.
 */

/* eslint-disable no-alert -- confirm() and prompt() are how the browser has always asked. */

import { request } from './api';
import { t } from './config';
import { html, notify, on, reloadSoon, setBusy } from './dom';
import { navigateTo } from './list';
import {
	closeModal,
	hideProgress,
	openModal,
	setModalLoading,
	showModalError,
	showProgress,
	updateProgress,
} from './modal';

/**
 * Whether a typed folder name will do.
 *
 * @param {string} name What was typed, trimmed.
 * @return {{valid: boolean, message: string}} The verdict.
 */
export function validateFolderName( name ) {
	const say = ( key ) => ( { valid: false, message: t( 'folders', key ) } );

	if ( ! name.length ) {
		return say( 'folderNameRequired' );
	}

	if ( name.length > 63 ) {
		return say( 'folderNameTooLong' );
	}

	if ( ! /^[a-zA-Z0-9 ._-]+$/.test( name ) ) {
		return say( 'folderNameInvalidChars' );
	}

	if (
		[ '.', '-' ].includes( name[ 0 ] ) ||
		[ '.', '-' ].includes( name[ name.length - 1 ] )
	) {
		return say( 'folderNameStartEnd' );
	}

	if ( name.includes( '..' ) ) {
		return say( 'folderNameConsecutiveDots' );
	}

	return { valid: true, message: '' };
}

/**
 * The New Folder dialog.
 *
 * @param {string} bucket The bucket.
 * @param {string} prefix The folder it goes in.
 */
function createFolder( bucket, prefix ) {
	const id = 's3FolderModal';

	const submit = () => {
		const name = document
			.getElementById( 's3FolderNameInput' )
			.value.trim();
		const verdict = validateFolderName( name );

		if ( ! verdict.valid ) {
			showModalError( id, verdict.message );
			return;
		}

		setModalLoading( id, true, t( 'folders', 'creatingFolder' ) );

		request( 'createFolder', { bucket, prefix, folder_name: name } )
			.then( ( answer ) => {
				notify(
					answer.message ||
						t( 'folders', 'createFolderSuccess' ).replace(
							'{name}',
							name
						),
					'success'
				);
				closeModal( id );
				reloadSoon();
			} )
			.catch( ( error ) => showModalError( id, error.message ) );
	};

	const overlay = openModal( {
		id,
		title: t( 'folders', 'newFolder' ),
		content: html`
			<div class="s3-modal-field">
				<label for="s3FolderNameInput"
					>${ t( 'folders', 'folderName' ) }</label
				>
				<input
					type="text"
					id="s3FolderNameInput"
					maxlength="63"
					placeholder="${ t( 'folders', 'folderNamePlaceholder' ) }"
				/>
				<p class="description">${ t( 'folders', 'folderNameHelp' ) }</p>
			</div>
		`,
		buttons: [
			{
				text: t( 'ui', 'cancel' ),
				action: 'cancel',
				callback: () => closeModal( id ),
			},
			{
				text: t( 'folders', 'createFolder' ),
				action: 'submit',
				classes: 'button-primary',
				callback: submit,
			},
		],
	} );

	const input = overlay.querySelector( '#s3FolderNameInput' );
	const button = overlay.querySelector( 'button[data-action="submit"]' );
	const error = overlay.querySelector( '.s3-modal-error' );

	button.disabled = true;

	input.addEventListener( 'input', () => {
		const name = input.value.trim();
		const verdict = validateFolderName( name );

		error.hidden = verdict.valid || ! name.length;
		error.textContent = verdict.message;
		button.disabled = ! verdict.valid;
	} );

	input.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			submit();
		}
	} );
}

/**
 * Delete a folder and everything in it, after asking.
 *
 * @param {Element} link The Delete link.
 */
function deleteFolder( link ) {
	const name = link.dataset.folderName || '';
	const message = t( 'folders', 'confirmDeleteFolder' )
		.replace( '{foldername}', name )
		.replace( /\\n/g, '\n' );

	if ( ! window.confirm( message ) ) {
		return;
	}

	showProgress(
		t( 'folders', 'deletingFolderProgress' ).replace( '{name}', name )
	);

	request( 'deleteFolder', {
		bucket: link.dataset.bucket,
		folder_path: link.dataset.prefix,
		recursive: true,
	} )
		.then( ( answer ) => {
			updateProgress( t( 'folders', 'folderDeletedSuccess' ) );

			setTimeout( () => {
				hideProgress();
				notify(
					answer.message || t( 'folders', 'deleteFolderSuccess' ),
					'success'
				);
				reloadSoon();
			}, 500 );
		} )
		.catch( ( error ) => {
			hideProgress();
			notify( error.message, 'error' );
		} );
}

/**
 * Wire the folder actions up.
 */
export function initFolders() {
	on( document, 'click', '.s3-open-folder', ( event, button ) => {
		event.preventDefault();
		setBusy( button, t( 'folders', 'opening' ) );
		navigateTo( {
			bucket: button.dataset.bucket,
			prefix: button.dataset.prefix,
		} );
	} );

	on(
		document,
		'click',
		'.wp-list-table .row-actions a.s3-delete-folder',
		( event, link ) => {
			event.preventDefault();
			deleteFolder( link );
		}
	);

	on( document, 'click', '#s3-create-folder', ( event, button ) => {
		event.preventDefault();
		createFolder( button.dataset.bucket, button.dataset.prefix || '' );
	} );
}
