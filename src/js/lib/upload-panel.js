/**
 * The upload panel: the drop zone, the file input, the list of uploads and
 * their cancel buttons.
 */

/* eslint-disable no-alert -- confirm() and prompt() are how the browser has always asked. */

import { request } from './api';
import { browserConfig, globalConfig, t } from './config';
import { element, fadeOut, html, notify, one } from './dom';
import { md5Base64 } from './md5';
import { objectKey, partSettings } from './multipart';
import { createRow, finishRow, noteRow, showProgress } from './progress';
import { createUploader } from './uploader';

const active = new Map();

/**
 * Whether a file may be uploaded, by extension and type.
 *
 * @param {File}   file   The file.
 * @param {Object} config allowedExtensions, allowedMimeTypes.
 * @return {{valid: boolean, error: string}} The verdict.
 */
export function validateFile( file, config ) {
	const extensions = config.allowedExtensions || [];
	const extension = file.name.toLowerCase().split( '.' ).pop();

	if ( extensions.length && ! extensions.includes( extension ) ) {
		return {
			valid: false,
			error: t( 'validation', 'invalidFileType' ).replace(
				'{extension}',
				extension
			),
		};
	}

	if ( file.type && config.allowedMimeTypes ) {
		if (
			! Object.values( config.allowedMimeTypes ).includes( file.type )
		) {
			return {
				valid: false,
				error: t( 'validation', 'invalidMimeType' ).replace(
					'{mimeType}',
					file.type
				),
			};
		}
	}

	return { valid: true, error: '' };
}

/**
 * Show a notice above the upload list for eight seconds.
 *
 * @param {Object} markup `html` output.
 */
function uploadNotice( markup ) {
	document
		.querySelectorAll( '.s3-upload-notice' )
		.forEach( ( old ) => old.remove() );

	const notice = element(
		html`<div class="s3-upload-notice" role="alert">${ markup }</div>`
	);

	one( '.s3-upload-list' )?.before( notice );
	setTimeout( () => fadeOut( notice, 500, true ), 8000 );
}

/**
 * Open or close the panel.
 *
 * @param {boolean} shown Whether it shows.
 */
function setPanel( shown ) {
	const panel = one( '#s3-upload-container' );

	if ( panel ) {
		panel.hidden = ! shown;
		panel.style.display = shown ? '' : 'none';
	}

	one( '#s3-toggle-upload' )?.classList.toggle( 'active', shown );
	one( '#s3-toggle-upload' )?.setAttribute(
		'aria-expanded',
		String( shown )
	);
}

/**
 * Send files into a folder.
 *
 * @param {FileList|File[]} files    The files.
 * @param {string}          bucket   The bucket.
 * @param {string}          prefix   The folder.
 * @param {Object}          uploader createUploader().
 */
