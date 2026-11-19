/**
 * What the picker shows, worked out from what the server listed.
 */

/**
 * The folders above and including a prefix, for the breadcrumb.
 *
 * @param {string} prefix A folder: "packs/drums/".
 * @return {Array<{name: string, prefix: string}>} [ { name: 'packs', prefix: 'packs/' }, … ].
 */
export function crumbs( prefix ) {
	const parts = String( prefix || '' )
		.split( '/' )
		.filter( Boolean );

	return parts.map( ( name, index ) => ( {
		name,
		prefix: parts.slice( 0, index + 1 ).join( '/' ) + '/',
	} ) );
}

/**
 * The folder above a prefix.
 *
 * @param {string} prefix A folder.
 * @return {string} Its parent; '' at the top.
 */
export function parentOf( prefix ) {
	const trail = crumbs( prefix );

	return trail.length > 1 ? trail[ trail.length - 2 ].prefix : '';
}

/**
 * Whether a file's name has one of the extensions asked for.
 *
 * @param {string}   name   The file's name.
 * @param {string[]} accept Extensions, without dots; empty for anything.
 * @return {boolean} Whether it may be chosen.
 */
export function accepts( name, accept ) {
	if ( ! accept || ! accept.length ) {
		return true;
	}

	const dot = name.lastIndexOf( '.' );
	const extension = dot > 0 ? name.slice( dot + 1 ).toLowerCase() : '';

	return accept
		.map( ( item ) => String( item ).replace( /^\./, '' ).toLowerCase() )
		.includes( extension );
}

/**
 * The rows to show: folders first, then files, narrowed to what may be
 * chosen and -- while the server's answer to a search is on its way -- to
 * what the typed words already match.
 *
 * @param {Object}   listing        The browse route's answer: folders, objects.
 * @param {Object}   options        What to keep.
 * @param {string[]} options.accept Extensions that may be chosen.
 * @param {string}   options.term   Words to match locally.
 * @return {Array<Object>} { type, name, prefix } for a folder, { type, name, key, size, … } for a file.
 */
export function entries( listing, { accept = [], term = '' } = {} ) {
	const needle = term.trim().toLowerCase();
	const matches = ( name ) =>
		! needle || name.toLowerCase().includes( needle );

	return [
		...( listing.folders || [] )
			.filter( ( folder ) => matches( folder.name ) )
			.map( ( folder ) => ( { type: 'folder', ...folder } ) ),
		...( listing.objects || [] )
			.filter(
				( object ) =>
					accepts( object.name, accept ) && matches( object.key )
			)
			.map( ( object ) => ( { type: 'file', ...object } ) ),
	];
}

/**
 * What the picker hands back for a chosen file.
 *
 * @param {string} bucket The bucket.
 * @param {Object} file   A file entry.
 * @return {{bucket: string, key: string, name: string, size: number, mimeType: string, address: string}} The choice.
 */
export function choice( bucket, file ) {
	return {
		bucket,
		key: file.key,
		name: file.name,
		size: Number( file.size || 0 ),
		mimeType: file.mime_type || '',
		address: file.address || `s3://${ bucket }/${ file.key }`,
	};
}

/**
 * The value a field receives for a choice.
 *
 * @param {Object} chosen  choice().
 * @param {string} returns address (s3://bucket/key), path (bucket/key) or key.
 * @return {string} The value.
 */
export function valueFor( chosen, returns = 'address' ) {
	switch ( returns ) {
		case 'key':
			return chosen.key;
		case 'path':
			return `${ chosen.bucket }/${ chosen.key }`;
		default:
			return chosen.address;
	}
}
