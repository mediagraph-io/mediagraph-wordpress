# Translations

Drop `mediagraph-assets-<locale>.mo` / `.po` files here, plus the
`mediagraph-assets-<locale>-<handle>.json` files that `wp_set_script_translations()`
needs for the JavaScript strings.

No translations ship with the plugin yet; the directory exists because
`load_plugin_textdomain()` and `wp_set_script_translations()` both point at it.
