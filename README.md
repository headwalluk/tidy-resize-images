# Tidy Resize Images

![Version](https://img.shields.io/badge/version-0.5.0-blue.svg)
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

## Documentation

- [docs/README.md](docs/README.md) — index and quick links
- [docs/use-cases/new-site.md](docs/use-cases/new-site.md) — install on a clean WordPress
- [docs/use-cases/inherited-site.md](docs/use-cases/inherited-site.md) — bulk-update workflow for sites full of historical oversized images
- [docs/wp-cli.md](docs/wp-cli.md) — full WP-CLI command reference
- [docs/hooks-and-filters.md](docs/hooks-and-filters.md) — extension points (`tri_format_decision` and others)

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

Paul Faulkner — [headwall-hosting.com](https://headwall-hosting.com/)
