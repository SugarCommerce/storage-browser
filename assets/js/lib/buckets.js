/**
 * A bucket's details, and the CORS rule uploads from this site need.
 */

/* eslint-disable no-alert -- confirm() and prompt() are how the browser has always asked. */

import { request } from './api';
import { globalConfig, t } from './config';
import { html, notify, on, reloadSoon } from './dom';
import { closeModal, hideProgress, openModal, showProgress } from './modal';
import { bucketDetails } from './templates';

/**
 * The upload rule, in the shape one provider's console takes.
 *
 * There is one rule -- PUT from this site, two headers, an hour -- and no
 * one way to write it down. S3's console wants a list of rules; so does
 * Cloudflare's, which refuses the ID S3 allows; Backblaze and Google have
 * key names of their own; DigitalOcean has a form and takes no JSON at all.
 * Everything else speaks the S3 API, so it gets PutBucketCors's document.
 *
 * @param {string} type   Provider type, e.g. 'r2'.
 * @param {string} origin This site's origin.
 * @param {Object} where  Where-to-put-it sentences, by provider; `default` for the rest.
 * @return {{where: string, rule: string}} The sentence and the rule.
 */
export function corsRuleFor( type, origin, where ) {
	const headers = [ 'Content-Type', 'Content-Length' ];
	const json = ( value ) => JSON.stringify( value, null, 2 );
	const sentence = ( key ) =>
		Object.prototype.hasOwnProperty.call( where, key )
			? where[ key ]
			: where.default || '';

	switch ( type ) {
		case 'aws':
			return {
				where: sentence( 'aws' ),
				rule: json( [
					{
						ID: 'UploadFromBrowser',
						AllowedOrigins: [ origin ],
						AllowedMethods: [ 'PUT' ],
						AllowedHeaders: headers,
						ExposeHeaders: [],
						MaxAgeSeconds: 3600,
					},
				] ),
			};

		case 'r2':
			return {
				where: sentence( 'r2' ),
				rule: json( [
					{
						AllowedOrigins: [ origin ],
						AllowedMethods: [ 'PUT' ],
						AllowedHeaders: headers,
						MaxAgeSeconds: 3600,
					},
				] ),
			};

		case 'backblaze':
			return {
				where: sentence( 'backblaze' ),
				rule: json( [
					{
						corsRuleName: 'uploadFromBrowser',
						allowedOrigins: [ origin ],
						allowedOperations: [ 's3_put' ],
						allowedHeaders: [ 'content-type', 'content-length' ],
						exposeHeaders: [],
						maxAgeSeconds: 3600,
					},
				] ),
			};

		case 'gcs':
			return {
				where: sentence( 'gcs' ),
				rule: json( [
					{
						origin: [ origin ],
						method: [ 'PUT' ],
						responseHeader: headers,
						maxAgeSeconds: 3600,
					},
				] ),
			};

		case 'digitalocean':
			return {
				where: sentence( 'digitalocean' ),
				rule: [
					`Origin: ${ origin }`,
					'Allowed Methods: PUT',
					`Allowed Headers: ${ headers.join( ', ' ) }`,
					'Access Control Max Age: 3600',
				].join( '\n' ),
			};

		default:
			return {
				where: sentence( 'default' ),
				rule: json( {
					CORSRules: [
						{
							ID: 'UploadFromBrowser',
							AllowedOrigins: [ origin ],
							AllowedMethods: [ 'PUT' ],
							AllowedHeaders: headers,
							MaxAgeSeconds: 3600,
						},
					],
				} ),
			};
	}
}

/**
 * The manual setup dialog, for when the rule could not be set from here.
 *
 * @param {string} bucket The bucket.
 * @param {string} reason Why setting it failed.
 */
function manualSetup( bucket, reason ) {
	const s = ( key ) => t( 'buckets', key );
	const config = globalConfig();
	const cors = corsRuleFor( config.providerType, window.location.origin, {
		aws: s( 'corsWhereAws' ),
		r2: s( 'corsWhereR2' ),
		backblaze: s( 'corsWhereBackblaze' ),
		gcs: s( 'corsWhereGcs' ),
		digitalocean: s( 'corsWhereDigitalocean' ),
		default: s( 'corsWhereDefault' ),
	} );

	openModal( {
		id: 's3CORSManualSetupModal',
		title: s( 'manualCorsSetup' ),
		content: html`
			<div class="s3-cors-setup-content">
				<p>
					<strong>${ s( 'autoSetupFailed' ) }</strong> ${ s(
						'manualSetupInstruction'
					).replace(
						'{provider}',
						config.providerName || s( 's3CompatibleProvider' )
					) }
				</p>
				${ reason && html`<p class="s3-cors-setup-reason"><code>${ reason }</code></p>` }
				<div class="s3-cors-setup-details">
					<h4>${ s( 'requiredCorsConfig' ) }</h4>
					<p>${ cors.where.replace( '{bucket}', bucket ) }</p>
					<textarea
						readonly
						rows="10"
						class="s3-cors-config-textarea"
					>
${ cors.rule }</textarea>
				</div>
				<div class="s3-cors-setup-details">
					<h4>${ s( 'whatRuleDoes' ) }</h4>
					<ul class="s3-cors-rule-list">
						<li>
							<strong>${ s( 'putMethodOnly' ) }</strong>
							${ s( 'putMethodDesc' ) }
						</li>
						<li>
							<strong>${ s( 'minimalHeaders' ) }</strong>
							${ s( 'minimalHeadersDesc' ) }
						</li>
						<li>
							<strong>${ s( 'singleOrigin' ) }</strong>
							${ s( 'singleOriginDesc' ) }
						</li>
						<li>
							<strong>${ s( 'oneHourCache' ) }</strong>
							${ s( 'oneHourCacheDesc' ) }
						</li>
					</ul>
				</div>
				<div class="s3-cors-setup-warning">
					<p>
						<strong>${ s( 'note' ) }</strong>
						${ s( 'configOptimized' ) }
					</p>
				</div>
			</div>
		`,
		buttons: [
			{
				text: t( 'ui', 'close' ),
				action: 'close',
				callback: () => closeModal( 's3CORSManualSetupModal' ),
			},
			{
				text: s( 'refreshPage' ),
				action: 'refresh',
				classes: 'button-primary',
				callback: () => window.location.reload(),
			},
		],
	} );
}

