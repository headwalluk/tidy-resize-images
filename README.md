# Tidy Resize Images

![Version](https://img.shields.io/badge/version-0.2.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)
![License](https://img.shields.io/badge/license-GPLv2%2B-green.svg)

A WordPress plugin for keeping the Media Library lean. It resizes oversized
uploads, converts unsuitable file formats, and recompresses bloated files —
with originals safely backed up, dry-run preview, and full WP-CLI control.

## Who it's for

- **Site operators** who want their Media Library to stop growing without
  bound, without giving up the ability to undo a change.
- **Developers** who want a small, hookable plugin they can extend (custom
  format-decision logic, bulk-processing integrations, etc.) instead of
  fighting a large SaaS-coupled image optimizer.

## Documentation

- [Getting started](docs/getting-started.md) — install, configure, first run.
- [WP-CLI reference](docs/wp-cli.md) — the full command surface.
- [Filter hooks](docs/hooks.md) — extending behaviour from your own code.
- [Contributing](docs/contributing.md) — code standards, dev workflow.

## Requirements

- WordPress 6.2 or later
- PHP 8.3 or later
- GD or Imagick PHP extension (most hosts have both)
- WebP support (universal); AVIF support optional and detected at runtime

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

Paul Faulkner — [headwall-hosting.com](https://headwall-hosting.com/)
