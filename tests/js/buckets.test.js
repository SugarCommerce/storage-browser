/**
 * The CORS rule in each provider's own shape.
 */

import { expect, test } from 'vitest';

import { corsRuleFor } from '../../assets/js/lib/buckets';

const where = { r2: 'In Cloudflare: {bucket}', default: 'Anywhere' };

test( "R2's console takes a list with no ID", () => {
	const rule = corsRuleFor( 'r2', 'https://shop.test', where );

	expect( rule.where ).toBe( 'In Cloudflare: {bucket}' );
	expect( JSON.parse( rule.rule ) ).toEqual( [
		{
			AllowedOrigins: [ 'https://shop.test' ],
			AllowedMethods: [ 'PUT' ],
			AllowedHeaders: [ 'Content-Type', 'Content-Length' ],
			MaxAgeSeconds: 3600,
		},
	] );
} );

test( 'DigitalOcean gets the form, not JSON; Backblaze its own keys', () => {
	expect(
		corsRuleFor( 'digitalocean', 'https://shop.test', where ).rule
	).toContain( 'Origin: https://shop.test' );
	expect(
		JSON.parse(
			corsRuleFor( 'backblaze', 'https://shop.test', where ).rule
		)[ 0 ].allowedOperations
	).toEqual( [ 's3_put' ] );
} );

test( 'anything else gets the PutBucketCors document and the default sentence', () => {
	const rule = corsRuleFor( 'minio', 'https://shop.test', where );

	expect( rule.where ).toBe( 'Anywhere' );
	expect( JSON.parse( rule.rule ).CORSRules[ 0 ].ID ).toBe(
		'UploadFromBrowser'
	);
} );
