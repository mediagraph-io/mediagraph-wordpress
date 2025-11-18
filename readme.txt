=== Mediagraph File Picker ===
Version: 1.1.0
Requires at least: WordPress 5.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Integrate Mediagraph's media asset management system into WordPress. Browse, search, and insert assets from Collections, Storage Folders, and Lightboxes.

== Description ==

Mediagraph File Picker seamlessly integrates your Mediagraph media library into WordPress. Access all your media assets directly from the WordPress editor with secure OAuth 2.0 authentication.

**Key Features:**

* OAuth 2.0 with PKCE - Secure authentication without client secrets
* Browse Collections, Storage Folders, and Lightboxes
* Elasticsearch-powered search with filters
* Visual permission indicators for restricted assets
* Metadata write-back on publish
* Multi-organization support

**Requirements:**

* A Mediagraph account (https://mediagraph.io)
* WordPress 5.8 or higher
* PHP 7.4 or higher

== Installation ==

See INSTALLATION.md for detailed installation instructions, or:

1. Upload plugin via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Settings > Mediagraph
4. Click "Connect to Mediagraph"
5. Authorize the connection
6. Start using in your posts!

== Changelog ==

= 1.1.0 - November 2025 =
* Fix bin (Lightbox sub-folder) filtering
* Update sort terminology: "Date Taken" → "Creation Date" with asc/desc options
* Fix container tree turndown alignment
* Add distinct organizer icons per container type
* Show paginated count format (e.g., "1-50 of 147 assets")
* Add pagination spacing

= 1.0.0 - October 2025 =
* Initial release
* OAuth 2.0 with PKCE authentication
* Browse Collections, Storage Folders, Lightboxes
* Search and filter assets
* Insert media into posts
* Metadata write-back on publish
* Multi-organization support
* Collapsible sidebar sections
* Enhanced loading indicators
* Optimized modal sizes

== Support ==

For support, please visit https://docs.mediagraph.io or contact support@mediagraph.io

== Copyright ==

Copyright (c) 2025 Mediagraph. All rights reserved.
