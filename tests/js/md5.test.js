/**
 * MD5 against RFC 1321 and Node's own.
 */

import { createHash, randomBytes } from 'node:crypto';
import { expect, test } from 'vitest';

import { md5Base64, md5Hex } from '../../src/js/lib/md5';

const bytes = ( text ) => new TextEncoder().encode( text );

test( 'md5 matches the RFC 1321 vectors', () => {
	expect( md5Hex( bytes( '' ) ) ).toBe( 'd41d8cd98f00b204e9800998ecf8427e' );
	expect( md5Hex( bytes( 'a' ) ) ).toBe( '0cc175b9c0f1b6a831c399e269772661' );
	expect( md5Hex( bytes( 'abc' ) ) ).toBe(
		'900150983cd24fb0d6963f7d28e17f72'
	);
	expect( md5Hex( bytes( 'message digest' ) ) ).toBe(
		'f96b697d7cb7938d525a2f31aaf161d0'
	);
	expect(
		md5Hex(
			bytes(
				'12345678901234567890123456789012345678901234567890123456789012345678901234567890'
			)
		)
	).toBe( '57edf4a22be3c955ac49da2e2107b67a' );
} );

test( 'md5 agrees with Node at every padding boundary and beyond', () => {
	[
		0, 1, 55, 56, 57, 63, 64, 65, 119, 120, 127, 128, 129, 1000, 65536,
		1048577,
	].forEach( ( size ) => {
		const data = randomBytes( size );

		expect(
			md5Base64(
				data.buffer.slice( data.byteOffset, data.byteOffset + size )
			)
		).toBe( createHash( 'md5' ).update( data ).digest( 'base64' ) );
	} );
} );
