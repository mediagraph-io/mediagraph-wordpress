=== Mediagraph Assets ===
Contributors: mediagraph
Tags: media, dam, digital asset management, images, gallery
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Browse, search, and insert assets from your Mediagraph library without leaving the WordPress editor. Requires a Mediagraph account.

== Description ==

Mediagraph Assets connects your WordPress site to your Mediagraph digital asset
library. Editors browse collections, storage folders, and lightboxes from inside
the editor and insert assets in a couple of clicks.

This plugin is a client for Mediagraph, a paid third-party digital asset
management service. It does nothing on its own: a Mediagraph account is required,
and the plugin is only useful to organizations that already have a library there.

Assets you choose are copied into the WordPress media library and inserted as
standard WordPress blocks. That is the important part: a Mediagraph image is an
ordinary attachment, so cropping, rotation, alignment, image sizes, captions,
alt text, `srcset`, and the Replace button all behave exactly as they do for a
file you uploaded yourself.

**Features**

* Secure OAuth 2.0 with PKCE — no client secret stored on your site
* Browse collections, storage folders, and lightboxes with lazy-loaded trees
* Full-text search plus filters for file type, rights status, creator, capture
  date, and custom metadata
* A Mediagraph option beside Upload and Media Library in every media block
* Multi-select for galleries and the classic editor
* Automatic file-type narrowing — a Video block only offers videos
* A Mediagraph tab in the standard WordPress media modal
* Usage reporting back to Mediagraph when a post is published
* Assets are never downloaded twice; re-inserting reuses the existing file

**Supported blocks**

Image, Gallery, Audio, Video, Cover, File, Media & Text, Site Logo, and any
third-party block built on the standard WordPress media placeholder.

== External Service ==

