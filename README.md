# Storage Browser

A bucket browser for the WordPress admin, and a "Choose from your bucket"
picker for any field: buckets, folders, search, uploads straight to storage
in resumable parts, rename, move and delete. Built on `sugarcommerce/s3`.

## What it does

Files sold as downloads usually live in object storage rather than the media
library, and the moment they do, attaching one to a product means copying a
key out of another tab. The browser puts the bucket beside the media library,
and the picker puts it beside any input: open it, find the file, and the
field has `s3://bucket/key`.

Nothing is made public, uploads go from the browser straight to storage
rather than through PHP, a 40 GB file that stops at 80% carries on from the
parts storage kept, and the parts of an upload nobody finished are cleared
away once a day rather than billed for ever.

## Features

* Browse buckets and folders in the admin, search a folder, load more as you scroll
* Upload straight to storage: small files in one PUT, large ones in checksummed parts that resume when the file is dropped again
* Rename, move, delete files and folders, warned first about anything that refers to them
* Copy a signed link, see a file's details, check and set the bucket's CORS rule for this site
* A tab in every media frame a post editor or a page of your own opens; EDD and WooCommerce file tables filled in directly
* "Choose from your bucket": a keyboard-first dialog that returns one file to any input, from PHP, JavaScript or a data attribute
* A file source other packages can offer without depending on this one
* A connection test that runs every step a store will
* `wp myshop files` commands to verify, list and assign files in bulk
* Plain JavaScript, built with `@wordpress/scripts`: no jQuery, no React

## Installation

```bash
composer require sugarcommerce/storage-browser
```

