/**
 * Moving through the picker's list from the search box.
 *
 * Focus stays in the search box -- typing narrows the list at any moment --
 * and the arrow keys move the active option, announced through
 * aria-activedescendant. These are the moves, with nothing to do with the
 * page, so they are tested on their own.
 */

/**
 * The option a key moves to.
 *
 * @param {number} current The active option; -1 for none.
 * @param {string} key     KeyboardEvent.key.
 * @param {number} count   How many options there are.
 * @param {number} page    How far PageUp and PageDown go.
 * @return {number|null} The new index, or null when the key is not a move.
 */
export function nextIndex( current, key, count, page = 10 ) {
	if ( count < 1 ) {
		return null;
	}

	const last = count - 1;
	const clamp = ( index ) => Math.max( 0, Math.min( last, index ) );

	switch ( key ) {
		case 'ArrowDown':
			return current < 0 ? 0 : clamp( current + 1 );
		case 'ArrowUp':
			return current < 0 ? last : clamp( current - 1 );
		case 'Home':
			return 0;
		case 'End':
			return last;
		case 'PageDown':
			return clamp( ( current < 0 ? 0 : current ) + page );
		case 'PageUp':
			return clamp( ( current < 0 ? 0 : current ) - page );
		default:
			return null;
	}
}

/**
 * What a key does in the search box, given what is active.
 *
 * @param {string}      key    KeyboardEvent.key.
 * @param {Object|null} active The active entry ({ type: 'folder'|'file'|'more' }), or null.
 * @param {string}      typed  What is in the search box.
 * @return {string|null} open, choose, more, up, close, or null to leave the key alone.
 */
export function keyAction( key, active, typed ) {
	if ( key === 'Escape' ) {
		return 'close';
	}

	if ( key === 'Enter' && active ) {
		if ( active.type === 'folder' ) {
			return 'open';
		}

		return active.type === 'more' ? 'more' : 'choose';
	}

	// Backspace in an empty box goes up a folder, as in a file manager.
	if ( key === 'Backspace' && typed === '' ) {
		return 'up';
	}

	return null;
}
