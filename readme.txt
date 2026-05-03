=== Tidy Resize Images ===
Contributors: paulfaulkner
Tags: media, images, optimization, resize, webp
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep the WordPress Media Library lean. Resize oversized uploads, convert unsuitable formats, and recompress bloated files — with backups, dry-run, and full WP-CLI control.

== Description ==

Tidy Resize Images applies three classes of fix to your Media Library:

* **Oversized dimensions** — images uploaded at unnecessary resolutions are resized to a configurable maximum longest edge.
* **Oversized file size** — images that are dimensionally fine but bloated (e.g. a 4&nbsp;MB PNG that should be a 400&nbsp;KB WebP) are recompressed.
* **Unsuitable formats** — PNGs and HEICs are converted to WebP (or AVIF where supported); JPEGs are recompressed in place.

Originals are backed up to a Trash directory inside `wp-content/uploads/` and can be restored from the admin UI or WP-CLI. Attachments can be marked "do not touch" so logos and brand assets are never altered.

A dry-run mode shows you exactly what would change before you commit.

== Features ==

* Resize on upload, with the maximum longest edge configurable per upload context.
* Recompress on upload using your chosen target format.
* Bulk processor for existing Media Library content, with progress reporting and resume.
* Originals trash with one-click restore.
* "Do not touch" flag per attachment, accessible from the Media Library list and edit screens.
* Database search-and-replace when a file is renamed (PNG → WebP), with serialized-data-aware rewriting.
* Dry-run mode for both upload-time and bulk processing.
* Full WP-CLI surface: `wp tidy-images scan | process | protect | restore | trash | settings`.
* Filter hook for custom format-decision logic (e.g. an Expert mode mapping matrix).

== Installation ==

1. Upload the plugin directory to `wp-content/plugins/`.
2. Activate through the **Plugins** menu in WordPress.
3. Visit **Tidy Images** in the admin menu to configure.

If a competing image-optimization plugin is already active, you will see a notice. We do not deactivate other plugins on your behalf — that decision is yours.

== Frequently Asked Questions ==

= Does this replace Imsanity? =

It can. Imsanity handles oversized dimensions well but does not act on file size alone and offers no restore path. Tidy Resize Images handles both, plus format conversion, plus an originals trash. Deactivate Imsanity before activating this plugin to avoid duplicate processing.

= Will my originals be lost? =

No, unless you explicitly disable backups. By default, every original we modify is moved to `wp-content/uploads/tri-trash/{year}/{month}/` and can be restored from the admin UI or with `wp tidy-images restore <id>`.

= Is AVIF supported? =

Yes, where the host's PHP install supports it (GD with libavif, or Imagick with libheif/libavif). The plugin detects support at runtime and falls back to WebP otherwise.

== Changelog ==

See CHANGELOG.md in the plugin directory for the full version history.
