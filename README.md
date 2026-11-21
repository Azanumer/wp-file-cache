# WP File Cache

Full-page file caching for WordPress — no Redis, no Varnish, no server module required.

A tiny `advanced-cache.php` drop-in serves cached pages **without booting WordPress at all**. This plugin writes the cache files, purges them when content changes, and manages the settings.

## How it works

1. First visit: WordPress renders the page normally; the plugin captures the output and stores it as an HTML file in `wp-content/cache/wp-file-cache/`.
2. Next visit: the drop-in sees a fresh cache file and serves it directly with an `X-WP-File-Cache: HIT` header — PHP and MySQL are never touched.
3. When a post is saved, deleted, or the theme/customizer changes, the affected pages are purged.

## Installation

1. Upload this plugin to `wp-content/plugins/` and activate it.
2. Add to `wp-config.php` (above the "stop editing" line):
   ```php
   define( 'WP_CACHE', true );
   ```
3. Copy `advanced-cache.php` from this plugin into `wp-content/` (or click **Install drop-in** on the settings page — it does the copy for you).

## What is never cached

- Logged-in users, POST requests, admin pages, `wp-login.php`, `wp-cron.php`, `xmlrpc.php`, REST API, feeds, search results, previews
- Any URL containing one of your excluded patterns (defaults: `/cart/`, `/checkout/`, `/my-account/`)

## Settings

**Settings → File Cache**

- **Enable caching** — master switch.
- **Cache lifetime** — seconds a cached page stays fresh (default 3600, minimum 60).
- **Excluded URL patterns** — one substring per line.
- **Purge Entire Cache** — deletes all cached pages immediately.

The status table shows whether `WP_CACHE` is on, whether the drop-in is installed, and how many pages are cached (with total size).

## Filters

- `wfc_is_cacheable_request` — return `false` to skip caching a request (e.g. for a custom cookie check).

## Notes

- Cache files are written atomically (temp file + rename), so visitors never see a half-written page.
- Cache keys are `md5( host + request URI )`, so different query strings are cached separately.
- Deleting the plugin removes its settings and all cached pages. The drop-in and the `WP_CACHE` constant are left in place — remove them manually if you no longer want caching.

## License

MIT — see [LICENSE](LICENSE).
