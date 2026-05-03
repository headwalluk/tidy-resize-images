# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Bulk_Processor` class: the headline workflow. Two static methods:
  `count_candidates()` returns the upfront total; `run_batch(
  $cursor, $limit, $dry_run )` processes up to `$limit` attachments
  with `ID > $cursor` and returns a Result containing totals, the
  next cursor, a `done` flag, and a per-attachment log.
  Cursor-based pagination is robust against deletions and resumable
  across calls. Scan filters in SQL: image MIMEs only; skips
  protected attachments and those already touched
  (`_tri_processed_at` exists). Skip-memo (`_tri_conversion_skipped`)
  is filtered in PHP per-attachment because the settings_hash lives
  inside a serialised meta. Per-attachment work mirrors
  `Upload_Handler::commit_mutation()` — backup → execute → swap →
  regenerate intermediates → search-replace if filename changed
  → mark `_tri_processed_at`. Honours dry-run.
- Smoke-test runner `dev-notes/smoke-tests/search-replace.php`:
  exercises raw + JSON-escaped post_content rewrites, serialised
  postmeta (top-level + nested), JSON-encoded postmeta, _tri_* meta
  preservation, dry-run mode, and `rewrite_attachment_rename` pair
  derivation. Run with `wp eval-file <path>`.
- `Trash_Manager::restore()` now performs a reverse search-replace
  when the backup record's `filename_changed=true` flag is set
  (i.e. the processor renamed the file during the original
  conversion). Captures pre-restore metadata before deleting the
  converted files, restores the file, then calls
  `Search_Replace::rewrite_attachment_rename()` to revert DB
  references from the converted state back to the restored state.
  Honours the operator's search-replace scope toggles via
  `Settings::sr_scope()`.

  End-to-end smoke test on dev host: 4000×3000 PNG uploaded →
  Upload_Handler converts to WebP-scaled → post manually created
  with WebP URL in content → restore() flips file back to PNG and
  rewrites the post content. Both the PNG file and the rewritten
  URL verified in the same call.

- Search-replace scope toggles on the Behaviour tab. Two checkboxes
  (`tri_behaviour_sr_posts` and `tri_behaviour_sr_postmeta`), both
  default-on, controlling which tables are rewritten when a file is
  renamed. `Settings::sr_scope()` helper returns the scope array
  ready for `Search_Replace::rewrite()` consumers (Trash_Manager,
  Bulk_Processor).
- `Search_Replace::rewrite_attachment_rename( $id, $old_meta, $new_meta, $scope, $dry_run )`:
  derives every `(old_url, new_url)` rename pair by comparing
  before/after `_wp_attachment_metadata` arrays — full-size + every
  intermediate size whose basename has changed + the WP-core
  `original_image` (when present and renamed) — then calls `rewrite()`
  for each pair and accumulates a single combined Report. The
  combined Report adds `attachment_id`, `pairs_processed`, and
  `pairs` fields. Intended for the M5 / M7 / M8 commit step when a
  processor changes a file's MIME.
- `Search_Replace` class: rewrites references to a renamed attachment
  URL across `wp_posts.post_content` and `wp_postmeta.meta_value`.
  Two URL forms handled at every string leaf — raw
  (`https://site.tld/...`) and JSON-escaped
  (`https:\/\/site.tld\/...`) — to cover values stored inside
  Elementor JSON, Gutenberg blocks, ACF flexible content, etc.
  Postmeta values are unserialised, recursively walked, and
  reserialised to preserve PHP serialisation. Our own `_tri_*`
  meta keys are skipped to avoid mutating backup state. Always
  supports a `dry_run` mode that produces the same Report shape
  with no DB writes. Returns a Report with per-table
  rows_examined / rows_changed / samples (capped at 10 per table).
  v1 scope: posts + postmeta only — `wp_options` and multisite
  tables are deferred.
- Smoke-test runner `dev-notes/smoke-tests/upload-handler.php`:
  inserts a synthetic 4000×3000 PNG with alpha + noise, triggers
  `wp_generate_attachment_metadata`, reports the post-processing
  state, runs the restore round-trip and verifies the file is
  back to .png. Run with `wp eval-file <path>`.
- `Upload_Handler` full processing pipeline on
  `wp_generate_attachment_metadata`. Pre-condition guard checks
  (context, protected flag, dry-run, skip-memo) live in
  `should_process()`; the work lives in `run_pipeline()` and
  `commit_mutation()`. Honours per-attachment protection, dry-run,
  and skip-memo settings. Backs up before mutation (when
  `backup_originals=true`); records skip-memo on
  result-larger-than-source discards; sets `filename_changed=true` on
  the backup record when MIME conversion changed the filename;
  regenerates intermediate sizes for the new file via
  `wp_create_image_subsizes()` (with self-unhook to avoid recursion);
  marks `_tri_processed_at`.
- `Upload_Handler::compute_final_path()` derives the destination path
  by swapping the source extension to match the target MIME (no
  rename when target equals source; treats `.jpg`/`.jpeg` as
  equivalent).

### Fixed
- `Trash_Manager::restore()` now calls `wp_create_image_subsizes()`
  directly instead of `wp_generate_attachment_metadata()`. The latter
  fires the `wp_generate_attachment_metadata` filter, which would
  cause `Upload_Handler::process_after_metadata()` to treat the
  just-restored file as a fresh upload and re-process it back to the
  converted format. Caught by the M5.2 end-to-end smoke test.

### Added
- Smoke-test runner `dev-notes/smoke-tests/trash-roundtrip.php`:
  exercises backup → idempotent re-backup → modify-then-restore →
  re-backup → purge against a synthetic attachment. Run with
  `wp eval-file <path>`.
- Trash POST handlers wired through admin-post.php:
  `tri_trash_restore` and `tri_trash_purge`. Each handler verifies
  capability (`manage_options`), attachment ID, and per-attachment
  nonce (`tri_trash_action_<id>`) before delegating to
  `Trash_Manager::restore()` / `::purge()`, then redirects back to
  the Trash page with a `tri_notice=` query param consumed by the
  template's flash-message rendering.
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
