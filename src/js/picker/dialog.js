/**
 * The "Choose from your bucket" dialog.
 *
 * A native <dialog> opened with showModal(), as the family's other pickers
 * are: the browser puts it in the top layer, keeps focus inside it and
 * gives Escape a cancel. Focus stays in the search box; the arrow keys move
 * through the list, Enter opens a folder or chooses a file, Backspace in an
 * empty box goes up a folder.
 */

import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../lib/api';
import { formatFileSize, html, raw } from '../lib/dom';
import { keyAction, nextIndex } from './keyboard';
import { choice, crumbs, entries, parentOf } from './listing';

let count = 0;

/**
 * The icon for a kind of file: its category's, or the plain file's.
 *
 * @param {Object} icons    Name => markup.
 * @param {string} category image, video, audio, document, archive or other.
 * @return {string} An icon name.
 */
const fileIcon = ( icons, category ) =>
	icons && icons[ category ] ? category : 'file';

/**
 * One of the icons PHP drew for this picker.
 *
 * @param {Object} icons Name => markup.
 * @param {string} name  The icon.
 * @return {Object} Safe markup.
 */
const iconOf = ( icons, name ) => raw( ( icons && icons[ name ] ) || '' );

/**
 * Open a picker and wait for a file, or for nothing.
 *
 * @param {Object}   source         The source's configuration from PHP: restUrl, nonce, defaultBucket, buckets, icons, label.
 * @param {Object}   options        What to show.
 * @param {string}   options.bucket The bucket to open on.
 * @param {string}   options.prefix The folder to open on.
 * @param {string[]} options.accept Extensions that may be chosen; empty for any.
 * @param {string}   options.title  The dialog's title.
 * @return {Promise<Object|null>} The chosen file (listing.choice()), or null.
 */