It requires `sugarcommerce/s3` (every request), `sugarcommerce/storage` (MIME
types and filenames), `sugarcommerce/support` (the listing cache and its
clear-up) and `sugarcommerce/plugin` (asset URLs and the build's name). The
scripts are built and committed in `assets/build`, so nothing runs at install.

## Quick start

```php
use SugarCommerce\S3\Account;
use SugarCommerce\StorageBrowser\Browser;

$browser = new Browser( Account::r2( $account_id ), $access_key, $secret_key, [ 'download' ], 'my-bucket', 'upload_files', 'myshop' );
$browser->set_allowed_buckets( [ 'my-bucket' ] );

// A "Choose from Cloudflare R2" button beside an input of your own.
echo $browser->picker()->button( 'my-file-url', [ 'name' => 'my-file-name' ] );
```

## The bucket browser

Files sold as downloads usually live in object storage rather than the media
library, and the moment they do, attaching one to a product means copying a
key out of another tab. `Browser` adds the bucket beside the media library:
buckets, folders, search, upload, rename, move, delete and a link back into
whatever field asked for it. Nothing is made public, and uploads go straight
from the browser to storage rather than through PHP.

```php
use SugarCommerce\StorageBrowser\Browser;
use SugarCommerce\S3\Account;

$browser = new Browser(
	Account::r2( $account_id ),
	$access_key,
	$secret_key,
	[ 'download' ],   // post types it appears for; [] means all
	'my-bucket',      // bucket it opens on
	'upload_files',   // capability required to use it
	'myshop'          // context: which plugin this instance serves
);

$browser->set_allowed_buckets( [ 'my-bucket' ] );
```

Confining it to one bucket is worth doing whenever you can: S3 keys usually
reach every bucket in the account, and the bucket arrives with each request.
Every REST route checks the capability, and every bucket route checks the
allow-list as well -- so does the media tab, which core would otherwise open
for anybody who may upload; changing a bucket's CORS rules takes
`manage_options` besides. A key with a `.` or `..` segment is refused before a
request is made.

`Account::aws( $region )`, `Account::regional( Provider::DigitalOcean, 'ams3' )`
and `Account::custom( 'minio.internal:9000' )` cover the other providers. Two
browsers on one site — two plugins, or two contexts — get separate media tabs,
REST routes, script handles and caches.

### The client

`Client` is what the routes and list tables ask, cached per bucket
and answered as an array or a `WP_Error` with storage's own code, its words
and a status a REST response can relay. Every request goes through
`SugarCommerce\S3\Bucket`; the browser has no S3 client of its own.

```php
$client = new SugarCommerce\StorageBrowser\Client( Account::r2( $account_id ), $access_key, $secret_key );

$client->get_buckets();                           // [ 'buckets' => [ [ 'name', 'created' ] ], 'scoped' => bool ]
$client->get_objects( 'my-bucket', 1000, 'packs/' ); // S3Object rows, folder prefixes, a continuation token
$client->object_exists( 'my-bucket', $key );      // [ 'exists', 'size', 'mime_type' ]
$client->rename_object( 'my-bucket', $from, $to );
$client->delete_folder( 'my-bucket', 'packs/old' ); // every page of it, DeleteObjects or one key at a time
$client->get_presigned_url( 'my-bucket', $key, 60 ); // minutes
$client->get_bucket_options();                    // [ name => name ] for a select
```

### Pages that are not post editors

A post editor is recognised by its post type. A screen of your own — a
settings page, a list, a panel — names its page instead and says where a
chosen file goes:

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) use ( $browser ) {
	if ( 'toplevel_page_my-shop' === $hook ) {
		$browser->set_host_hook( $hook );
		wp_enqueue_media();
	}
}, 5 );
```

```js
window.S3BrowserHost = {
	insert: function ( files ) {
		// files: [ { fileName, bucket, key } ]
	}
};
```

The browser then appears as a tab in any media frame that page opens. The page
owns its fields, so it is asked rather than written into: throw from `insert`
and the frame stays open with the selection still in it. On the edit screens of
the `product` and `download` post types the browser fills the downloadable-files
table itself.

### Uploads need CORS

Uploading from the browser is a cross-origin request, so the bucket has to allow
it. The bucket's Details panel says whether it does for this site's origin and
offers to set the rule. Setting it needs an admin token: a key with Object Read
& Write cannot call `PutBucketCors`, and the error it gets back says
`AccessDenied`, which reads like a bucket problem rather than a key problem.
For checksummed parts the rule has to allow the `Content-MD5` request header
as well; the rule the browser sets does.

### Large uploads

A file of 64 MB or more -- and every file over the 5 GB one PUT may carry --
goes in parts through `SugarCommerce\S3\Multipart`: the server starts the upload and signs
part URLs twenty at a time, the browser PUTs four parts at once, each with
its Content-MD5, retrying a part that fails with a backoff and signing an
expired URL again, and the server assembles what storage says it holds. A
part storage reports missing at the end is sent again, once. Cancelling
aborts the upload in storage.

Dropping the same file again -- after a reload, a closed tab, a part that
would not go -- carries on from the parts storage kept: the file's name,
size and modified time find the upload. Uploads belong to the user who
started them. The thresholds are in `S3BrowserGlobalConfig.multipart`
(`threshold`, `concurrency`, `signBatch`, `retries`), which the
`browser_global_config` filter changes.

| Route (under the browser's namespace and base) | |
|---|---|
| `POST buckets/{bucket}/uploads` | Start, or resume the same file: `key`, `size`, `mime_type`, `fingerprint` → `id`, `part_size`, `part_count`, `done`, `resumed`, `checksums` |
| `POST buckets/{bucket}/uploads/{id}/parts` | Part URLs: `parts` (up to 100 numbers) → `urls` |
| `GET buckets/{bucket}/uploads/{id}` | Where it stands, from storage: `done`, `progress` |
| `POST buckets/{bucket}/uploads/{id}/complete` | Assemble → `address`; 409 `s3_upload_incomplete` with `missing` |
| `DELETE buckets/{bucket}/uploads/{id}` | Abort |
| `GET connection/check?bucket=` | The whole connection test, step by step |

Once a day each browser aborts the unfinished uploads in its buckets -- the
ones it is confined to, or the one it opens on -- older than a week
(`prune_uploads()`, on the cron hook `get_prune_hook()`). A plugin's
deactivation can take it out of the calendar with
`wp_clear_scheduled_hook( Browser::prune_hook( $hook_suffix ) )`.

### The connection test

The Test Connection button lists the account's buckets, and then, for the
fallback bucket a plugin filters in or the bucket the browser opens on, runs
`Probe` and shows each step with what to change.

### Icons

Buckets, folders, file types and upload states are drawn as inline Heroicons
(outline, MIT, in `assets/icons/heroicons`), read through
`SugarCommerce\Plugin\Assets::contents()` and handed to the scripts already
drawn (`Admin\Icon::for_scripts()`, `for_picker()`). Every icon carries the
class `arraypress-icon`, so one stylesheet rule sizes them all.

### WP-CLI

A plugin that keeps a table of downloadable files implements `Cli\Files`
(find a product by reference, read and write its files) and registers the
commands under its own name:

```php
WP_CLI::add_command( 'myshop files', new SugarCommerce\StorageBrowser\Cli\Commands( $store, $browser_client ) );
```

```bash
wp myshop files verify                     # every referenced object is really in the bucket
wp myshop files manifest --all > files.txt # every referenced path, for rclone --files-from
wp myshop files assign files.csv           # dry run: what would change
wp myshop files assign files.csv --mode=sync --execute
```

`assign` reads a CSV of `file_name`, `file_path` and a reference column (`sku`,
`id`, `product`, `download` or `reference`). Modes are `append` (the default),
`sync`, which matches rows by name so a download keeps its id, and `replace`.

### Hooks

Every hook is named from the build's prefix, so two plugins bundling their own
copy never answer each other's filters: unprefixed it is
`sugarcommerce_storage_browser_{name}`, in a Strauss build prefixed `MyPlugin\`
it is `myplugin_sugarcommerce_storage_browser_{name}` -- so the configuration
filter is `sugarcommerce_storage_browser_browser_config`. `Utils\Runtime::hook( 'browser_config' )`
gives the name, whatever the build. The ones marked *+context* run a second
time as `{name}_{context}`, so a plugin can change its own browser without
changing every browser on the site.

| Hook | Kind | |
|---|---|---|
| `browser_allowed_buckets` | filter | Buckets this instance may address (`$buckets, $provider_id, $context`) |
| `browser_config`, `browser_global_config` | filter, +context | The configuration handed to the scripts |
| `browser_translations` | filter, +context | The scripts' strings |
| `browser_insert_label` | filter, +context | The "Insert Selected" button |
| `browser_auto_load` | filter | Open the listing straight away |
| `sugarcommerce_storage_allowed_mime_types` | filter, +context | What may be uploaded -- `sugarcommerce/storage`'s hook, named from its own build |
| `s3_object_hidden_files` | filter | Names never listed (`.DS_Store`, `Thumbs.db`, `__MACOSX` and the like) |
| `s3_known_buckets_fallback` | filter | Buckets to show when the key cannot list them |
| `s3_connection_test_fallback_bucket` | filter | A bucket the connection test may read instead |
| `s3_object_references` | filter | Where an object is used, shown before it is renamed or deleted |
| `s3_object_renamed` | action | After a rename or move (`$bucket, $old_key, $new_key`) |
| `s3_upload_prune_days` | filter | Days an unfinished upload may sit before the daily clear-up aborts it (`7, $provider_id, $context`) |
| `s3_prune_uploads_{suffix}` | action | The daily clear-up's cron hook, one per browser instance |
| `s3_get_objects_response` | filter, +context | A listed page, with the bucket, prefix, max keys and context |
| `picker_label` | filter, +context | What the picker's button and dialog are called (`Choose from Cloudflare R2`) |
| `picker_config` | filter, +context | What the picker's script is told: REST URL, buckets, icons |

## "Choose from your bucket"

`Browser::picker()` is a dialog that browses this browser's buckets and hands
back one file. It is for a field that wants a file and nothing else: no
upload, no rename -- the browser is there for those.

```php
$picker = $browser->picker();

