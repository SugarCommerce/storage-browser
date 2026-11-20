/**
 * The browser's dialogs from the keyboard.
 */

import { afterEach, expect, test, vi } from 'vitest';

import { html } from '../../assets/js/lib/dom';
import { closeModal, openModal } from '../../assets/js/lib/modal';

afterEach( async () => {
	await closeModal( 'm' );
	document.body.innerHTML = '';
} );

const key = ( name, shiftKey = false ) =>
	document.dispatchEvent(
		new KeyboardEvent( 'keydown', {
			key: name,
			shiftKey,
			bubbles: true,
			cancelable: true,
		} )
	);

test( 'a dialog is labelled by its title and takes focus at its first field', () => {
	const overlay = openModal( {
		id: 'm',
		title: 'Rename',
		content: html`<input id="field">`,
		buttons: [ { text: 'Go', action: 'go' } ],
	} );
	const dialog = overlay.querySelector( '[role="dialog"]' );

	expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' );
	expect(
		document.getElementById( dialog.getAttribute( 'aria-labelledby' ) )
			.textContent
	).toBe( 'Rename' );
	expect( document.activeElement.id ).toBe( 'field' );
} );

test( 'with no field, focus goes to the first button, and the title is text', () => {
	const overlay = openModal( {
		id: 'm',
		title: '<img src=x onerror=alert(1)>',
		content: html`<p>Sure?</p>`,
		buttons: [
			{ text: 'Yes', action: 'yes' },
			{ text: 'No', action: 'no' },
		],
	} );

	expect( document.activeElement.dataset.action ).toBe( 'yes' );
	expect( overlay.querySelector( 'img' ) ).toBeNull();
} );

test( 'Escape closes and hands focus back to the opener', async () => {
	const opener = document.createElement( 'button' );
	document.body.append( opener );
	opener.focus();

	openModal( {
		id: 'm',
		title: 'T',
		content: html``,
		buttons: [ { text: 'Ok', action: 'ok' } ],
	} );
	key( 'Escape' );
	await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );

	expect( document.getElementById( 'm' ) ).toBeNull();
	expect( document.activeElement ).toBe( opener );
} );

test( 'a button runs its callback; a disabled one does not', () => {
	const go = vi.fn();
	const overlay = openModal( {
		id: 'm',
		title: 'T',
		content: html``,
		buttons: [ { text: 'Go', action: 'go', callback: go } ],
	} );
	const button = overlay.querySelector( '[data-action="go"]' );

	button.click();
	button.disabled = true;
	button.click();

	expect( go ).toHaveBeenCalledTimes( 1 );
} );
