/**
 * Turning a named route into a request.
 */

import { describe, expect, test } from 'vitest';

import { buildRequest, ROUTES, toError } from '../../src/js/lib/api';

describe( 'buildRequest', () => {
	test( 'placeholders are filled, encoded, and used up', () => {
		expect(
			buildRequest( ROUTES.signParts, {
				bucket: 'my bucket',
				id: 'abc',
				parts: [ 1, 2 ],
			} )
		).toEqual( {
			method: 'POST',
			path: '/buckets/my%20bucket/uploads/abc/parts',
			body: { parts: [ 1, 2 ] },
		} );
	} );

	test( 'GET and DELETE carry what is left in the query string', () => {
		expect(
			buildRequest( ROUTES.deleteObject, {
				bucket: 'b',
				key: 'a/b c.zip',
			} )
		).toEqual( {
			method: 'DELETE',
			path: '/buckets/b/objects?key=a%2Fb+c.zip',
			body: undefined,
		} );
		expect(
			buildRequest( ROUTES.browse, {
				bucket: 'b',
				prefix: '',
				search: undefined,
			} ).path
		).toBe( '/buckets/b/browse?prefix=' );
	} );
} );

describe( 'toError', () => {
	test( "a route's refusal keeps its code, data and status", () => {
		const error = toError( {
			code: 's3_upload_incomplete',
			message: 'missing',
			data: { status: 409, missing: [ 2 ] },
		} );

		expect( error.message ).toBe( 'missing' );
		expect( error.code ).toBe( 's3_upload_incomplete' );
		expect( error.status ).toBe( 409 );
		expect( error.data.missing ).toEqual( [ 2 ] );
	} );

	test( 'no answer at all is status 0', () => {
		expect(
			toError( {
				code: 'fetch_error',
				message: 'You are probably offline.',
			} ).status
		).toBe( 0 );
	} );
} );