This plugin relies on Mediagraph, a third-party digital asset management
service operated by Mediagraph (https://www.mediagraph.io). The plugin is a
client for that service and cannot function without an account on it.

Nothing is sent anywhere until an administrator explicitly connects the site
under Settings → Mediagraph. Connecting starts an OAuth 2.0 authorization at
mediagraph.io; you sign in there and approve access, and the site stores the
resulting access and refresh tokens. No password ever reaches WordPress, and no
client secret is stored on your site.

Once connected, the plugin contacts `https://mediagraph.io` in these cases:

* When an editor opens the picker, to list collections, storage folders and
  lightboxes, and to load the filter options for your library.
* When an editor searches or filters, sending the search text and the chosen
  filters so the service can return matching assets.
* When an editor inserts an asset, to fetch that asset's metadata and download
  the file into your WordPress media library. Mediagraph records this as a
  download against your account.
* When a post containing Mediagraph assets is published or updated, so the
  library can record where an asset has been used. This report is the most
  substantial thing the plugin sends, and it includes: which assets appear and
  how they are used, your site name, the post's title, permalink, publication
  date, excerpt and author display name, and the post's body as plain text.

  The body is included so newsrooms can find the story an asset ran in from
  inside Mediagraph. It is capped at 100,000 characters, and you can shorten or
  disable it with the `mediagraph_article_text_limit` filter — setting it to 0
  sends no body text. Posts with no Mediagraph assets are never reported.
* Periodically, to exchange an expired access token for a fresh one.

The site's own admin URL is sent as part of the OAuth redirect so the service
can return the browser to your site after authorization.

Nothing is sent about visitors to your site, no analytics or telemetry are
collected, and the plugin adds no markup, links or credits to your public pages.

Mediagraph's terms and privacy policy govern the data handled by the service:

* Terms of Service: https://www.mediagraph.io/terms-of-service
* Privacy Policy: https://www.mediagraph.io/privacy-policy

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate it.
3. Go to Settings → Mediagraph.
4. Click "Connect to Mediagraph" and authorize the connection.
5. Edit any post and look for Mediagraph in the media blocks.

Requires a Mediagraph account. See https://www.mediagraph.io

== Building From Source ==

Development happens in the open at
https://github.com/mediagraph-io/mediagraph-wordpress — that repository is the
canonical source, and it is also where to report a bug or send a patch.

You do not need it to verify this plugin, though. The JavaScript in
`admin/js/dist/` is compiled, so this package ships the unminified sources it is
built from, along with the tooling needed to reproduce them. Nothing is
obfuscated and no build step is hidden.

* Sources: `admin/js/src/` (the picker and the block editor integration)
* Tooling: `webpack.config.js`, `babel.config.js`, `package.json`,
  `package-lock.json`
* Tests: `admin/js/__tests__/`

From the plugin directory, with Node.js 18 or newer:

`npm ci`
`npm run build`

That regenerates `admin/js/dist/mediagraph-picker.bundle.js` and
`admin/js/dist/mediagraph-blocks.bundle.js`. `npm run lint` and `npm test` also
run against the shipped sources. React and the WordPress packages are treated as
externals and are not bundled — the plugin uses the copies WordPress provides.

== Frequently Asked Questions ==

= Where do the files live? =

In your WordPress media library. Mediagraph is the source of record; WordPress
holds a copy so the site can serve it quickly and so every standard editing
control works.

= Does choosing the same asset twice create duplicates? =

No. Imports are deduplicated per asset and download quality.

= Who can use the picker? =

Any user who can edit posts sees it; importing files additionally requires the
upload_files capability. Only administrators can connect or disconnect the site.

= What happens when the connection expires? =

The plugin refreshes the access token automatically. If the refresh token has
also expired, an admin notice appears with a link to reconnect, and the picker
explains the situation instead of failing silently.

== Upgrade Notice ==

= 2.1.0 =
Housekeeping and interface fixes. The package now ships the JavaScript sources
and build tooling, and the readme documents exactly what the plugin sends to
Mediagraph. Sites that do not want post body text leaving WordPress can now set
the mediagraph_article_text_limit filter to 0.

= 2.0.0 =
Major rewrite. Existing posts are unaffected and existing connections are kept.
Newly inserted assets now become native WordPress blocks, so all standard image
controls work. Legacy Mediagraph blocks keep rendering and can be converted
in-place from the editor toolbar.

== Changelog ==

= 2.1.0 =
* The picker now has a single Insert button, in the footer. The details panel
  no longer offers a second one that acted only on the asset being previewed.
* Added Mediagraph to the Replace menu on a block that already holds media,
  beside Open Media Library, Upload, and Reset.
* Fixed: title, caption, alt text, and credit shown in the details panel are
  now applied to the attachment when inserting straight from the footer.
  Previously they only travelled if the field had been typed in by hand.
* Removed the Mediagraph option from the Icon block. That block stores the name
  of a built-in SVG icon rather than an uploaded file, so it had nowhere to put
  an attachment.
* The chosen download quality now applies to the whole insert and is kept while
  moving between assets.
* The package now includes the unminified JavaScript sources, the webpack and
  babel configuration, and the lockfile, so the shipped bundles can be rebuilt
  from the plugin itself.
* Added the GPL-2.0 licence text, and documented the Mediagraph service, the
  data sent to it, and its terms and privacy policy in the readme.
* The sitewide "connection expired" admin notice is now dismissible.
* Changed: mediagraph_article_text_limit set to 0 now omits the post body from
  the publish report entirely. It previously meant "no limit", which was the
  opposite of what the name suggests.

= 2.0.0 =
* Rewrite. Assets now insert as native WordPress blocks backed by real media
  library attachments, so cropping, sizes, alignment, replace, and every other
  core control work normally.
* Added a Mediagraph option beside Upload and Media Library in every media
  block placeholder, covering Image, Gallery, Audio, Video, Cover, File,
  Media & Text, and Site Logo.
* Added multi-select, so several assets can be inserted into a gallery at once.
* The grid now narrows to the file types the calling block accepts.
* Added filtering by file type, rights status, creator, capture date, and
  custom metadata fields.
* Asset details, including caption and credit, are shown while choosing an
  asset and remain editable from the block sidebar after it is placed.
* Large multi-asset imports are now split across requests so they cannot hit
  PHP's execution limit part-way through.
* Fixed: access tokens are now refreshed. Previously the refresh code existed
  but was never called, so every site silently broke when its token expired.
* Fixed: the connection status is now accurate, and an expired connection is
  reported instead of showing "Connected" over a picker that cannot load.
* Fixed: the sidebar Reload button now really reloads. Cache invalidation wrote
  one key and deleted another, so nothing was ever cleared.
* Fixed: usage reporting is derived from the saved post content instead of a
  browser global racing the save, so it no longer misses the first publish,
  and removing an asset from a post now removes it from the report.
* Fixed: the featured image is correctly reported as the lead photo.
* Fixed: video and audio usage is now reported rather than silently dropped.
* Fixed: assets skipped by the API are surfaced instead of reported as success.
* Fixed: re-inserting an asset no longer fills the media library with copies.
* Fixed: "Full size" now downloads the real full-size rendition. It previously
  resolved to the same 1200px preview as "Large".
* Fixed: the picker now loads on the site editor, widgets screen, and media
  library, where it previously failed to initialize.
* Fixed: usage reporting now covers custom post types.
* Fixed: dimensions display in the details panel.
* Removed the "Extended Description" field, which had no destination in
  WordPress and was always a copy of the description.
* Added capability checks to every AJAX endpoint.
* Debug output is now behind WP_DEBUG and redacts credentials.
* Full keyboard and screen-reader support in the picker.

= 1.4.1 =
* Fixed WordPress picker alignment and size controls.

= 1.4.0 =
* Added a Mediagraph tab to the WordPress media modal.
* Added Mediagraph buttons to Image, Gallery, Cover, and Media & Text toolbars.

= 1.3.0 =
* Download assets into the WordPress media library instead of hotlinking.

= 1.2.0 =
* Added asset size selection and caption support.

= 1.1.0 =
* Fixed bin (lightbox sub-folder) filtering.
* Renamed "Date Taken" to "Creation Date" with ascending and descending options.
* Added distinct organizer icons per container type.
* Added paginated count display.

= 1.0.0 =
* Initial release.

== Support ==

https://docs.mediagraph.io or support@mediagraph.io
