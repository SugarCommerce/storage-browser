/**
 * The REST client: one table of named routes, one way to call them.
 *
 * A call site names what it wants rather than restating a path and a verb.
 * Every answer is the route's own JSON; every failure is an Error carrying
 * the route's `code`, `data` and HTTP `status`, so a caller can tell
 * `s3_upload_incomplete` from a dropped connection.
 */

import apiFetch from '@wordpress/api-fetch';

import { globalConfig, t } from './config';

/**
 * The routes, under the browser's namespace and base.
 */
export const ROUTES = {
	connectionTest: { method: 'GET', path: '/connection' },
	connectionCheck: { method: 'GET', path: '/connection/check' },
	clearCache: { method: 'DELETE', path: '/cache' },
	listBuckets: { method: 'GET', path: '/buckets' },
	bucketDetails: { method: 'GET', path: '/buckets/{bucket}' },
	browse: { method: 'GET', path: '/buckets/{bucket}/browse' },
	listObjects: { method: 'GET', path: '/buckets/{bucket}/objects' },
	deleteObject: { method: 'DELETE', path: '/buckets/{bucket}/objects' },
	renameObject: { method: 'PATCH', path: '/buckets/{bucket}/objects' },
	moveObject: { method: 'POST', path: '/buckets/{bucket}/objects/move' },
	objectReferences: {
		method: 'GET',
		path: '/buckets/{bucket}/objects/references',
	},
	downloadUrl: {
		method: 'POST',
		path: '/buckets/{bucket}/objects/download-url',
	},
	uploadUrl: { method: 'POST', path: '/buckets/{bucket}/objects/upload-url' },
	listFolders: { method: 'GET', path: '/buckets/{bucket}/folders' },
	createFolder: { method: 'POST', path: '/buckets/{bucket}/folders' },
	deleteFolder: { method: 'DELETE', path: '/buckets/{bucket}/folders' },
	setupCors: { method: 'PUT', path: '/buckets/{bucket}/cors' },
	deleteCors: { method: 'DELETE', path: '/buckets/{bucket}/cors' },
	startUpload: { method: 'POST', path: '/buckets/{bucket}/uploads' },
	uploadStatus: { method: 'GET', path: '/buckets/{bucket}/uploads/{id}' },
	signParts: { method: 'POST', path: '/buckets/{bucket}/uploads/{id}/parts' },
	completeUpload: {
		method: 'POST',
		path: '/buckets/{bucket}/uploads/{id}/complete',
	},
	abortUpload: { method: 'DELETE', path: '/buckets/{bucket}/uploads/{id}' },
};

/**
 * Turn a route and its parameters into a request.
 *
 * Placeholders are filled -- and those parameters used up -- first. GET and
 * DELETE carry what is left in the query string, because some hosts and
 * proxies drop a DELETE's body; the rest send it as JSON.
 *
 * @param {{method: string, path: string}} route The route.
 * @param {Object}                         data  Its parameters.
 * @return {{method: string, path: string, body: (Object|undefined)}} The request.
 */
export function buildRequest( route, data = {} ) {
	const params = { ...data };
	let path = route.path.replace( /\{(\w+)}/g, ( match, name ) => {
		const value = params[ name ];
		delete params[ name ];

		return encodeURIComponent( value ?? '' );
	} );

	if ( route.method === 'GET' || route.method === 'DELETE' ) {
		const query = new URLSearchParams();

		Object.keys( params ).forEach( ( name ) => {
			if ( params[ name ] !== undefined && params[ name ] !== null ) {
				query.append( name, String( params[ name ] ) );
			}
		} );

		const string = query.toString();

		if ( string ) {
			path += ( path.includes( '?' ) ? '&' : '?' ) + string;
		}

		return { method: route.method, path, body: undefined };
	}

	return { method: route.method, path, body: params };
}

/**
 * An Error from a REST failure, keeping what the route said.
 *
 * @param {Object} failure What apiFetch rejected with.
 * @return {Error} The error, with `code`, `data` and `status`.
 */
export function toError( failure ) {
	const body = failure && typeof failure === 'object' ? failure : {};
	const error = new Error(
		body.message && body.code !== 'fetch_error'
			? body.message
			: t( 'upload', 'networkError' ) || 'Network error occurred'
	);

	error.code = body.code || '';
	error.data = body.data || {};
	error.status =
		body.code === 'fetch_error' ? 0 : Number( error.data.status || 0 );

	return error;
}

/**
 * Call a named route.
 *
 * @param {string} name    A key of ROUTES.
 * @param {Object} data    Its parameters.
 * @param {Object} options base: the REST base URL; nonce: the REST nonce.
 * @return {Promise<Object>} The route's answer.
 */
export function request( name, data = {}, options = {} ) {
	const route = ROUTES[ name ];

	if ( ! route ) {
		return Promise.reject( new Error( `Unknown route: ${ name }` ) );
	}

	const config = globalConfig();
	const base = options.base || config.restUrl || '';
	const nonce = options.nonce || config.restNonce || '';
	const { method, path, body } = buildRequest( route, data );

	return apiFetch( {
		url: base + path,
		method,
		data: body,
		headers: nonce ? { 'X-WP-Nonce': nonce } : {},
	} ).then(
		( payload ) => payload || {},
		( failure ) => Promise.reject( toError( failure ) )
	);
}
