# Installing the Mediagraph WordPress plugin

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- A Mediagraph account with permission to download assets
- An administrator account on the WordPress site (to connect it)

## Install

1. Download `mediagraph-assets-vX.Y.Z.zip`.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP and select **Install Now**.
4. Select **Activate Plugin**.

## Connect

1. Go to **Settings → Mediagraph**.
2. Select **Connect to Mediagraph**.
3. Sign in to Mediagraph if prompted, and approve the connection.
4. You are returned to WordPress with the account and organization shown.

The connection is **site-wide**: one Mediagraph account backs the whole site.
Every WordPress user who can edit posts browses through that account, so choose
an account whose Mediagraph permissions match what the whole editorial team
should be able to reach.

Access renews itself. If it ever cannot, an admin notice appears with a
**Reconnect** link, and the picker explains the situation rather than failing
silently.

## Using it

### Block editor

Add any media block — Image, Gallery, Audio, Video, Cover, File, Media & Text.
Alongside **Upload** and **Media Library**, there is a **Mediagraph** button.

The picker only offers files the block can use: a Video block shows videos, an
Image block shows images. A Gallery block lets you select several at once.

Once a block already has media, the **Mediagraph** button in its toolbar
replaces it (or, for a gallery, appends to it).

Because assets land in your media library as normal attachments, everything in
the block toolbar works exactly as it does for an uploaded file — crop, rotate,
resize, alignment, link settings, captions, alt text.

### Classic editor

Use **Add from Mediagraph** above the editor toolbar. Several assets can be
inserted at once.

### Media modal

Anywhere WordPress opens its media modal — including **Set featured image** —
there is a **Mediagraph** tab beside Media Library.

## In the picker

- **Left:** storage folders, collections, and lightboxes. Sections collapse and
  remember their state; the arrow expands sub-folders on demand.
- **Search:** full text across the library.
- **File type chips:** narrow to images, video, audio, or documents. When a
  block only accepts certain types this is locked and shown as a note instead.
- **Filters:** filter by custom metadata fields, and optionally include assets
  you do not have permission to download.
- **Info icon** on a tile: full metadata, plus the fields that will be written
  onto the WordPress media item, plus a download-quality choice.

Selecting is a single click; double-click inserts straight away. A padlock means
your Mediagraph account cannot download that asset.

## Download quality

The details panel offers only what your account is allowed to download:

| Option | What it is |
|---|---|
| Original file | The untouched file as stored in Mediagraph |
| Full size | Web-ready full resolution — recommended |
| Medium | 1200px |
| Small | 640px |

WordPress generates its own thumbnail, medium, and large sizes from whatever you
pick, so **Full size** is usually right. Choosing the same asset again reuses the
file already in your library instead of downloading another copy.

## Usage reporting

When a post is published or updated, the plugin tells Mediagraph which assets it
uses, along with the headline, byline, categories, tags, and article text. The
featured image is reported as the lead photo. Removing an asset from a post
removes it from the next report.

The result appears as a notice on the post screen. If Mediagraph could not match
some assets, it says how many and why rather than reporting success.

By default this covers posts and pages. Other post types can be added with the
`mediagraph_publishable_post_types` filter.

## Self-hosted Mediagraph

Under **Settings → Mediagraph → Configuration**, set **Mediagraph URL** to your
instance. Changing it clears the existing connection, so reconnect afterwards.

## Troubleshooting

**"Mediagraph is not connected" in the picker**
An administrator needs to connect the site at Settings → Mediagraph.

**The connection expired**
Select Reconnect on the settings screen. Assets already in posts are unaffected.

**A newly created collection is missing**
The container tree is cached for five minutes. Use the reload icon at the top of
the sidebar, or **Refresh asset list** on the settings screen.

**An asset shows a padlock**
The connected Mediagraph account does not have download permission for it. This
is a Mediagraph permission, changed there rather than here.

**"The Mediagraph picker did not load"**
The JavaScript bundle is missing or was blocked. Hard-refresh (Cmd/Ctrl +
Shift + R). If it persists, reinstall the plugin — an incomplete upload can
leave `admin/js/dist/` missing.

**Nothing appears in the block toolbar**
Confirm the site is connected, and that your WordPress role can edit posts.
Importing files additionally requires the `upload_files` capability.

### Deeper diagnostics

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Mediagraph messages are prefixed `[Mediagraph]` in `wp-content/debug.log`.
Credentials are redacted. Turn this off again on a production site.

## Upgrading from 1.x

Upgrade in place; nothing needs to be reconnected and no published post changes.

- Existing posts keep rendering exactly as before.
- Assets inserted by 1.x appear in the editor as "legacy" blocks with a toolbar
  button that converts them to a standard Image, Video, or Audio block.
- Newly inserted assets use native blocks from the start.
- The picker's **Extended Description** field is gone. It had no destination in
  WordPress and was always a copy of the description.

## Uninstalling

Deactivating keeps your connection and settings. Deleting the plugin removes its
options and disconnects the site. **Your media library is left alone** — every
imported file and every post that uses one keeps working.
