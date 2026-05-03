# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Trash admin page (`admin-templates/trash-page.php`) under
  Tidy Images → Trash submenu. Lists trashed attachments with
  thumbnail, original vs current size/dims, savings, trashed-at
  timestamp, and per-row Restore / Purge actions (admin-post.php with
  per-attachment nonces). Empty state surfaces an explanatory notice;
  rows where `filename_changed=true` warn the operator that DB
  references may be stale until M6 search-replace lands.
- `Admin_Hooks` registers the Trash submenu with same-slug "Settings"
  override so the auto-generated duplicate parent entry is replaced
  with a meaningful label. Asset enqueueing extended to cover the
  Trash page.
- `Trash_Manager::list_trashed()` and `Trash_Manager::count_trashed()`
  query helpers for the admin page (and future Status counts /
  WP-CLI scan).
- `Trash_Manager` class (static API): backs up an attachment's current
  file to `wp-content/uploads/tri-trash/{year}/{month}/{id}-{ts}-{basename}`,
  records a restore receipt in `_tri_backup` post meta, and provides
  `restore()` / `purge()` to reverse or discard. `backup()` is
  idempotent (no-op if a backup already exists for the attachment);
  `restore()` deletes current file + intermediate sizes, moves the
  trash file back, regenerates `_wp_attachment_metadata` (and sub-size
  files) via `wp_generate_attachment_metadata()`. DB content
  references for renamed files are deferred to M6 (Search_Replace) —
  the `filename_changed` flag in the backup record will let the Trash
  admin page warn when restoring such an attachment.
- Status tab (`admin-templates/settings-tabs/status.php`): read-only
  view of detected GD/Imagick capabilities (with a per-MIME table),
  attachment counts (total / processed / protected / has-backup /
  conversion-skipped), and the current settings hash. An
  explanatory "no processing has run yet" notice appears at the top
  while the tracking-meta-based counts are all zero, so operators
  understand that's expected during early-milestone use.
- Behaviour tab (`admin-templates/settings-tabs/behaviour.php`):
  dry-run mode toggle, EXIF stripping toggle (default on), originals
  backup toggle (default on), trash retention days input (0 disables
  auto-purge), and an always-skip MIME checkbox list with all seven
  known image MIMEs. SVG and GIF are default-checked (vector and
  animated formats are out of v1 scope).
  The DB search-replace scope controls (mentioned in the M3 tracker
  entry) are deliberately deferred to M6 — surfacing UI without the
  underlying implementation would be a foot-gun.
- Format tab (`admin-templates/settings-tabs/format.php`): lossy
  target + quality, alpha-preserving target + quality, JPEG
  recompression quality. Target dropdowns are runtime-capability-gated
  — AVIF options render with `disabled` and an explanatory tooltip
  when the host's GD/Imagick build cannot write AVIF, so operators see
  the option exists but understand why it is unavailable.
- Expert mode placeholder on the Format tab: a callout explaining that
  Expert mode (a from→to mapping matrix) is planned, with reasoning
  for why Simple/Auto mode covers most use cases.
