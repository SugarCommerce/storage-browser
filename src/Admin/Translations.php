<?php
/**
 * Browser Translations
 *
 * The strings the browser's JavaScript needs, grouped the way it reads them:
 * its configuration's `i18n.<group>.<key>` (assets/js/lib/config.js).
 *
 * @package     SugarCommerce\StorageBrowser\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\StorageBrowser\Admin;

/**
 * Class Translations
 */
class Translations {

	/**
	 * Build the translation table.
	 *
	 * Called once per page load that enqueues the browser, so the strings are
	 * assembled on demand rather than held as a constant -- __() needs the
	 * text domain loaded, which it is not at class-definition time.
	 *
	 * @return array Strings grouped by area.
	 */
	public static function all(): array {
		return [
			'ui' => [
				'uploadFiles'    => __( 'Upload Files', 'sugarcommerce' ),
				'or'             => __( 'or', 'sugarcommerce' ),
				'waitForUploads' => __( 'Please wait for uploads to complete before closing', 'sugarcommerce' ),
				'cancel'         => __( 'Cancel', 'sugarcommerce' ),
				'close'          => __( 'Close', 'sugarcommerce' ),
				'deleting'       => __( 'Deleting...', 'sugarcommerce' ),
				'refreshing'     => __( 'Refreshing...', 'sugarcommerce' ),
			],
			'files' => [
				'confirmDelete'    => implode( "\n\n", [
					__( 'Are you sure you want to delete "{filename}"?', 'sugarcommerce' ),
					__( 'This action cannot be undone.', 'sugarcommerce' ),
				] ),
				'deleteSuccess'    => __( 'File successfully deleted', 'sugarcommerce' ),
				'deleteBreaksOne'  => __( 'One product sells this file. Deleting it will break that download for everyone who has already bought it.', 'sugarcommerce' ),
				/* translators: %d: number of products selling the file */
				'deleteBreaksMany' => __( '%d products sell this file. Deleting it will break those downloads for everyone who has already bought them.', 'sugarcommerce' ),
				'renameFile'       => __( 'Rename File', 'sugarcommerce' ),
				'moveFile'         => __( 'Move File', 'sugarcommerce' ),
				'insertUnavailable' => __( 'Could not add the file to this page automatically. Copy the path below and paste it into the file field.', 'sugarcommerce' ),
				'oneSelected'      => __( '1 file selected', 'sugarcommerce' ),
				/* translators: %d: number of files selected */
				'manySelected'     => __( '%d files selected', 'sugarcommerce' ),
				/* translators: %1$d: number inserted, %2$d: number selected */
				'moveTo'           => __( 'Choose a folder to move this file into:', 'sugarcommerce' ),
				'moveRoot'         => __( 'Bucket root', 'sugarcommerce' ),
				'moveHere'         => __( 'Move here', 'sugarcommerce' ),
				'movingFile'       => __( 'Moving file...', 'sugarcommerce' ),
				'moveSuccess'      => __( 'File moved successfully', 'sugarcommerce' ),
				'loadingFolders'   => __( 'Loading folders...', 'sugarcommerce' ),
				'noSubfolders'     => __( 'No folders here', 'sugarcommerce' ),
				'filenameLabel'    => __( 'Enter the new filename:', 'sugarcommerce' ),
				'filenameHelp'     => __( 'Enter a new filename. The file extension will be preserved.', 'sugarcommerce' ),
				'renameSuccess'    => __( 'File renamed successfully', 'sugarcommerce' ),
				'renamingFile'     => __( 'Renaming file...', 'sugarcommerce' ),
				'filenameRequired' => __( 'Filename is required', 'sugarcommerce' ),
				'filenameInvalid'  => __( 'Filename contains invalid characters', 'sugarcommerce' ),
				'filenameTooLong'  => __( 'Filename is too long', 'sugarcommerce' ),
				'filenameSame'     => __( 'The new filename is the same as the current filename', 'sugarcommerce' ),
				'referencedByOne'  => __( 'One product links to this file', 'sugarcommerce' ),
				/* translators: %d: number of products linking to the file */
				'referencedByMany' => __( '%d products link to this file', 'sugarcommerce' ),
				'referencesUpdated' => __( 'Their links will be updated to the new name automatically.', 'sugarcommerce' ),
			],
			'folders' => [
				'newFolder'                 => __( 'New Folder', 'sugarcommerce' ),
				'createFolder'              => __( 'Create Folder', 'sugarcommerce' ),
				'folderName'                => __( 'Folder Name', 'sugarcommerce' ),
				'folderNamePlaceholder'     => __( 'Enter folder name', 'sugarcommerce' ),
				'folderNameHelp'            => __( 'Enter a name for the new folder. Use only letters, numbers, spaces, dots, hyphens, and underscores.', 'sugarcommerce' ),
				'createFolderSuccess'       => __( 'Folder "{name}" created successfully', 'sugarcommerce' ),
				'creatingFolder'            => __( 'Creating folder...', 'sugarcommerce' ),
				'folderNameRequired'        => __( 'Folder name is required', 'sugarcommerce' ),
				'folderNameTooLong'         => __( 'Folder name cannot exceed 63 characters', 'sugarcommerce' ),
				'folderNameInvalidChars'    => __( 'Folder name can only contain letters, numbers, spaces, dots, hyphens, and underscores', 'sugarcommerce' ),
				'folderNameStartEnd'        => __( 'Folder name cannot start or end with dots or hyphens', 'sugarcommerce' ),
				'folderNameConsecutiveDots' => __( 'Folder name cannot contain consecutive dots', 'sugarcommerce' ),
				'confirmDeleteFolder'       => implode( "\n\n", [
					__( 'Are you sure you want to delete the folder "{foldername}" and all its contents?', 'sugarcommerce' ),
					__( 'This action cannot be undone.', 'sugarcommerce' ),
				] ),
				'deleteFolderSuccess'       => __( 'Folder successfully deleted', 'sugarcommerce' ),
				'deletingFolderProgress'    => __( 'Deleting folder "{name}"...', 'sugarcommerce' ),
				'folderDeletedSuccess'      => __( 'Folder deleted successfully!', 'sugarcommerce' ),
				'opening'                   => __( 'Opening...', 'sugarcommerce' ),
				'folderOpenError'           => __( 'Failed to open folder', 'sugarcommerce' ),
			],
			'buckets' => [
				// Modal titles and actions
				'detailsTitle'           => __( 'Bucket Details: {bucket}', 'sugarcommerce' ),
				'browseBucket'           => __( 'Browse Bucket', 'sugarcommerce' ),
				'revokeCorsRules'        => __( 'Revoke CORS Rules', 'sugarcommerce' ),
				'loadingDetails'         => __( 'Loading bucket details...', 'sugarcommerce' ),
				'loadDetailsError'       => __( 'Failed to load bucket details: {message}', 'sugarcommerce' ),
				'manualCorsSetup'        => __( 'Manual CORS Setup Instructions', 'sugarcommerce' ),
				'refreshPage'            => __( 'Refresh Page', 'sugarcommerce' ),

				// Basic information
				'region'                 => __( 'Region:', 'sugarcommerce' ),
				'created'                => __( 'Created:', 'sugarcommerce' ),
				'provider'               => __( 'Provider:', 'sugarcommerce' ),
				'uploadReady'            => __( 'Upload Ready:', 'sugarcommerce' ),
				'yes'                    => __( 'Yes', 'sugarcommerce' ),
				'no'                     => __( 'No', 'sugarcommerce' ),

				// CORS configuration
				'permissions'            => __( 'Permissions', 'sugarcommerce' ),
				'recommendations'        => __( 'Recommendations', 'sugarcommerce' ),

				// CORS setup process
				'corsSetupConfirm'       => implode( "\n", [
					__( 'Set up CORS (Cross-Origin Resource Sharing) for bucket "{bucket}"?', 'sugarcommerce' ),
					'',
					__( 'This will:', 'sugarcommerce' ),
					__( '• Enable file uploads from web browsers', 'sugarcommerce' ),
					__( '• Allow cross-origin access from this domain: {origin}', 'sugarcommerce' ),
					__( '• Configure secure upload permissions', 'sugarcommerce' ),
					'',
					__( 'This is required for uploads to work.', 'sugarcommerce' ),
				] ),
				'settingUpCors'          => __( 'Setting up CORS configuration...', 'sugarcommerce' ),
				'corsSetupSuccess'       => __( 'CORS successfully configured for bucket "{bucket}"', 'sugarcommerce' ),
				'corsSetupError'         => __( 'Failed to setup CORS: {message}', 'sugarcommerce' ),

				// Manual CORS setup
				's3CompatibleProvider'   => __( 'S3 Compatible Provider', 'sugarcommerce' ),
				'autoSetupFailed'        => __( 'CORS needs to be set up in your provider\'s console.', 'sugarcommerce' ),
				'manualSetupInstruction' => __( 'You can set up CORS manually through your {provider} console or API.', 'sugarcommerce' ),
				'requiredCorsConfig'     => __( 'Required CORS Configuration:', 'sugarcommerce' ),
				'addCorsRule'            => __( 'Add this minimal CORS rule to bucket {bucket}:', 'sugarcommerce' ),

				// Where the rule goes, provider by provider. One sentence
				// each, since the rule printed under it is already in that
				// provider's own shape. Flat keys: every entry here is a
				// string, which is what the script and the tests rely on.
				'corsWhereAws' => __( 'In the S3 console open the bucket, then Permissions → Cross-origin resource sharing (CORS) → Edit, and paste:', 'sugarcommerce' ),
				'corsWhereR2' => __( 'In the Cloudflare dashboard open R2 → {bucket} → Settings → CORS Policy → Edit, and paste:', 'sugarcommerce' ),
				'corsWhereBackblaze' => __( 'Backblaze takes rules in its own format. Pass this to the b2 command line tool’s --cors-rules option when updating bucket {bucket}:', 'sugarcommerce' ),
				'corsWhereGcs' => __( 'Save this as cors.json, then run: gcloud storage buckets update gs://{bucket} --cors-file=cors.json', 'sugarcommerce' ),
				'corsWhereDigitalocean' => __( 'In the DigitalOcean control panel open the Space, then Settings → CORS Configurations → Add, and enter:', 'sugarcommerce' ),
				'corsWhereDefault' => __( 'Save this as cors.json, then run it with the AWS command line tool, using a key that may change bucket settings: aws s3api put-bucket-cors --bucket {bucket} --cors-configuration file://cors.json --endpoint-url YOUR-ENDPOINT', 'sugarcommerce' ),
				'whatRuleDoes'           => __( 'What This Rule Does:', 'sugarcommerce' ),
				'putMethodOnly'          => __( 'PUT method only:', 'sugarcommerce' ),
				'putMethodDesc'          => __( 'Enables secure file uploads via presigned URLs', 'sugarcommerce' ),
				'minimalHeaders'         => __( 'Minimal headers:', 'sugarcommerce' ),
				'minimalHeadersDesc'     => __( 'Only Content-Type and Content-Length for security', 'sugarcommerce' ),
				'singleOrigin'           => __( 'Single origin:', 'sugarcommerce' ),
				'singleOriginDesc'       => __( 'Restricts access to your domain only', 'sugarcommerce' ),
				'oneHourCache'           => __( '1-hour cache:', 'sugarcommerce' ),
				'oneHourCacheDesc'       => __( 'Reduces preflight requests', 'sugarcommerce' ),
				'note'                   => __( 'Note:', 'sugarcommerce' ),
				'configOptimized'        => __( 'This configuration is optimized for browser uploads only. All other operations (delete, list, etc.) are handled server-side and don\'t require additional CORS permissions.', 'sugarcommerce' ),

				// CORS revocation
				'revokeConfirm'          => implode( "\n", [
					__( 'Are you sure you want to revoke all CORS rules for bucket "{bucket}"?', 'sugarcommerce' ),
					'',
					__( 'This will:', 'sugarcommerce' ),
					__( '• Disable file uploads from web browsers', 'sugarcommerce' ),
					__( '• Prevent cross-origin access to bucket resources', 'sugarcommerce' ),
					__( '• Require CORS to be reconfigured before uploads work again', 'sugarcommerce' ),
					'',
					__( 'This cannot be undone automatically.', 'sugarcommerce' ),
				] ),
				'revokingCors'           => __( 'Revoking CORS rules...', 'sugarcommerce' ),
				'revokeSuccess'          => __( 'CORS rules successfully revoked for bucket "{bucket}"', 'sugarcommerce' ),
				'revokeError'            => __( 'Failed to revoke CORS rules: {message}', 'sugarcommerce' ),
			],
			'upload' => [
				'cancelUploadConfirm' => __( 'Are you sure you want to cancel "{filename}"?', 'sugarcommerce' ),
				'uploadFailed'        => __( 'Upload failed:', 'sugarcommerce' ),
				'uploadComplete'      => __( 'Uploads completed. Refreshing file listing...', 'sugarcommerce' ),
				'corsError'           => __( 'CORS configuration error - Your bucket needs proper CORS settings to allow uploads from this domain.', 'sugarcommerce' ),
				'networkError'        => __( 'Network error detected. Please check your internet connection and try again.', 'sugarcommerce' ),
				'failedPresignedUrl'  => __( 'Failed to get upload URL', 'sugarcommerce' ),
				'uploadFailedStatus'  => __( 'Upload failed with status', 'sugarcommerce' ),
				'uploadCancelled'     => __( 'Upload cancelled', 'sugarcommerce' ),
				'resuming'            => __( 'Resuming: {done} of {total} parts are already in storage.', 'sugarcommerce' ),
				'assembling'          => __( 'Putting the file together…', 'sugarcommerce' ),
				'retrying'            => __( 'The connection dropped. Trying part {part} again…', 'sugarcommerce' ),
				'noChecksums'         => __( 'The bucket\'s CORS rules do not allow the Content-MD5 header, so parts are being sent without a checksum.', 'sugarcommerce' ),
				'tooLarge'            => __( '"{filename}" is larger than the 5 TB storage keeps in one file.', 'sugarcommerce' ),
				'partFailed'          => __( 'Part {part} could not be uploaded: {reason}', 'sugarcommerce' ),
				'resumeHint'          => __( 'Drop the same file here again to carry on where it stopped.', 'sugarcommerce' ),
			],
			'validation' => [
				'validationFailed'    => __( 'File Validation Failed', 'sugarcommerce' ),
				'invalidFileType'     => __( 'File type "{extension}" is not allowed', 'sugarcommerce' ),
				'invalidMimeType'     => __( 'MIME type "{mimeType}" is not allowed', 'sugarcommerce' ),
				'someFilesRejected'   => __( 'Uploading {accepted} files. {rejected} files were rejected due to validation errors.', 'sugarcommerce' ),
				'connectionSuccess'   => __( 'Connection successful!', 'sugarcommerce' ),
				'connectionFailed'    => __( 'Connection test failed', 'sugarcommerce' ),
			],
			'loading' => [
				'loadingText'    => __( 'Loading...', 'sugarcommerce' ),
				'loadMoreItems'  => __( 'Load More Items', 'sugarcommerce' ),
				'networkError'   => __( 'Network error. Please try again.', 'sugarcommerce' ),
				'testing'        => __( 'Testing...', 'sugarcommerce' ),
				'testConnection' => __( 'Test Connection', 'sugarcommerce' ),
			],
			'search' => [
				'noMatchesFound' => __( 'No matches found', 'sugarcommerce' ),
				'noFilesFound'   => __( 'No files or folders found matching "{term}"', 'sugarcommerce' ),
				'itemsMatch'     => __( '{visible} of {total} items match', 'sugarcommerce' ),
			],
			'display' => [
				'singleItem'    => __( 'item', 'sugarcommerce' ),
				'multipleItems' => __( 'items', 'sugarcommerce' ),
				'moreAvailable' => __( ' (more available)', 'sugarcommerce' ),
			],
			'cache' => [
				'cacheRefreshed' => __( 'Cache refreshed successfully', 'sugarcommerce' ),
			],
			'copyLink' => [
				'copyLink'             => __( 'Copy Link', 'sugarcommerce' ),
				'linkDuration'         => __( 'Link Duration (minutes)', 'sugarcommerce' ),
				'linkDurationHelp'     => __( 'Enter how long the link should remain valid (1 minute to 7 days).', 'sugarcommerce' ),
				'generatedLink'        => __( 'Generated Link', 'sugarcommerce' ),
				'generateLinkFirst'    => __( 'Click Generate Link to create a shareable URL and copy it', 'sugarcommerce' ),
				'generateLink'         => __( 'Generate Link', 'sugarcommerce' ),
				'generatingLink'       => __( 'Generating link...', 'sugarcommerce' ),
				'linkGenerated'        => __( 'Link generated successfully!', 'sugarcommerce' ),
				'linkGeneratedSuccess' => __( 'Link generated successfully', 'sugarcommerce' ),
				'linkExpiresAt'        => __( 'Link expires at: {time}', 'sugarcommerce' ),
				'linkCopied'           => __( 'Link copied to clipboard.', 'sugarcommerce' ),
				'copyFailed'           => __( 'Link ready. Press Ctrl/Cmd+C to copy it.', 'sugarcommerce' ),
				'invalidDuration'      => __( 'Duration must be between 1 minute and 7 days (10080 minutes)', 'sugarcommerce' ),
			],
			'fileDetails' => [
				'title'         => __( 'File Details', 'sugarcommerce' ),
				'filename'      => __( 'Filename:', 'sugarcommerce' ),
				'objectKey'     => __( 'Object Key:', 'sugarcommerce' ),
				'size'          => __( 'Size:', 'sugarcommerce' ),
				'bytes'         => __( 'bytes', 'sugarcommerce' ),
				'mimeType'      => __( 'MIME Type:', 'sugarcommerce' ),
				'category'      => __( 'Category:', 'sugarcommerce' ),
				'storageClass'  => __( 'Storage Class:', 'sugarcommerce' ),
				'etag'          => __( 'ETag:', 'sugarcommerce' ),
				'multipart'     => __( 'Multipart', 'sugarcommerce' ),
				'parts'         => __( 'parts', 'sugarcommerce' ),
				'checksumInfo'  => __( 'Checksum Information', 'sugarcommerce' ),
			],
			'checksum' => [
				'noChecksumAvailable' => __( 'No checksum available', 'sugarcommerce' ),
				'none'                => __( 'None', 'sugarcommerce' ),
				'md5Composite'        => __( 'MD5 (Composite)', 'sugarcommerce' ),
				'md5'                 => __( 'MD5', 'sugarcommerce' ),
				'compositeNote'       => __( 'Hash of hashes from {parts} - not directly verifiable against file content', 'sugarcommerce' ),
				'directNote'          => __( 'Direct MD5 of file content - can be verified after download', 'sugarcommerce' ),
				'multipleParts'       => __( 'multiple parts', 'sugarcommerce' ),
			],
			'cors' => [
				'corsSetup'              => __( 'Setup CORS', 'sugarcommerce' ),
				'corsError'              => __( 'Failed to configure CORS', 'sugarcommerce' ),
			],
		];
	}
}
