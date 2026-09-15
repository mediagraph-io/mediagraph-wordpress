# Mediagraph Assets for WordPress

Browse, search, and insert assets from your [Mediagraph](https://www.mediagraph.io)
library without leaving the WordPress editor.

This plugin is a client for Mediagraph, a paid digital asset management service.
It needs an account there to do anything.

## How it works

An asset you choose is downloaded into the WordPress media library and inserted
as an ordinary **core block**. A Mediagraph image is a real attachment, so
cropping, rotation, `srcset`, registered image sizes, alignment, captions, alt
text and the Replace button all work with no plugin code at all.

That is the central design decision, and it is worth preserving. If a block
control is missing, the fix is almost always "hand core a real attachment ID",
not "generate more HTML".

Mediagraph appears in three places in the editor:

- Beside Upload and Media Library in every empty media block
- In the Replace menu on a block that already holds media
- In the block toolbar, along with a panel for editing an asset's title,
  caption, alt text and credit after it has been placed

Supported blocks: Image, Gallery, Audio, Video, Cover, File, Media & Text, Site
Logo, and any third-party block built on the standard media placeholder.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- A Mediagraph account

## Installing

Download a release ZIP and install it through **Plugins → Add New → Upload
Plugin**, then connect the site under **Settings → Mediagraph**. Connecting uses
OAuth 2.0 with PKCE — you sign in at mediagraph.io and approve access, and no
password or client secret is ever stored on your site.

## Building

The bundles in `admin/js/dist/` are compiled from `admin/js/src/`. With Node.js
18 or newer:

```bash
npm ci
npm run build      # production bundles
npm run dev        # watch build
npm run lint
npm test
```

React and the `@wordpress/*` packages are webpack externals, not bundled — the
plugin uses the copies WordPress already ships.

`./build-release.sh` runs lint, tests and a production build, then assembles a
versioned ZIP in `releases/`. It reads the version from the plugin header and
fails if `package.json` or `readme.txt` disagree.

## What gets sent to Mediagraph

Nothing until an administrator connects the site. After that, the plugin calls
`https://mediagraph.io` to list containers, run searches, fetch and download
assets, refresh its token, and — when a post containing Mediagraph assets is
published — report where those assets were used.

That last report includes the post's title, permalink, publication date,
excerpt, author display name and body text, so a newsroom can find the story an
asset ran in. The body is capped at 100,000 characters; the
`mediagraph_article_text_limit` filter shortens it, and setting it to `0` omits
it entirely.

See `readme.txt` for the full disclosure, and Mediagraph's
[terms](https://www.mediagraph.io/terms-of-service) and
[privacy policy](https://www.mediagraph.io/privacy-policy).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
