# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Project scaffolding: entry file in the root namespace, namespaced classes
  under `includes/`, constants in `constants.php`, helpers in
  `functions-private.php`.
- `Plugin` orchestrator with lazy-loaded `Admin_Hooks` collaborator and
  hook registration gated on `is_admin()` to keep front-end overhead minimal.
- Top-level admin menu entry ("Tidy Images") with placeholder settings page.
- Conflict detection: `Admin_Hooks::render_notices()` surfaces an admin
  notice when known competing image-optimization plugins (Imsanity, EWWW,
  ShortPixel, Smush, Optimole, reSmush.it, TinyPNG, Converter for Media)
  are active alongside this plugin. We never deactivate them — only inform.
- `phpcs.xml` configured against the WordPress Coding Standards with
  `tidy_resize_images` / `tri` / `Tidy_Resize_Images` prefixes.
- `readme.txt` for WordPress.org distribution, `README.md` for GitHub
  (with badges and links into `docs/`), `LICENSE` (GPLv2).
- `dev-notes/00-project-tracker.md` capturing the milestone plan and
  cross-cutting design decisions (AVIF policy, format-decision tree,
  failed-conversion memoisation, DB search-replace scope).