// A button beside an input; the input gets s3://bucket/key.
echo $picker->button( 'product-file', [
	'name'    => 'product-file-name', // an input for the file's name, filled when empty
	'accept'  => [ 'zip', 'wav' ],    // only these can be chosen
	'returns' => 'address',           // or 'path' (bucket/key) or 'key'
] );

// Or only load it, and open it from your own script.
$picker->enqueue();
```

```js
const file = await window.SugarCommerceStoragePicker.open( { accept: [ 'zip' ] } );
// { bucket, key, name, size, mimeType, address: 's3://bucket/key' }, or null when dismissed

window.SugarCommerceStoragePicker.attach( button, { input: '#file-url', name: '#file-name' } );
```

Any button with `data-storage-picker` attaches itself, now or when a repeater
adds it later: `data-storage-picker-target` (the input), `-name`, `-returns`,
`-accept` (comma separated), `-bucket`, `-prefix` and `-source` (which browser,
when a page has two). A chosen file fires `input` and `change` on the input,
and `storage-picker:chosen` on the button with the file as its `detail`.

The dialog is the family's: a native `<dialog>`, focus kept in the search box,
the arrow keys, Home, End and the page keys moving through the list, Enter
opening a folder or choosing a file, Backspace in an empty box going up a
folder, Escape closing it. At phone width it is the screen less a margin,
with finger-sized rows and full-width buttons.

Search covers the folder and every folder beneath it, by the words in a
file's name. Storage has no search, so it lists a thousand keys a request
and keeps what matches -- at most ten requests, or two hundred matches,
before it answers with a token to carry on from ("Load more"). A folder is
listed fresh each time it is opened, not from the browser's hour-long cache:
the file wanted has often only just been uploaded.

| Route (under the browser's namespace and base) | |
|---|---|
| `GET buckets` | The buckets it may open: the allow-list when there is one, otherwise what the key can list |
| `GET buckets/{bucket}/browse` | `prefix`, `search`, `continuation_token` → `folders`, `objects` (`key`, `name`, `size`, `size_label`, `modified_label`, `mime_type`, `category`, `address`), `truncated`, `continuation_token` |

### Offering it from another package

A field library should not have to depend on a bucket browser to offer one.
`Picker::source()` is the picker as plain values -- an id, a label, an icon,
a callable that loads the script, and `protocols` (`[ 's3' ]`, the scheme its
addresses use, so a field that cleans its value as a URL keeps `s3://…`) --
and the script registers the same id in `window.sugarcommerceFileSources`, a
registry that names no package:

