/**
 * What a page that opens the browser needs: the browser as a tab in its
 * media frames, and a listener for the files the browser asks it to insert.
 */

import { globalConfig } from './config';

/**
 * Listen for the browser asking this page to insert files.
 *
 * Origin will not identify the sender: a frame with an opaque origin posts
 * as "null". The token comes from this page's own configuration, so a
 * frame that did not receive it is not ours.
 *
 * @param {Function} insert Called with the files; throw to keep the frame open.
 * @param {Window}   self   This window.
 * @return {Function} Stops listening.
 */
export function listenForInserts( insert, self = window ) {
	const listener = ( event ) => {
		const data = event.data;
		const token = globalConfig().insertToken;

		if ( ! data || data.type !== 's3-browser:insert' ) {
			return;
		}

		if ( ! data.token || ! token || data.token !== token ) {
			return;
		}

		insert( Array.isArray( data.files ) ? data.files : [] );
	};

	self.addEventListener( 'message', listener );

	return () => self.removeEventListener( 'message', listener );
}

/**
 * The browser frame's address.
 *
 * @param {Object} config S3BrowserGlobalConfig.
 * @param {Object} extra  More query arguments: post_id, s3_host.
 * @return {string} URL.
 */
export function frameUrl( config, extra = {} ) {
	const bucket = config.favoriteBucket || config.defaultBucket;
	const query = new URLSearchParams( {
		chromeless: '1',
		tab: `s3_${ config.providerId }`,
		...( bucket ? { bucket, view: 'objects' } : { view: 'buckets' } ),
		...extra,
	} );

	return `${ config.baseUrl }?${ query }`;
}

/**
 * Add the browser as a tab to every Select frame this page opens.
 *
 * One browser for the page, handed to whichever frame is showing: a page
 * with a file field in every row builds a frame per click, and a browser
 * per frame would be a bucket listing fetched again for each.
 *
 * @param {Object}   options        The tab.
 * @param {Function} options.extra  ( frame ) => more query arguments for the frame URL.
 * @param {Function} options.onOpen ( frame ) => void, when a frame opens.
 * @return {boolean} Whether there was a media library to extend.
 */
export function addBrowserTab( {
	extra = () => ( {} ),
	onOpen = () => {},
} = {} ) {
	const media = window.wp && window.wp.media;

	if ( ! media || ! media.view || ! media.view.MediaFrame ) {
		return false;
	}

	const config = globalConfig();

	const Select = media.view.MediaFrame.Select;
	let shared = null;

	media.view.MediaFrame.Select = Select.extend( {
		initialize( ...args ) {
			Select.prototype.initialize.apply( this, args );

			this.states.add( [
				new media.controller.State( {
					id: `s3_${ config.providerId }`,
					title: config.providerName || 'S3 Files',
					priority: 60,
					content: 's3-content',
					menu: 'default',
				} ),
			] );

			this.on( 'content:render:s3-content', this.s3ContentRender, this );
			this.on( 'open', () => onOpen( this ) );
		},

		s3ContentRender() {
			if ( shared ) {
				this.content.set( shared );
				return;
			}

			const view = new media.View( {
				controller: this,
				className: 's3-browser-frame-wrapper',
				tagName: 'div',
			} );
			const src = frameUrl( config, extra( this ) );

			view.render = function () {
				const frame = document.createElement( 'iframe' );

				frame.src = src;
				frame.className = 's3-browser-frame';
				frame.title = config.providerName || 'S3 Files';
				frame.style.cssText =
					'width:100%;height:100%;border:none;min-height:500px;';
				this.el.replaceChildren( frame );
				this.el.style.cssText =
					'height:100%;min-height:500px;width:100%;overflow:hidden;';

				return this;
			};

			this.content.set( view );
			shared = view;
		},
	} );

	return true;
}

/**
 * Close whichever media frame the browser was opened in.
 *
 * @param {Object|null} frame The frame to close, if known.
 */
export function closeFrame( frame = null ) {
	try {
		if ( frame ) {
			frame.close();
		} else if ( window.wp && window.wp.media && window.wp.media.frame ) {
			window.wp.media.frame.close();
		} else if ( window.tb_remove ) {
			window.tb_remove();
		}
	} catch {
		// A frame that will not close is not a reason to lose the files
		// that were just written.
	}
}

/**
 * Normalise a stored path for comparing: no scheme, no outer slashes.
 *
 * @param {string} value A file field's value.
 * @return {string} bucket/key.
 */
export const comparablePath = ( value ) =>
	String( value || '' )
		.trim()
		.replace( /^s3:\/\//, '' )
		.replace( /^\/+|\/+$/g, '' );

/**
 * Set a field's value the way typing would, so whatever listens hears it.
 *
 * @param {HTMLInputElement|null} input The field.
 * @param {string}                value Its new value.
 */
export function setValue( input, value ) {
	if ( ! input ) {
		return;
	}

	input.value = value;
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}
