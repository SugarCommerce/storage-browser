/**
 * The few DOM helpers every module here needs, so none of them needs jQuery.
 */

import { browserConfig } from './config';

/**
 * The first element matching a selector.
 *
 * @param {string}           selector CSS selector.
 * @param {Document|Element} root     Where to look.
 * @return {Element|null} The element.
 */
export const one = ( selector, root = document ) =>
	root.querySelector( selector );

/**
 * Every element matching a selector, as an array.
 *
 * @param {string}           selector CSS selector.
 * @param {Document|Element} root     Where to look.
 * @return {Element[]} The elements.
 */
export const all = ( selector, root = document ) =>
	Array.from( root.querySelectorAll( selector ) );

/**
 * Listen for an event on whatever matches a selector, now or later.
 *
 * @param {Document|Element} root     Where to listen.
 * @param {string}           type     Event type.
 * @param {string}           selector What the event must come from.
 * @param {Function}         handler  Called with ( event, matched element ).
 * @return {Function} Stops listening.
 */
export function on( root, type, selector, handler ) {
	const listener = ( event ) => {
		const target =
			event.target instanceof window.Element
				? event.target.closest( selector )
				: null;

		if ( target && root.contains( target ) ) {
			handler( event, target );
		}
	};

	root.addEventListener( type, listener );

	return () => root.removeEventListener( type, listener );
}

/**
 * Escape a value for HTML text or a quoted attribute.
 *
 * Quotes too: text-node escaping leaves them alone, and a filename of
 * `a" onfocus=alert(1) autofocus` would otherwise leave value="...".
 *
 * @param {*} value Anything.
 * @return {string} Escaped text.
 */
export function escapeHtml( value ) {
	return String( value ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );
}

/**
 * Markup that is already safe, for `html` to insert as it is.
 */
class Raw {
	constructor( markup ) {
		this.markup = String( markup ?? '' );
	}

	toString() {
		return this.markup;
	}
}

/**
 * Mark markup as safe to insert unescaped: an icon PHP drew, or the output
 * of another `html` call.
 *
 * @param {string} markup Markup.
 * @return {Raw} Wrapped markup.
 */
export const raw = ( markup ) => new Raw( markup );

/**
 * A template literal tag that escapes every value put into it.
 *
 * Escaping is the default rather than a decision made at each value, which
 * is how one gets missed: arrays are joined, `raw()` values and nested
 * `html` results pass through, `false`, `null` and `undefined` are nothing.
 *
 * @param {string[]} strings The literal parts.
 * @param {...*}     values  The values.
 * @return {Raw} Safe markup.
 */
export function html( strings, ...values ) {
	const render = ( value ) => {
		if ( value instanceof Raw ) {
			return value.markup;
		}

		if ( Array.isArray( value ) ) {
			return value.map( render ).join( '' );
		}

		if ( value === false || value === null || value === undefined ) {
			return '';
		}

		return escapeHtml( value );
	};

	return new Raw(
		strings.reduce(
			( out, part, index ) =>
				out +
				part +
				( index < values.length ? render( values[ index ] ) : '' ),
			''
		)
	);
}

/**
 * Build an element from markup.
 *
 * @param {Raw|string} markup One root element's markup (`html` output).
 * @return {Element} The element.
 */
export function element( markup ) {
	const template = document.createElement( 'template' );
	template.innerHTML = String( markup ).trim();

	return template.content.firstElementChild;
}

/**
 * One of the icons PHP drew for the scripts (Icon::for_scripts()).
 *
 * @param {string} name bucket, folder, cancel, done, failed and the picker's.
 * @return {Raw} Its markup, or nothing for a name PHP did not send.
 */
export function icon( name ) {
	const icons = browserConfig().icons || window.S3BrowserIcons || {};

	return raw( typeof icons[ name ] === 'string' ? icons[ name ] : '' );
}

/**
 * Fade an element out, then resolve.
 *
 * @param {Element} target   The element.
 * @param {number}  duration Milliseconds.
 * @param {boolean} remove   Take it out of the page afterwards.
 * @return {Promise<void>} Settles when it has gone.
 */