- Limits tab (`admin-templates/settings-tabs/limits.php`): max longest
  edge (px) and max file size (bytes) inputs, both clamped to ranges
  defined by `MIN_EDGE`/`MAX_EDGE` and `MIN_BYTES`/`MAX_BYTES`. The
  byte input shows a `size_format()` helper line ("Current value: 512
  KB") so operators can sanity-check without doing arithmetic.
- Settings page restructured to load per-tab partials from
  `admin-templates/settings-tabs/<slug>.php`, falling back to a
  "coming soon" placeholder for tabs whose partial doesn't exist yet.
  Single form spans all tabs — one `Save Changes` button persists
  everything via the standard `options.php` submission flow.
- Tabbed settings page shell (`admin-templates/settings-page.php`) with
  four tabs: Limits, Format, Behaviour, Status. URL-hash navigation
  (`#limits`, `#format`, ...) so the active tab persists across reloads
  and is shareable via deep-link. Form fields land in subsequent
  milestones.
- Admin asset pipeline: `assets/admin/tri-admin.css` and
  `assets/admin/tri-admin.js`. `Admin_Hooks::enqueue_assets()` hooks
  into `admin_enqueue_scripts` and is scoped to the settings page only
  (matches the `toplevel_page_tidy-resize-images` hook suffix). The
  JS uses class-based selectors and is scoped to `.tri-settings` so we
  don't interfere with other nav-tab-wrappers in the WP admin.
- `Image_Processor::from_settings()` static factory: builds a ruleset
  by reading from a `Settings` instance (defaulting to the
  orchestrator's shared instance). Returns the same shape as
  `default_rules()`, so the processor itself doesn't care whether rules
  came from constants or from operator-saved options. `max_bytes`,
  `dry_run`, and `backup_originals` are intentionally excluded — those
  are scanner / wrapper-layer concerns.
- `Settings` class: registers every plugin option with the WordPress
  Settings API (one wp_options row per setting), with per-option
  sanitisation callbacks that clamp ints to MIN_/MAX_ ranges, validate
  target MIMEs against allow-lists, and use `filter_var(...
  FILTER_VALIDATE_BOOLEAN)` for bools. Provides `get()` with
  constant-default fallback and `all()` for the full settings array.
  Settings is wired in `Plugin::run()` to register on `admin_init`.
- Range constants `MIN_EDGE`/`MAX_EDGE`, `MIN_BYTES`/`MAX_BYTES`,
  `MIN_QUALITY`/`MAX_QUALITY`, `MIN_TRASH_RETENTION_DAYS`/`MAX_…` in
  `constants.php`.
- Static helpers `Settings::lossy_target_mimes()`,
  `Settings::alpha_target_mimes()`, `Settings::known_image_mimes()`
  for sanitisation and form rendering.
- Smoke-test runner `dev-notes/smoke-tests/processor-roundtrip.php`:
  exercises `plan()` and `execute()` against three synthetic images
  (large alpha PNG, tiny JPEG, SVG) covering the main branches of the
  decision tree. Run with `wp eval-file <path>`.
- `Capabilities` class: runtime detection of GD/Imagick and per-MIME
  read+write support for JPEG, PNG, WebP, AVIF, GIF, and HEIC.
  Used by the image processor to gate AVIF/HEIC behaviour and by the
  future Status tab and `wp tidy-images caps` command.
- `Image_Processor::plan()`: takes a source path and a ruleset, returns
  a Plan describing what *would* happen — no filesystem mutation. Plan
  shape is `{action, target_mime, quality, max_edge, strip_exif, reason,
  source_meta}`. The decision flows through the new `tri_format_decision`
  filter so external code (e.g. a future Expert mode mapping matrix)
  can override without subclassing. Includes an early MIME detector
  (wp_check_filetype with mime_content_type fallback) so excluded
  vector formats like SVG are caught before any raster decode attempt.
- `Skip_Memo` class: per-attachment memoisation for the
  "result-larger-than-source" rule. Stores `_tri_conversion_skipped`
  meta containing reason, attempted target MIME, attempted-at timestamp
  (Y-m-d H:i:s T), and a settings hash. `Skip_Memo::should_skip(id,
  hash)` returns true only when a memo exists AND its hash matches the
  current settings hash — operator changes to encoding settings
  invalidate memos automatically.
- `Image_Processor::settings_hash()`: pure static helper that hashes
  the subset of rules affecting encoded output bytes (lossy/alpha
  targets and qualities, jpeg quality, strip_exif). Excludes max_edge
  (only relevant when no resize occurred — already implied by the memo
  trigger), excluded_mimes, and dry-run flag.
- `Image_Processor::execute()`: carries out the transform described by
  a Plan. Resizes (if max_edge set), encodes to target_mime at the
  configured quality, optionally strips EXIF — all into a temp path
  under `get_temp_dir()`. Implements the "result-larger-than-source AND
  no dimension change → discard" rule (the discarded-output Result has
  `committed=false` and `reason='result_larger_than_source'`, ready for
  the M2.5 memoisation marker). Returns a Result with success flag,
  committed flag, output path/meta, savings (bytes + percent), and
  error message on hard failure.
- `Image_Processor::default_decision()`: the Simple/Auto branch table.
  PNG-with-alpha → alpha-target; PNG-opaque/JPEG/WebP/HEIC/static-GIF →
  lossy-target (with HEIC capability-gated and AVIF auto-falling-back to
  WebP if the host can't write AVIF); animated GIF and SVG → skip.
- `Image_Processor::default_rules()`: compiles the default ruleset from
  the `DEF_*` constants. M3 will add `from_settings()` to read from
  `wp_options`.
- `Image_Library` class: thin wrapper around `WP_Image_Editor` with raw
  GD/Imagick reach-through. Exposes:
  - `get_meta()` — mime, dims, bytes, has_alpha, is_animated. Alpha is
    detected by parsing format headers (PNG IHDR, WebP VP8X) so we don't
    pay the full decode cost just to answer the question.
  - `resize( $max_edge )` — proportional, no-op if already within limit.
  - `encode( $mime, $quality, $strip_exif, $tmp_path )` — writes the
    transformed image to a temp path. Strips EXIF/XMP/IPTC via
    `Imagick::stripImage()` when requested (GD encoding strips by default).
  - `close()` — release resources.
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
