/**
 * Moving through the picker's list from the search box.
 */

import { describe, expect, test } from 'vitest';

import { keyAction, nextIndex } from '../../assets/js/picker/keyboard';

describe( 'nextIndex', () => {
	test( 'the arrows move one, and stop at the ends', () => {
		expect( nextIndex( 0, 'ArrowDown', 3 ) ).toBe( 1 );
		expect( nextIndex( 2, 'ArrowDown', 3 ) ).toBe( 2 );
		expect( nextIndex( 1, 'ArrowUp', 3 ) ).toBe( 0 );
		expect( nextIndex( 0, 'ArrowUp', 3 ) ).toBe( 0 );
	} );

	test( 'with nothing active, down is the first and up the last', () => {
		expect( nextIndex( -1, 'ArrowDown', 5 ) ).toBe( 0 );
		expect( nextIndex( -1, 'ArrowUp', 5 ) ).toBe( 4 );
	} );

	test( 'Home, End and the page keys', () => {
		expect( nextIndex( 3, 'Home', 30 ) ).toBe( 0 );
		expect( nextIndex( 3, 'End', 30 ) ).toBe( 29 );
		expect( nextIndex( 3, 'PageDown', 30 ) ).toBe( 13 );
		expect( nextIndex( 25, 'PageDown', 30 ) ).toBe( 29 );
		expect( nextIndex( 3, 'PageUp', 30 ) ).toBe( 0 );
		expect( nextIndex( 3, 'PageDown', 30, 5 ) ).toBe( 8 );
	} );

	test( 'other keys and an empty list are not moves', () => {
		expect( nextIndex( 0, 'a', 3 ) ).toBeNull();
		expect( nextIndex( 0, 'Enter', 3 ) ).toBeNull();
		expect( nextIndex( -1, 'ArrowDown', 0 ) ).toBeNull();
	} );
} );

describe( 'keyAction', () => {
	const folder = { type: 'folder' };
	const fileRow = { type: 'file' };
	const more = { type: 'more' };

	test( 'Enter opens a folder, chooses a file, loads more', () => {
		expect( keyAction( 'Enter', folder, '' ) ).toBe( 'open' );
		expect( keyAction( 'Enter', fileRow, 'mix' ) ).toBe( 'choose' );
		expect( keyAction( 'Enter', more, '' ) ).toBe( 'more' );
		expect( keyAction( 'Enter', null, '' ) ).toBeNull();
	} );

	test( 'Backspace goes up only when the box is empty', () => {
		expect( keyAction( 'Backspace', fileRow, '' ) ).toBe( 'up' );
		expect( keyAction( 'Backspace', fileRow, 'm' ) ).toBeNull();
	} );

	test( 'Escape closes', () => {
		expect( keyAction( 'Escape', null, 'anything' ) ).toBe( 'close' );
	} );
} );