/**
 * Set the upload rule on the bucket, after asking.
 *
 * @param {string} bucket The bucket.
 */
function setupCors( bucket ) {
	const s = ( key ) => t( 'buckets', key );
	const question = s( 'corsSetupConfirm' )
		.replace( '{bucket}', bucket )
		.replace( '{origin}', window.location.origin );

	if ( ! window.confirm( question ) ) {
		return;
	}

	showProgress( s( 'settingUpCors' ) );

	request( 'setupCors', { bucket, origin: window.location.origin } )
		.then( ( answer ) => {
			hideProgress();
			notify(
				answer.message ||
					s( 'corsSetupSuccess' ).replace( '{bucket}', bucket ),
				'success'
			);
			reloadSoon( 2000 );
		} )
		.catch( ( error ) => {
			hideProgress();
			notify(
				s( 'corsSetupError' ).replace( '{message}', error.message ),
				'error'
			);
			setTimeout( () => manualSetup( bucket, error.message ), 1000 );
		} );
}

/**
 * Take the bucket's CORS rules away, after asking.
 *
 * @param {string} bucket The bucket.
 */
function revokeCors( bucket ) {
	const s = ( key ) => t( 'buckets', key );

	if (
		! window.confirm( s( 'revokeConfirm' ).replace( '{bucket}', bucket ) )
	) {
		return;
	}

	showProgress( s( 'revokingCors' ) );

	request( 'deleteCors', { bucket } )
		.then( () => {
			hideProgress();
			notify(
				s( 'revokeSuccess' ).replace( '{bucket}', bucket ),
				'success'
			);
			reloadSoon( 2000 );
		} )
		.catch( ( error ) => {
			hideProgress();
			notify(
				s( 'revokeError' ).replace( '{message}', error.message ),
				'error'
			);
		} );
}

/**
 * Load and show a bucket's details.
 *
 * @param {string}   bucket   The bucket.
 * @param {string}   provider The provider id.
 * @param {Function} browse   Opens the bucket.
 */
function showDetails( bucket, provider, browse ) {
	const s = ( key ) => t( 'buckets', key );
	const id = 's3BucketDetailsModal';

	showProgress( s( 'loadingDetails' ) );

	request( 'bucketDetails', {
		bucket,
		provider: provider || globalConfig().providerId,
		current_origin: window.location.origin,
	} )
		.then( ( data ) => {
			hideProgress( true );

			const cors = data.cors || {};
			const later = ( run ) => () => closeModal( id ).then( run );
			const buttons = [
				{
					text: s( 'browseBucket' ),
					action: 'browse',
					classes: 'button-primary',
					callback: later( () => browse( bucket ) ),
				},
			];

			if ( cors.analysis && cors.analysis.has_cors ) {
				buttons.push( {
					text: s( 'revokeCorsRules' ),
					action: 'revoke_cors',
					classes: 'button-secondary button-destructive',
					callback: later( () => revokeCors( bucket ) ),
				} );
			}

			if ( ! cors.upload_ready ) {
				buttons.push( {
					text: t( 'cors', 'corsSetup' ),
					action: 'setup_cors',
					classes: 'button-primary',
					callback: later( () => setupCors( bucket ) ),
				} );
			}

			buttons.push( {
				text: t( 'ui', 'close' ),
				action: 'close',
				callback: () => closeModal( id ),
			} );

			openModal( {
				id,
				title: s( 'detailsTitle' ).replace( '{bucket}', bucket ),
				content: bucketDetails( {
					...data,
					bucket,
					providerName: globalConfig().providerName || '',
				} ),
				buttons,
			} );
		} )
		.catch( ( error ) => {
			hideProgress();
			notify(
				s( 'loadDetailsError' ).replace( '{message}', error.message ),
				'error'
			);
		} );
}

/**
 * Wire the bucket actions up.
 *
 * @param {Function} browse Opens a bucket.
 */
export function initBuckets( browse ) {
	on( document, 'click', '.s3-bucket-details', ( event, button ) => {
		event.preventDefault();
		event.stopPropagation();

		if ( button.dataset.bucket ) {
			showDetails(
				button.dataset.bucket,
				button.dataset.provider,
				browse
			);
		}
	} );
}
