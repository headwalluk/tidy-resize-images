# Tidy Resize Images

![Version](https://img.shields.io/badge/version-0.2.0-blue.svg)
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

## What's in v0.2.0

- Settings UI (limits, format targets, behaviour, capability status)
- Image Processor with a format-decision tree (PNG/JPEG/WebP/AVIF/HEIC/GIF) and a `tri_format_decision` filter for custom rules
- Originals Trash with one-click restore (Tidy Images → Trash)
- Upload-time hook (resize + convert + recompress new uploads)
- Database search-and-replace, serialised-data-aware, when filenames change
- Bulk Processor with admin AJAX runner + daily cron variant

## In development

- Media Library row actions and "Tidy" status column (target: v0.3.0)
- WP-CLI commands (target: v0.3.0)
- Auto-purge of trashed originals + translations (target: v0.3.0)

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