function uploadFiles( files, bucket, prefix, uploader ) {
	const config = globalConfig();
	const rules = config.fileValidation || {
		allowedExtensions: config.allowedExtensions,
		allowedMimeTypes: config.allowedMimeTypes,
	};
	const accepted = [];
	const refused = [];

	Array.from( files ).forEach( ( file ) => {
		const verdict = validateFile( file, rules );

		if ( verdict.valid ) {
			accepted.push( file );
		} else {
			refused.push( { name: file.name, error: verdict.error } );
		}
	} );

	if ( refused.length ) {
		uploadNotice( html`
			<div class="s3-validation-errors">
				<h4>${ t( 'validation', 'validationFailed' ) }</h4>
				<ul>
					${ refused.map( ( r ) => html`<li><strong>${ r.name }:</strong> ${ r.error }</li>` ) }
				</ul>
			</div>
		` );
	}

	if ( ! accepted.length ) {
		return;
	}

	if ( refused.length ) {
		notify(
			t( 'validation', 'someFilesRejected' )
				.replace( '{accepted}', String( accepted.length ) )
				.replace( '{rejected}', String( refused.length ) ),
			'info'
		);
	}

	const list = one( '.s3-upload-list' );
	setPanel( true );

	const sends = accepted.map( ( file ) => {
		const id = `upload_${ Date.now() }_${ Math.random().toString( 36 ).slice( 2, 11 ) }`;
		const row = createRow( file, id );

		list.append( row );

		const sending = uploader.upload( file, {
			bucket,
			key: objectKey( prefix, file.name ),
			onProgress: ( progress ) => showProgress( row, progress ),
			onNote: ( text ) => noteRow( row, text ),
		} );

		active.set( id, sending );

		return sending.promise
			.then( () => {
				finishRow( row, 'success' );

				return true;
			} )
			.catch( ( error ) => {
				if ( error.cancelled ) {
					finishRow( row, 'cancelled' );
					setTimeout( () => fadeOut( row, 800, true ), 3000 );

					return false;
				}

				finishRow( row, 'error' );

				const message = error.message || t( 'upload', 'uploadFailed' );

				if ( /CORS|403|401/.test( message ) ) {
					uploadNotice( html`${ t( 'upload', 'corsError' ) }` );
				} else {
					uploadNotice(
						html`${ t( 'upload', 'uploadFailed' ) } ${ message }`
					);
				}

				return false;
			} )
			.finally( () => active.delete( id ) );
	} );

	Promise.all( sends ).then( ( results ) => {
		if ( results.some( Boolean ) ) {
			setTimeout( () => {
				notify( t( 'upload', 'uploadComplete' ), 'success' );
				window.location.reload();
			}, 3000 );
		}
	} );
}

/**
 * Wire the upload panel up.
 */
export function initUploads() {
	const zone = one( '.s3-upload-zone' );

	if ( ! zone ) {
		return;
	}

	const input = one( '.s3-file-input' );

	const uploader = createUploader( {
		request,
		checksum: md5Base64,
		settings: partSettings( globalConfig().multipart ),
		strings: ( browserConfig().i18n || {} ).upload || {},
	} );

	const send = ( files ) =>
		uploadFiles(
			files,
			zone.dataset.bucket,
			zone.dataset.prefix || '',
			uploader
		);

	[ 'dragover', 'dragenter' ].forEach( ( type ) =>
		zone.addEventListener( type, ( event ) => {
			event.preventDefault();
			zone.classList.add( 's3-dragover' );
		} )
	);

	zone.addEventListener( 'dragleave', ( event ) => {
		event.preventDefault();
		zone.classList.remove( 's3-dragover' );
	} );

	zone.addEventListener( 'drop', ( event ) => {
		event.preventDefault();
		zone.classList.remove( 's3-dragover' );

		if ( event.dataTransfer?.files?.length ) {
			send( event.dataTransfer.files );
		}
	} );

	input?.addEventListener( 'change', () => {
		if ( input.files.length ) {
			send( input.files );
			input.value = '';
		}
	} );

	one( '.s3-upload-list' )?.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.s3-cancel-upload' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const id = button.dataset.uploadId;
		const row = document.getElementById( id );
		const name = row?.querySelector( '.s3-filename' )?.textContent || '';

		if (
			active.has( id ) &&
			window.confirm(
				t( 'upload', 'cancelUploadConfirm' ).replace(
					'{filename}',
					name
				)
			)
		) {
			active.get( id ).abort();
		}
	} );

	one( '#s3-toggle-upload' )?.addEventListener( 'click', () => {
		const panel = one( '#s3-upload-container' );
		const shown =
			panel && ( panel.hidden || panel.style.display === 'none' );

		setPanel( shown );

		if ( ! shown && ! active.size ) {
			one( '.s3-upload-list' )?.replaceChildren();
		}
	} );

	one( '.s3-close-upload' )?.addEventListener( 'click', () => {
		if ( active.size ) {
			notify( t( 'ui', 'waitForUploads' ), 'info' );
			return;
		}

		setPanel( false );
		one( '.s3-upload-list' )?.replaceChildren();
	} );

	// Leaving with uploads under way loses them, so say so.
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( active.size ) {
			event.preventDefault();
		}
	} );
}