export function fadeOut( target, duration = 300, remove = false ) {
	return new Promise( ( resolve ) => {
		if ( ! target ) {
			resolve();
			return;
		}

		target.style.transition = `opacity ${ duration }ms`;
		target.style.opacity = '0';

		setTimeout( () => {
			if ( remove ) {
				target.remove();
			} else {
				target.hidden = true;
				target.style.opacity = '';
			}

			resolve();
		}, duration );
	} );
}

/**
 * Show or hide an element.
 *
 * @param {Element|null} target The element.
 * @param {boolean}      shown  Whether it shows.
 */
export function toggle( target, shown ) {
	if ( target ) {
		target.hidden = ! shown;
		target.style.display = shown ? '' : 'none';
	}
}

/**
 * Put a button into its busy state, with core's spinning `updating-message`.
 *
 * @param {HTMLButtonElement|null} button The button.
 * @param {string}                 label  A label while it works.
 * @return {boolean} False when it was already busy.
 */
export function setBusy( button, label = '' ) {
	if ( ! button || button.disabled || button.getAttribute( 'aria-busy' ) ) {
		return false;
	}

	if ( button.dataset.s3Label === undefined ) {
		button.dataset.s3Label = button.textContent;
	}

	if ( label ) {
		button.textContent = label;
	}

	button.disabled = true;
	button.classList.add( 'updating-message' );
	button.setAttribute( 'aria-busy', 'true' );

	return true;
}

/**
 * Return a button to rest, with its own label back.
 *
 * @param {HTMLButtonElement|null} button The button.
 */
export function clearBusy( button ) {
	if ( ! button ) {
		return;
	}

	if ( button.dataset.s3Label !== undefined ) {
		button.textContent = button.dataset.s3Label;
		delete button.dataset.s3Label;
	}

	button.disabled = false;
	button.classList.remove( 'updating-message' );
	button.removeAttribute( 'aria-busy' );
}

/**
 * A size people read: 1.5 MB.
 *
 * @param {number} bytes Bytes.
 * @return {string} The size.
 */
export function formatFileSize( bytes ) {
	if ( ! bytes ) {
		return '0 Bytes';
	}

	const sizes = [ 'Bytes', 'KB', 'MB', 'GB', 'TB' ];
	const i = Math.min(
		sizes.length - 1,
		Math.floor( Math.log( bytes ) / Math.log( 1024 ) )
	);

	return `${ parseFloat( ( bytes / Math.pow( 1024, i ) ).toFixed( 2 ) ) } ${
		sizes[ i ]
	}`;
}

/**
 * Reload the page after a pause, so a notice can be read first.
 *
 * @param {number} delay Milliseconds.
 */
export function reloadSoon( delay = 1500 ) {
	setTimeout( () => window.location.reload(), delay );
}

/**
 * Show a notice at the top of the browser that fades after five seconds.
 *
 * Messages routinely carry storage's own error text, which echoes the key
 * back, so they are text, never markup.
 *
 * @param {string} message What to say.
 * @param {string} type    success, error or info.
 */
export function notify( message, type = 'info' ) {
	all( '.s3-notification' ).forEach( ( old ) => old.remove() );

	const container = one( '.s3-browser-container' );

	if ( ! container ) {
		return;
	}

	const notice = document.createElement( 'div' );
	notice.className = `s3-notification s3-notification-${ type }`;
	notice.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
	notice.textContent = message ?? '';
	container.prepend( notice );
	notice.scrollIntoView?.( { block: 'nearest', behavior: 'smooth' } );

	setTimeout( () => fadeOut( notice, 300, true ), 5000 );
}

/**
 * Show a listing error above the "Load more" button.
 *
 * @param {string} message What went wrong.
 */
export function showError( message ) {
	let notice = one( '.s3-ajax-error' );

	if ( ! notice ) {
		notice = element(
			html`<div class="notice notice-error s3-ajax-error" role="alert">
				<p></p>
			</div>`
		);
		one( '.s3-load-more-wrapper' )?.before( notice );
	}

	notice.querySelector( 'p' ).textContent = message;
	toggle( notice, true );
	setTimeout( () => fadeOut( notice ), 5000 );
}
