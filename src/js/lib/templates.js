/**
 * The two details dialogs' bodies.
 *
 * Every value goes through `html`, which escapes by default: bucket names,
 * CORS details and recommendations come from the provider, and a value that
 * was not escaped by omission is how markup gets in.
 */

import { __ } from '@wordpress/i18n';

import { html } from './dom';

const yes = () =>
	html`<span class="s3-status-success"
		>&#10003; ${ __( 'Yes', 'sugarcommerce' ) }</span
	>`;
const no = () =>
	html`<span class="s3-status-error"
		>&#10007; ${ __( 'No', 'sugarcommerce' ) }</span
	>`;

/**
 * One label and value row.
 *
 * @param {string} label The label.
 * @param {*}      value The value: text, or `html` output.
 * @return {Object} Markup.
 */
const row = ( label, value ) =>
	html`<tr>
		<td><strong>${ label }</strong></td>
		<td>${ value }</td>
	</tr>`;

/**
 * A titled section holding a table.
 *
 * @param {string} title The heading.
 * @param {*}      rows  Its rows.
 * @return {Object} Markup.
 */
const section = ( title, rows ) =>
	html`<div class="s3-details-section">
		<h4>${ title }</h4>
		<table class="s3-details-table">
			${ rows }
		</table>
	</div>`;

/**
 * The bucket details dialog.
 *
 * @param {Object} data The bucket details route's answer, with bucket and providerName.
 * @return {Object} Markup.
 */
export function bucketDetails( data ) {
	const basic = data.basic || {};
	const cors = data.cors || null;
	const analysis = cors && cors.analysis ? cors.analysis : null;
	const permissions = data.permissions || null;
	const recommendations = ( analysis && analysis.recommendations ) || [];
	const warnings = ( analysis && analysis.security_warnings ) || [];

	return html`
		<div class="s3-bucket-details-content">
			${ section( __( 'Bucket Information', 'sugarcommerce' ), [
				row(
					__( 'Bucket Name', 'sugarcommerce' ),
					html`<code>${ data.bucket }</code>`
				),
				basic.region &&
					row( __( 'Region', 'sugarcommerce' ), basic.region ),
				basic.created &&
					row( __( 'Created', 'sugarcommerce' ), basic.created ),
				row( __( 'Provider', 'sugarcommerce' ), data.providerName ),
			] ) }
			${
				cors &&
				section( __( 'Upload Capability', 'sugarcommerce' ), [
					row(
						__( 'Upload Ready', 'sugarcommerce' ),
						cors.upload_ready ? yes() : no()
					),
					row(
						__( 'Current Domain', 'sugarcommerce' ),
						cors.current_origin
					),
					cors.details &&
						html`<tr>
							<td colspan="2">
								<small>${ cors.details }</small>
							</td>
						</tr>`,
				] )
			}
			${
				analysis &&
				section( __( 'CORS Configuration', 'sugarcommerce' ), [
					row(
						__( 'Has CORS', 'sugarcommerce' ),
						analysis.has_cors
							? __( 'Yes', 'sugarcommerce' )
							: __( 'No', 'sugarcommerce' )
					),
					analysis.has_cors &&
						row(
							__( 'Rules', 'sugarcommerce' ),
							analysis.rules_count || 0
						),
					analysis.has_cors &&
						warnings.length > 0 &&
						row(
							__( 'Security Warnings', 'sugarcommerce' ),
							html`<span class="s3-status-warning"
								>${ warnings.length }</span
							>`
						),
				] )
			}
			${
				permissions &&
				section( __( 'Permissions', 'sugarcommerce' ), [
					row(
						__( 'Read Access', 'sugarcommerce' ),
						permissions.read ? yes() : no()
					),
					row(
						__( 'Write Access', 'sugarcommerce' ),
						permissions.write ? yes() : no()
					),
					row(
						__( 'Delete Access', 'sugarcommerce' ),
						permissions.delete ? yes() : no()
					),
				] )
			}
			${
				recommendations.length > 0 &&
				html`<div class="s3-details-section">
					<h4>${ __( 'Recommendations', 'sugarcommerce' ) }</h4>
					<ul class="s3-recommendations-list">
						${ recommendations.map( ( rec ) => html`<li>${ rec }</li>` ) }
					</ul>
				</div>`
			}
		</div>
	`;
}

/**
 * The file details dialog.
 *
 * @param {Object} data What the row carries: filename, key, sizes, dates, etag, storage class, parts.
 * @return {Object} Markup.
 */
export function fileDetails( data ) {
	return html`
		<div class="s3-details-content">
			${ section( __( 'Basic Information', 'sugarcommerce' ), [
				row( __( 'Filename', 'sugarcommerce' ), data.filename ),
				row(
					__( 'Object Key', 'sugarcommerce' ),
					html`<code>${ data.key }</code>`
				),
				row( __( 'Size', 'sugarcommerce' ), data.sizeFormatted ),
				row(
					__( 'Last Modified', 'sugarcommerce' ),
					data.modifiedFormatted
				),
				row( __( 'MIME Type', 'sugarcommerce' ), data.mimeType ),
				row( __( 'Category', 'sugarcommerce' ), data.category ),
			] ) }
			${ section( __( 'Storage Information', 'sugarcommerce' ), [
				row(
					__( 'Storage Class', 'sugarcommerce' ),
					data.storageClass
				),
				row(
					__( 'ETag', 'sugarcommerce' ),
					html`<code>${ data.etag }</code>`
				),
				row(
					__( 'Upload Type', 'sugarcommerce' ),
					data.isMultipart
						? html`${ __( 'Multipart', 'sugarcommerce' ) }${ data.partCount ? ` (${ data.partCount })` : '' }`
						: __( 'Single part', 'sugarcommerce' )
				),
			] ) }
		</div>
	`;
}
