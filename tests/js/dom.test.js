/**
 * Escaping is the default.
 */

import { expect, test } from 'vitest';

import { escapeHtml, formatFileSize, html, raw } from '../../assets/js/lib/dom';

test( 'every value is escaped, quotes included', () => {
	const name = '"><img src=x onerror=alert(1)>';

	expect( html`<input value="${ name }">`.markup ).toBe(
		'<input value="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;">'
	);
	expect( escapeHtml( "it's" ) ).toBe( 'it&#39;s' );
} );

test( 'raw markup, nested templates and lists pass through; nothing is nothing', () => {
	const markup = html`<p>${ raw( '<b>x</b>' ) }${ html`<i>${ '<' }</i>` }${ [ 'a', '<' ] }${ false }${ null }</p>`;

	expect( markup.markup ).toBe( '<p><b>x</b><i>&lt;</i>a&lt;</p>' );
} );

test( 'sizes read as people say them', () => {
	expect( formatFileSize( 0 ) ).toBe( '0 Bytes' );
	expect( formatFileSize( 1536 ) ).toBe( '1.5 KB' );
	expect( formatFileSize( 5 * 1099511627776 ) ).toBe( '5 TB' );
} );
