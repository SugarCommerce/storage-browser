/**
 * A page hears only its own browser.
 */

import { afterEach, expect, test, vi } from 'vitest';

import {
	comparablePath,
	frameUrl,
	listenForInserts,
} from '../../assets/js/lib/host-page';

afterEach( () => {
	delete window.S3BrowserGlobalConfig;
} );

const post = ( data ) =>
	window.dispatchEvent( new MessageEvent( 'message', { data } ) );

test( 'an insert with the page token is carried out; any other is ignored', () => {
	window.S3BrowserGlobalConfig = { insertToken: 'secret' };

	const insert = vi.fn();
	const stop = listenForInserts( insert );

	post( {
		type: 's3-browser:insert',
		token: 'wrong',
		files: [ { key: 'a' } ],
	} );
	post( { type: 's3-browser:insert', files: [ { key: 'a' } ] } );
	post( { type: 'other', token: 'secret', files: [ { key: 'a' } ] } );
	post( {
		type: 's3-browser:insert',
		token: 'secret',
		files: [ { key: 'a' } ],
	} );
	stop();
	post( {
		type: 's3-browser:insert',
		token: 'secret',
		files: [ { key: 'b' } ],
	} );

	expect( insert ).toHaveBeenCalledTimes( 1 );
	expect( insert ).toHaveBeenCalledWith( [ { key: 'a' } ] );
} );

test( 'a page with no token hears nobody', () => {
	window.S3BrowserGlobalConfig = {};

	const insert = vi.fn();
	listenForInserts( insert );
	post( { type: 's3-browser:insert', token: '', files: [] } );

	expect( insert ).not.toHaveBeenCalled();
} );

test( 'stored paths compare without the scheme or outer slashes', () => {
	expect( comparablePath( ' s3://files/a.zip/ ' ) ).toBe( 'files/a.zip' );
	expect( comparablePath( 'files/a.zip' ) ).toBe( 'files/a.zip' );
} );

test( 'the frame opens on the default bucket, or on the bucket list', () => {
	expect(
		frameUrl(
			{
				baseUrl: '/wp-admin/media-upload.php',
				providerId: 'r2_shop',
				defaultBucket: 'files',
			},
			{ s3_host: '1' }
		)
	).toBe(
		'/wp-admin/media-upload.php?chromeless=1&tab=s3_r2_shop&bucket=files&view=objects&s3_host=1'
	);
	expect( frameUrl( { baseUrl: '/m', providerId: 'x' } ) ).toBe(
		'/m?chromeless=1&tab=s3_x&view=buckets'
	);
} );