```php
// In the plugin that owns both, where the field library asks for sources.
add_filter( $fields_file_sources_hook, fn( array $sources ) => $sources + [ $picker->id() => $picker->source() ] );
```

```js
const chosen = await window.sugarcommerceFileSources[ id ].pick( { accept: [ 'zip' ], returns: 'address' } );
// { value: 's3://bucket/key', name, bucket, key, size, … }, or null
```

## The scripts

Plain JavaScript in ES modules (`assets/js`), each with one job, built by
`@wordpress/scripts` into `assets/build` -- one file per page that needs one,
with an `.asset.php` naming the WordPress scripts it imports
(`wp-api-fetch`, `wp-i18n`, `wp-dom-ready`) and a version that changes with
its contents. `SugarCommerce\Plugin\Assets::enqueue_built_script()` registers an entry
from that file.

| Entry | Loaded on | Modules |
|---|---|---|
| `browser` | the browser's own media-frame tab, or a page named with `set_admin_hook()` | `lib/list` (search, load more, selection), `lib/files`, `lib/folders`, `lib/buckets`, `lib/tree`, `lib/upload-panel`, `lib/uploader` and `lib/multipart` (the parts), `lib/progress`, `lib/modal`, `lib/md5` |
| `picker` | wherever `Picker::enqueue()` or `button()` runs | `picker/dialog`, `picker/keyboard`, `picker/listing` |
| `host` | a page named with `set_host_hook()` | `lib/host-page` |
| `edd`, `wc` | the `download` and `product` edit screens | `lib/host-page` |
| `connection` | a settings page named with `set_admin_hook()` | the Test Connection button |

`lib/api` is the one REST client (`@wordpress/api-fetch`), `lib/config` the
one reader of what PHP handed over, and `lib/dom` the few DOM helpers -- among
them `html`, a template tag that escapes every value put into it. Strings a
plugin may change through `browser_translations` come from PHP; the rest are
`__()` in the `sugarcommerce` text domain, with `wp_set_script_translations()`.

```bash
npm ci
npm run build      # assets/build, committed
npm run start      # rebuild on change
npm run test:js    # Vitest, in jsdom
npm run lint:js
```

The build's files are what ship. CI builds again and fails when the result
differs from what was committed.

## How it is laid out

* `Browser.php`, `Client.php`, `Cache.php`, `Permissions.php` -- the browser, what it asks of storage, its cache, and what a key may do
* `Admin/` -- the media tab, the page it can live on, its configuration, icons, scripts and strings
* `Rest/` -- the REST controller; its routes and handlers are `Traits/Controller`, one trait per job
* `Picker/` -- "Choose from your bucket"
* `Tables/` -- the bucket and object list tables
* `Cli/` -- the WP-CLI import commands
* `Models/`, `Utils/` -- an object row, and the small helpers (paths, timestamps, the build's prefix)
* `Traits/` -- `Client` and `Controller`, one trait per job, and the debug and context traits they share
* `assets/js/` -- the scripts' sources (`src/` is PHP only); `assets/build/` what they build to; `assets/css/` the styles

## Security

* Checks the browser's capability on every route and on the media tab, which WordPress itself opens for anybody who may upload
* Confines every route, and the media tab's listing, to the allow-list of buckets when there is one -- S3 keys usually reach the whole account
* Asks `manage_options` as well before a bucket's CORS rules are set or removed, since they change the bucket for every site using it
* Lets a user resume, sign, finish or abort only an upload they started, in the bucket the route names
* Refuses a malformed bucket name before it reaches a host name, and a key with a `.` or `..` segment before anything is signed
* Escapes everything the scripts put into the page by default, and everything the list tables print
* Reports storage's refusals to the debug log without a header, so no signature or key reaches it

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/s3`, `sugarcommerce/storage`, `sugarcommerce/support` and `sugarcommerce/plugin`
* Credentials for an S3-compatible provider, and a CORS rule on the bucket for uploads from the browser
* Node 22 or later, only to change the scripts

## License

GPL-2.0-or-later

The Heroicons in `assets/icons/heroicons` are MIT, © Tailwind Labs; their licence is beside them.
