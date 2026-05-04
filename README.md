# Tidy Resize Images

![Version](https://img.shields.io/badge/version-0.4.1-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)
![License](https://img.shields.io/badge/license-GPLv2%2B-green.svg)

A WordPress plugin for keeping the Media Library lean. It resizes oversized
uploads, converts unsuitable file formats, and recompresses bloated files —
with originals safely backed up and a dry-run preview.

## Who it's for

- **Site operators** who want their Media Library to stop growing without
  bound, without giving up the ability to undo a change.
- **Developers** who want a small, hookable plugin they can extend (custom
  format-decision logic, bulk-processing integrations, etc.) instead of
  fighting a large SaaS-coupled image optimizer.

## What's in v0.4.1

- Settings UI (limits, format targets, behaviour, capability status)
- Image Processor with a format-decision tree (PNG/JPEG/WebP/AVIF/HEIC/GIF) and a `tri_format_decision` filter for custom rules
- **Smart format selection for lossy sources**: JPEGs and WebPs convert to your preferred lossy target by default; if the conversion would yield a larger file, the orchestrator automatically falls back to source-format recompression before giving up
- Originals Trash with **Restore** and **Restore & protect** actions (the latter puts the original back AND marks the attachment do-not-touch)
- Upload-time hook (resize + convert + recompress new uploads)
- Database search-and-replace, serialised-data-aware, when filenames change
- Bulk Processor with admin AJAX runner + daily cron variant
- "Tidy" column on the Media Library list view — per-row state icons for protected / processed / has-backup / conversion-skipped
- Row actions on image attachments (live AJAX, no page reload): **Protect** / **Unprotect**, **Optimize Now**, **Restore Original**
- Bulk actions on the Media Library: **Tidy: Protect** / **Tidy: Unprotect**
- Attachment edit-screen meta box: protection toggle and a preview of the last five processing-log entries
- Grid-mode protection toggle in the Media Library modal (for operators who never switch to list view)
- Translations: seeded English (en_GB), German, Greek, Spanish, French, Italian, Dutch, and Polish

## In development

- WP-CLI commands (`wp tidy-images scan | process | protect | restore | trash | settings`)
- Auto-purge of trashed originals after a configurable retention period
- `uninstall.php` to clean up plugin options on removal (trash files left intact)
- Stale-trash-record cleanup helpers on the Trash page

Operator and developer docs land in `docs/` closer to v1.0.

## Requirements

- WordPress 6.2 or later
- PHP 8.3 or later
- GD or Imagick PHP extension (most hosts have both)
- WebP support (universal); AVIF support optional and detected at runtime

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

Paul Faulkner — [headwall-hosting.com](https://headwall-hosting.com/)
