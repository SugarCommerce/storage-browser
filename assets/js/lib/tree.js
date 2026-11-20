/**
 * A bucket's folders as a tree, loaded a level at a time.
 *
 * A bucket's layout is only knowable by listing it, and listing all of it
 * to draw a tree costs a request per folder whether or not anybody opens
 * them -- so a folder fetches its children when it is expanded, and a deep
 * bucket costs only the path actually walked.
 */

import { request } from './api';
import { t } from './config';
import { element, html, icon } from './dom';

/**
 * One folder's markup.
 *
 * @param {string}  prefix Its prefix; '' for the bucket itself.
 * @param {string}  label  What it is called.
 * @param {boolean} root   Whether it is the bucket.
 * @return {Object} Markup.
 */
const node = ( prefix, label, root = false ) => html`
	<li
		class="s3-folder-node${ root ? ' is-root' : '' }"
		data-prefix="${ prefix }"
		role="none"
	>
		<div
			class="s3-folder-row"
			role="treeitem"
			tabindex="${ root ? 0 : -1 }"
			aria-expanded="false"
			aria-selected="false"
		>
			<span class="s3-folder-toggle" aria-hidden="true"></span>
			${ icon( root ? 'bucket' : 'folder' ) }
			<span class="s3-folder-label">${ label }</span>
		</div>
		<ul class="s3-folder-children" role="group" hidden></ul>
	</li>
`;

/**
 * Build a folder tree.
 *
 * @param {Object}   options           The tree.
 * @param {string}   options.bucket    The bucket.
 * @param {string}   options.rootLabel What the bucket itself is called.
 * @param {Function} options.onSelect  Called with the chosen folder's prefix.
 * @return {Element} The tree.
 */
export function folderTree( { bucket, rootLabel, onSelect } ) {
	const tree = element( html`
		<div class="s3-folder-tree" data-selected="">
			<ul role="tree" aria-label="${ rootLabel }">
				${ node( '', rootLabel, true ) }
			</ul>
		</div>
	` );

	const toggleNode = ( item ) => {
		const children = item.querySelector( ':scope > .s3-folder-children' );
		const row = item.querySelector( ':scope > .s3-folder-row' );

		if ( item.dataset.loaded === '1' ) {
			children.hidden = ! children.hidden;
			item.classList.toggle( 'is-open', ! children.hidden );
			row.setAttribute( 'aria-expanded', String( ! children.hidden ) );
			return;
		}

		if ( item.dataset.loaded === 'loading' ) {
			return;
		}

		item.dataset.loaded = 'loading';
		item.classList.add( 'is-open' );
		row.setAttribute( 'aria-expanded', 'true' );
		children.hidden = false;
		children.replaceChildren(
			element(
				html`<li class="s3-folder-loading" role="none">
					${ t( 'files', 'loadingFolders' ) }
				</li>`
			)
		);

		request( 'listFolders', { bucket, prefix: item.dataset.prefix || '' } )
			.then( ( answer ) => {
				const folders = answer.folders || [];

				item.dataset.loaded = '1';

				if ( ! folders.length ) {
					item.classList.add( 'is-leaf' );
					children.replaceChildren(
						element(
							html`<li class="s3-folder-empty" role="none">
								${ t( 'files', 'noSubfolders' ) }
							</li>`
						)
					);
					return;
				}

				children.replaceChildren(
					...folders.map( ( folder ) =>
						element( node( folder.prefix, folder.name ) )
					)
				);
			} )
			.catch( ( error ) => {
				item.dataset.loaded = '';
				children.replaceChildren(
					element(
						html`<li class="s3-folder-empty" role="none">
							${ error.message }
						</li>`
					)
				);
			} );
	};

	const select = ( item ) => {
		tree.querySelectorAll( '.s3-folder-node' ).forEach( ( other ) => {
			other.classList.remove( 'is-selected' );
			other
				.querySelector( ':scope > .s3-folder-row' )
				.setAttribute( 'aria-selected', 'false' );
		} );
		item.classList.add( 'is-selected' );
		item.querySelector( ':scope > .s3-folder-row' ).setAttribute(
			'aria-selected',
			'true'
		);
		tree.dataset.selected = item.dataset.prefix || '';
		onSelect( item.dataset.prefix || '' );
	};

	const rows = () =>
		Array.from( tree.querySelectorAll( '.s3-folder-row' ) ).filter(
			( row ) => ! row.closest( '[hidden]' )
		);

	const focusRow = ( row ) => {
		tree.querySelectorAll( '.s3-folder-row' ).forEach( ( other ) => {
			other.tabIndex = -1;
		} );
		row.tabIndex = 0;
		row.focus();
	};

	tree.addEventListener( 'click', ( event ) => {
		const item = event.target.closest( '.s3-folder-node' );

		if ( ! item ) {
			return;
		}

		event.preventDefault();

		// Expanding is not choosing.
		if ( event.target.closest( '.s3-folder-toggle' ) ) {
			toggleNode( item );
			return;
		}

		select( item );
	} );

	tree.addEventListener( 'keydown', ( event ) => {
		const row = event.target.closest( '.s3-folder-row' );

		if ( ! row ) {
			return;
		}

		const item = row.closest( '.s3-folder-node' );
		const visible = rows();
		const index = visible.indexOf( row );

		switch ( event.key ) {
			case 'Enter':
			case ' ':
				event.preventDefault();
				select( item );
				break;
			case 'ArrowRight':
				event.preventDefault();
				if ( row.getAttribute( 'aria-expanded' ) !== 'true' ) {
					toggleNode( item );
				}
				break;
			case 'ArrowLeft':
				event.preventDefault();
				if ( row.getAttribute( 'aria-expanded' ) === 'true' ) {
					toggleNode( item );
				} else {
					const parent =
						item.parentElement.closest( '.s3-folder-node' );
					if ( parent ) {
						focusRow(
							parent.querySelector( ':scope > .s3-folder-row' )
						);
					}
				}
				break;
			case 'ArrowDown':
				event.preventDefault();
				if ( visible[ index + 1 ] ) {
					focusRow( visible[ index + 1 ] );
				}
				break;
			case 'ArrowUp':
				event.preventDefault();
				if ( visible[ index - 1 ] ) {
					focusRow( visible[ index - 1 ] );
				}
				break;
		}
	} );

	// Open the bucket straight away; one collapsed node asks for two clicks
	// before anything shows.
	toggleNode( tree.querySelector( '.s3-folder-node.is-root' ) );

	return tree;
}