export function openPicker( source, options = {} ) {
	const id = `sc-storage-picker-${ ++count }`;
	const icons = source.icons || {};
	const api = ( name, data ) =>
		request( name, data, { base: source.restUrl, nonce: source.nonce } );

	const state = {
		buckets: Array.isArray( source.buckets ) ? source.buckets : [],
		bucket: options.bucket || source.defaultBucket || '',
		prefix: options.prefix || '',
		term: '',
		listing: { folders: [], objects: [] },
		next: '',
		rows: [],
		active: -1,
		selected: null,
		loading: false,
		sequence: 0,
		timer: null,
	};

	const dialog = document.createElement( 'dialog' );

	dialog.className = 'sc-storage-picker';
	dialog.setAttribute( 'aria-labelledby', `${ id }-title` );
	dialog.innerHTML = html`
		<div class="sc-storage-picker__header">
			<h2 class="sc-storage-picker__title" id="${ id }-title">
				${ options.title || __( 'Choose a file', 'sugarcommerce' ) }
			</h2>
			<button
				type="button"
				class="sc-storage-picker__close"
				aria-label="${ __( 'Close', 'sugarcommerce' ) }"
			>
				${ iconOf( icons, 'close' ) }
			</button>
		</div>
		<div class="sc-storage-picker__tools">
			<div class="sc-storage-picker__where">
				<label class="screen-reader-text" for="${ id }-bucket"
					>${ __( 'Bucket', 'sugarcommerce' ) }</label
				>
				<select
					class="sc-storage-picker__bucket"
					id="${ id }-bucket"
					hidden
				></select>
				<nav
					class="sc-storage-picker__crumbs"
					aria-label="${ __( 'Folder', 'sugarcommerce' ) }"
				>
					<ol></ol>
				</nav>
			</div>
			<div class="sc-storage-picker__search">
				${ iconOf( icons, 'search' ) }
				<label class="screen-reader-text" for="${ id }-search"
					>${ __( 'Search this folder and the folders in it', 'sugarcommerce' ) }</label
				>
				<input
					type="search"
					id="${ id }-search"
					class="sc-storage-picker__input"
					role="combobox"
					aria-expanded="true"
					aria-autocomplete="list"
					aria-controls="${ id }-list"
					autocomplete="off"
					spellcheck="false"
					placeholder="${ __( 'Search this folder', 'sugarcommerce' ) }"
				/>
			</div>
		</div>
		<div class="sc-storage-picker__results">
			<ul
				class="sc-storage-picker__list"
				id="${ id }-list"
				role="listbox"
				aria-label="${ __( 'Files and folders', 'sugarcommerce' ) }"
			></ul>
			<p class="sc-storage-picker__empty" hidden></p>
		</div>
		<div class="sc-storage-picker__footer">
			<p
				class="sc-storage-picker__status"
				role="status"
				aria-live="polite"
			></p>
			<div class="sc-storage-picker__actions">
				<button type="button" class="button sc-storage-picker__cancel">
					${ __( 'Cancel', 'sugarcommerce' ) }
				</button>
				<button
					type="button"
					class="button button-primary sc-storage-picker__choose"
					disabled
				>
					${ __( 'Choose file', 'sugarcommerce' ) }
				</button>
			</div>
		</div>
	`.markup;

	const find = ( selector ) => dialog.querySelector( selector );
	const input = find( '.sc-storage-picker__input' );
	const list = find( '.sc-storage-picker__list' );
	const empty = find( '.sc-storage-picker__empty' );
	const status = find( '.sc-storage-picker__status' );
	const chooseButton = find( '.sc-storage-picker__choose' );
	const bucketSelect = find( '.sc-storage-picker__bucket' );
	const crumbList = find( '.sc-storage-picker__crumbs ol' );

	let finish;
	const result = new Promise( ( resolve ) => {
		finish = resolve;
	} );

	const close = ( value ) => {
		clearTimeout( state.timer );

		if ( dialog.open ) {
			dialog.close();
		}

		dialog.remove();
		finish( value );
	};

	const say = ( text ) => {
		status.textContent = text;
	};

	const drawCrumbs = () => {
		const trail = [
			{
				name: state.bucket || __( 'Bucket', 'sugarcommerce' ),
				prefix: '',
			},
			...crumbs( state.prefix ),
		];

		crumbList.innerHTML = html`${ trail.map( ( crumb, index ) =>
			index === trail.length - 1
				? html`<li>
						<span aria-current="location"
							>${ index === 0 ? iconOf( icons, 'bucket' ) : '' }${ crumb.name }</span
						>
					</li>`
				: html`<li>
						<button
							type="button"
							class="button-link"
							data-prefix="${ crumb.prefix }"
						>
							${ index === 0 ? iconOf( icons, 'bucket' ) : '' }${ crumb.name }</button
						><span class="sc-storage-picker__sep" aria-hidden="true"
							>${ iconOf( icons, 'chevron' ) || '/' }</span
						>
					</li>`
		) }`.markup;
	};

	const setActive = ( index, scroll = true ) => {
		state.active = index;

		list.querySelectorAll( '[role="option"]' ).forEach( ( option, i ) => {
			option.classList.toggle( 'is-active', i === index );
		} );

		const option = list.querySelector( `[data-index="${ index }"]` );

		if ( option ) {
			input.setAttribute( 'aria-activedescendant', option.id );

			if ( scroll ) {
				option.scrollIntoView?.( { block: 'nearest' } );
			}
		} else {
			input.removeAttribute( 'aria-activedescendant' );
		}
	};

	const select = ( index ) => {
		const row = state.rows[ index ];

		state.selected = row && row.type === 'file' ? row : null;
		list.querySelectorAll( '[role="option"]' ).forEach( ( option, i ) => {
			option.setAttribute(
				'aria-selected',
				String( i === index && !! state.selected )
			);
		} );
		chooseButton.disabled = ! state.selected;
		setActive( index );

		if ( state.selected ) {
			say(
				sprintf(
					/* translators: 1: a file name, 2: its size. */
					__( 'Selected %1$s, %2$s.', 'sugarcommerce' ),
					state.selected.name,
					formatFileSize( state.selected.size )
				)
			);
		}
	};

	const draw = () => {
		const searching = state.term.trim() !== '';

		state.rows = entries( state.listing, {
			accept: options.accept || [],
			term: state.term,
		} );

		if ( state.next ) {
			state.rows.push( {
				type: 'more',
				name: __( 'Load more', 'sugarcommerce' ),
			} );
		}

		list.innerHTML = html`${ state.rows.map( ( row, index ) => {
			if ( row.type === 'more' ) {
				return html`<li
					role="option"
					id="${ id }-opt-${ index }"
					data-index="${ index }"
					aria-selected="false"
					class="sc-storage-picker__option sc-storage-picker__option--more"
				>
					${ row.name }
				</li>`;
			}

			const folder = row.type === 'folder';
			const where =
				searching && ! folder && row.key.includes( '/' )
					? row.key.slice( 0, row.key.lastIndexOf( '/' ) + 1 )
					: '';

			return html`<li
				role="option"
				id="${ id }-opt-${ index }"
				data-index="${ index }"
				aria-selected="false"
				class="sc-storage-picker__option${ folder ? ' is-folder' : '' }"
			>
				<span class="sc-storage-picker__icon"
					>${ iconOf( icons, folder ? 'folder' : fileIcon( icons, row.category ) ) }</span
				>
				<span class="sc-storage-picker__name"
					>${ row.name }${ where && html`<span class="sc-storage-picker__path">${ where }</span>` }</span
				>
				<span class="sc-storage-picker__meta"
					>${ folder ? __( 'Folder', 'sugarcommerce' ) : row.size_label || formatFileSize( row.size ) }${ ! folder && row.modified_label ? html`<span class="sc-storage-picker__date"> · ${ row.modified_label }</span>` : '' }</span
				>
			</li>`;
		} ) }`.markup;

		const files = state.rows.filter(
			( row ) => row.type === 'file'
		).length;
		const folders = state.rows.filter(
			( row ) => row.type === 'folder'
		).length;

		empty.hidden = state.rows.length > 0 || state.loading;
		empty.textContent = searching
			? __( 'Nothing here matches that search.', 'sugarcommerce' )
			: __( 'This folder is empty.', 'sugarcommerce' );

		if ( ! state.loading ) {
			say(
				sprintf(
					/* translators: 1: number of folders, 2: number of files. */
					__( '%1$s, %2$s.', 'sugarcommerce' ),
					sprintf(
						/* translators: %d: number of folders. */
						_n(
							'%d folder',
							'%d folders',
							folders,
							'sugarcommerce'
						),
						folders
					),
					sprintf(
						/* translators: %d: number of files. */
						_n( '%d file', '%d files', files, 'sugarcommerce' ),
						files
					)
				)
			);
		}

		state.selected = null;
		chooseButton.disabled = true;
		setActive( state.rows.length ? 0 : -1, false );
	};

	const load = ( append = false ) => {
		if ( ! state.bucket ) {
			return Promise.resolve();
		}

		const sequence = ++state.sequence;

		state.loading = true;
		list.setAttribute( 'aria-busy', 'true' );
		say( __( 'Loading…', 'sugarcommerce' ) );

		return api( 'browse', {
			bucket: state.bucket,
			prefix: state.prefix,
			search: state.term.trim(),
			continuation_token: append ? state.next : '',
		} )
			.then( ( answer ) => {
				// A slower answer to an older question is not the list.
				if ( sequence !== state.sequence ) {
					return;
				}

				state.listing = append
					? {
							folders: [
								...state.listing.folders,
								...( answer.folders || [] ),
							],
							objects: [
								...state.listing.objects,
								...( answer.objects || [] ),
							],
						}
					: {
							folders: answer.folders || [],
							objects: answer.objects || [],
						};
				state.next = answer.truncated
					? answer.continuation_token || ''
					: '';
				state.loading = false;
				list.removeAttribute( 'aria-busy' );
				draw();
			} )
			.catch( ( error ) => {
				if ( sequence !== state.sequence ) {
					return;
				}

				state.loading = false;
				list.removeAttribute( 'aria-busy' );
				state.listing = { folders: [], objects: [] };
				draw();
				empty.hidden = false;
				empty.textContent = error.message;
				say( error.message );
			} );
	};

	const go = ( prefix ) => {
		state.prefix = prefix;
		state.term = '';
		input.value = '';
		drawCrumbs();
		load();
	};

	const act = ( index ) => {
		const row = state.rows[ index ];

		if ( ! row ) {
			return;
		}

		if ( row.type === 'folder' ) {
			go( row.prefix );
		} else if ( row.type === 'more' ) {
			load( true );
		} else {
			close( choice( state.bucket, row ) );
		}
	};

	const drawBuckets = () => {
		bucketSelect.innerHTML = html`${ state.buckets.map(
			( bucket ) =>
				html`<option value="${ bucket.name }">${ bucket.name }</option>`
		) }`.markup;
		bucketSelect.value = state.bucket;
		bucketSelect.hidden = state.buckets.length < 2;
	};

	dialog.addEventListener( 'cancel', ( event ) => {
		event.preventDefault();
		close( null );
	} );

	dialog.addEventListener( 'click', ( event ) => {
		if ( event.target === dialog ) {
			close( null );
			return;
		}

		if (
			event.target.closest(
				'.sc-storage-picker__close, .sc-storage-picker__cancel'
			)
		) {
			close( null );
			return;
		}

		if (
			event.target.closest( '.sc-storage-picker__choose' ) &&
			state.selected
		) {
			close( choice( state.bucket, state.selected ) );
			return;
		}

		const crumb = event.target.closest(
			'.sc-storage-picker__crumbs button'
		);

		if ( crumb ) {
			go( crumb.dataset.prefix || '' );
			input.focus();
			return;
		}

		const option = event.target.closest( '[role="option"]' );

		if ( option ) {
			const index = Number( option.dataset.index );
			const row = state.rows[ index ];

			if ( row && row.type === 'file' ) {
				select( index );
			} else {
				act( index );
			}

			input.focus();
		}
	} );

	dialog.addEventListener( 'dblclick', ( event ) => {
		const option = event.target.closest( '[role="option"]' );

		if ( option ) {
			act( Number( option.dataset.index ) );
		}
	} );

	input.addEventListener( 'keydown', ( event ) => {
		const move = nextIndex( state.active, event.key, state.rows.length );

		if ( move !== null ) {
			event.preventDefault();
			const row = state.rows[ move ];

			if ( row && row.type === 'file' ) {
				select( move );
			} else {
				setActive( move );
			}

			return;
		}

		const action = keyAction(
			event.key,
			state.rows[ state.active ] || null,
			input.value
		);

		switch ( action ) {
			case 'open':
			case 'more':
			case 'choose':
				event.preventDefault();
				act( state.active );
				break;
			case 'up':
				if ( state.prefix ) {
					event.preventDefault();
					go( parentOf( state.prefix ) );
				}
				break;
			case 'close':
				event.preventDefault();
				close( null );
				break;
		}
	} );

	input.addEventListener( 'input', () => {
		state.term = input.value;
		draw();
		clearTimeout( state.timer );
		state.timer = setTimeout( () => load(), 300 );
	} );

	bucketSelect.addEventListener( 'change', () => {
		state.bucket = bucketSelect.value;
		go( '' );
	} );

	document.body.append( dialog );
	dialog.showModal();
	input.focus();
	drawCrumbs();

	const ready = state.buckets.length
		? Promise.resolve()
		: api( 'listBuckets', {} ).then(
				( answer ) => {
					state.buckets = answer.buckets || [];
				},
				() => {
					state.buckets = state.bucket
						? [ { name: state.bucket } ]
						: [];
				}
			);

	ready.then( () => {
		if ( ! state.bucket && state.buckets.length ) {
			state.bucket = state.buckets[ 0 ].name;
		}

		drawBuckets();
		drawCrumbs();

		if ( ! state.bucket ) {
			empty.hidden = false;
			empty.textContent = __(
				'There is no bucket to choose from.',
				'sugarcommerce'
			);
			return;
		}

		load();
	} );

	return result;
}
