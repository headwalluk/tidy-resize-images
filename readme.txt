=== Tidy Resize Images ===
Contributors: paulfaulkner
Tags: media, images, optimization, resize, webp
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep the WordPress Media Library lean. Resize oversized uploads, convert unsuitable formats, and recompress bloated files — with backups and a dry-run preview.

== Description ==

Tidy Resize Images applies three classes of fix to your Media Library:

* **Oversized dimensions** — images uploaded at unnecessary resolutions are resized to a configurable maximum longest edge.
* **Oversized file size** — images that are dimensionally fine but bloated (e.g. a 4&nbsp;MB PNG that should be a 400&nbsp;KB WebP) are recompressed.
* **Unsuitable formats** — PNGs and HEICs are converted to WebP (or AVIF where supported); JPEGs are recompressed in place.

Originals are backed up to a Trash directory inside `wp-content/uploads/` and can be restored from the admin UI. A dry-run mode shows you exactly what would change before you commit.

== Features (v0.4.1) ==

* Resize on upload (using the configurable maximum longest edge).
* Recompress on upload using your chosen target format (WebP or AVIF where supported).
* **Smart format selection** for lossy sources: JPEGs and WebPs are converted to your preferred lossy target by default, with an automatic fallback to source-format recompression when the conversion would not save space.
* Bulk processor for existing Media Library content, with progress reporting and stop-mid-run.
* Daily cron variant of the bulk processor — processes a small batch each day so large libraries clear over time without spiking server load.
* Originals trash with one-click **Restore** or **Restore &amp; protect** (the latter puts the original file back AND marks the attachment do-not-touch so it stays out of future runs).
* "Tidy" column on the Media Library list view, showing per-row state at a glance: protected, processed, has-backup, conversion-skipped.
* Per-row actions on image attachments (live AJAX, no page reload):
    * **Protect** / **Unprotect** — toggle the do-not-touch flag.
    * **Optimize Now** — run the processor against this attachment immediately, ignoring the global dry-run setting.
    * **Restore Original** — restore from the trash backup if one exists.
* Bulk actions on the Media Library: **Tidy: Protect** and **Tidy: Unprotect** for marking many images do-not-touch (or releasing them) in one click.
* Attachment edit-screen meta box: protection toggle plus a preview of the last five processing-log entries (action, timestamp, format change, savings).
* Grid-mode protection toggle in the Media Library modal, so operators who never switch to list view can still mark images do-not-touch.
* Database search-and-replace when a file is renamed (PNG → WebP), with serialised-data-aware rewriting that handles raw and JSON-escaped URLs.
* Dry-run mode for both upload-time processing and the bulk runner.
* Filter hook (`tri_format_decision`) for custom format-decision logic.
* Translations: seeded English (en_GB), German, Greek, Spanish, French, Italian, Dutch, and Polish.

== Roadmap ==

* Full WP-CLI surface (`wp tidy-images scan | process | protect | restore | trash | settings`) — next milestone.
* Auto-purge of trashed originals after a configurable retention period.
* `uninstall.php` to clean up plugin options on removal (trash files left in place — your originals are safe).
* Stale-trash-record cleanup on the Trash page, for sites with backup records written by older versions of the plugin or with paths from a previous WordPress configuration.

== Installation ==

1. Upload the plugin directory to `wp-content/plugins/`.
2. Activate through the **Plugins** menu in WordPress.
3. Visit **Tidy Images** in the admin menu to configure.

If a competing image-optimization plugin is already active, you will see a notice. We do not deactivate other plugins on your behalf — that decision is yours.

== Frequently Asked Questions ==

= Does this replace Imsanity? =

It can. Imsanity handles oversized dimensions well but does not act on file size alone and offers no restore path. Tidy Resize Images handles both, plus format conversion, plus an originals trash. Deactivate Imsanity before activating this plugin to avoid duplicate processing.

= Will my originals be lost? =

No, unless you explicitly disable backups. By default, every original we modify is moved to `wp-content/uploads/tri-trash/{year}/{month}/` and can be restored from the admin Trash page (Tidy Images → Trash).

= Is AVIF supported? =

Yes, where the host's PHP install supports it (GD with libavif, or Imagick with libheif/libavif). The plugin detects support at runtime and falls back to WebP otherwise.

== Changelog ==

See CHANGELOG.md in the plugin directory for the full version history.
