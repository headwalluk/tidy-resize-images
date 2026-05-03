# Project Tracker

**Version:** 0.1.0-dev
**Last Updated:** 2026-05-03
**Current Phase:** Milestone 8 (Media Library UI)
**Overall Progress:** 70%

---

## Overview

Tidy Resize Images is a WordPress plugin for keeping the Media Library lean.
It scans uploads (on arrival and retroactively in bulk) and applies three
classes of fix: oversized longest-edge dimensions, oversized file size, and
unsuitable file format. Originals are backed up to a Trash directory and can
be restored. Attachments can be marked "do not touch" so logos and brand
assets are never altered. Full WP-CLI surface mirrors the admin UI.

Built to fill the gap left by Imsanity, which handles dimensions well but
ignores file-size-only problems and offers no restore path.

---

## Active TODO Items

### Up next (Milestone 8 — Media Library UI)

Surface protection, status, and on-demand processing in the standard
Media Library list and edit screens. Also where the shared
`Attachment_Processor` extraction (M7 deferral) lands so all three
callers (Upload_Handler, Bulk_Processor, "Optimize Now" row action)
share one commit pipeline.

- [ ] Extract shared `Attachment_Processor::process( $attachment_id, $dry_run )` from the duplicated logic in Upload_Handler::commit_mutation and Bulk_Processor::commit_one
- [ ] Media Library list-table column: "Tidy" — icons for protected / processed / has-backup / conversion-skipped
- [ ] Row actions: Protect, Unprotect, Optimize Now, Restore Original (when backup exists)
- [ ] Bulk actions: Protect, Unprotect, Optimize, Restore
- [ ] Attachment edit screen meta box: protected toggle + processing log preview
- [ ] AJAX endpoints for the row actions (with nonces)

---

## Milestones

### M1 — Scaffolding & Activation ✅
Bootstrap plugin so it can activate, deactivate, show in admin menu, and
warn on conflicts. No image work yet.

- [x] Plugin header + bootstrap (entry file in root namespace; classes namespaced)
- [x] Constants file (option keys, meta keys, defaults, MIME types, conflicting-plugins list)
- [x] phpcs.xml + first clean phpcs run
- [x] Admin menu (top-level "Tidy Images" entry, `dashicons-format-image`, position 80)
- [x] Conflict detection (function `Tidy_Resize_Images\get_active_conflicts()` + `Admin_Hooks::render_notices()` rendering — collapsed from the originally-planned `Conflict_Detector` class)
- [x] Deactivate Imsanity on dev site
- [x] `readme.txt`, `README.md`, `CHANGELOG.md`, `LICENSE` in place

### M2 — Image Processor Core ✅
Pure service class. Takes a file path + a ruleset, returns a "plan" describing
what would change. No WP hooks, no DB, no I/O side effects beyond reading the
source file. Easy to call from WP-CLI for testing.

- [x] Capability detection (GD vs Imagick, AVIF/WebP/HEIC availability) — `Capabilities` class
- [x] `Image_Library` wrapper (WP_Image_Editor + raw GD/Imagick reach-through)
- [x] `Image_Processor::plan( $path, $rules ): array` — returns Plan with action/target/quality/max_edge/strip_exif/reason/source_meta
- [x] `Image_Processor::execute( $plan, $path, ?$tmp_path ): array` — returns Result with success/committed/output_path/output_meta/savings/error; temp-file output, never mutates the source
- [x] Format decision tree (Simple/Auto mode) with `tri_format_decision` filter
- [x] Failed-conversion memoisation: `Skip_Memo` class + `Image_Processor::settings_hash()`
- [x] Strip-EXIF support (Imagick `stripImage()` second pass; GD-encoded files are stripped natively)
- [x] WP-CLI smoke-test snippet at `dev-notes/smoke-tests/processor-roundtrip.php`

### M3 — Settings Page ✅
Tabbed admin page. Fully drives the processor's ruleset.

- [x] Tab: Limits (max edge, max file size; per-context overrides deferred per agreement)
- [x] Tab: Format — Simple/Auto mode (lossy + alpha targets, per-format quality, AVIF capability-gated)
- [x] Tab: Format — Expert mode placeholder ("Coming soon" callout)
- [x] Tab: Behaviour (dry-run, strip EXIF, backup originals, trash retention, MIME exclusions; search-replace scope deferred to M6 alongside the implementation)
- [x] Tab: Status (capability detection summary, attachment counts, settings-hash debug; counts placeholder note while no processing has run)
- [x] Settings-hash computation for memoisation invalidation (built in M2.5; surfaced on Status tab)
- [x] `Settings` class with sanitisation, lazy `register()` on admin_init
- [x] `Image_Processor::from_settings()` factory
- [x] `assets/admin/tri-admin.css` + `tri-admin.js` (hash-based tab nav), enqueued only on the settings page

### M4 — Trash Manager ✅
Backup originals to `wp-content/uploads/tri-trash/{year}/{month}/`, store
restore metadata in `_tri_backup` post meta, provide restore.

- [x] `Trash_Manager::backup( $attachment_id ): bool` (idempotent)
- [x] `Trash_Manager::restore( $attachment_id ): bool` (regenerates `_wp_attachment_metadata`)
- [x] `Trash_Manager::purge( $attachment_id ): bool` (idempotent)
- [x] `Trash_Manager::list_trashed()` and `count_trashed()` query helpers
- [x] Trash admin page under Tidy Images → Trash submenu, with per-row Restore + Purge actions (admin-post.php + per-attachment nonces) and warning rows when `filename_changed=true`
- [ ] Auto-purge cron (configurable retention) — deferred to M10 polish per dependency on the cron-scheduling pattern not yet in use

### M5 — Upload Handler ✅
Hook the WP upload pipeline so new uploads are processed at arrival.

**Lower-priority workflow** — operator preference is to skip upload-time
processing on their own sites and rely on the daily cron run instead
(see M7's scheduled cron variant). Build M5 cleanly per the plan but do
not gold-plate; it's the safety net, not the headline feature.

- [x] `big_image_size_threshold` — honours our `max_edge` when ours is the lower value
- [x] `wp_generate_attachment_metadata` — full backup → execute → swap → regenerate-intermediates pipeline
- [x] Trash_Manager::backup before any mutation (skipped if `backup_originals=false`)
- [x] Skip_Memo::record after a `result_larger_than_source` discard
- [x] `_tri_processed_at` meta written on commit; `filename_changed=true` set on backup record when MIME changes
- [x] Self-unhooks during regenerate-intermediates to avoid filter recursion
- [x] Trash_Manager::restore() fixed to use `wp_create_image_subsizes()` directly so it doesn't trigger Upload_Handler's filter on restored files

### M6 — DB Search & Replace ✅
Required before bulk processor can rename safely. Serialized-data-aware.
v1 scope: posts + postmeta (no options, no multisite).

- [x] `Search_Replace::rewrite( $old_url, $new_url, $scope, $dry_run ): Report`
- [x] Serialized-safe walker for postmeta (matches both raw and JSON-escaped URLs)
- [x] Dry-run report (paths, counts, sample rows)
- [x] `rewrite_attachment_rename( $id, $old_meta, $new_meta )` convenience for sub-size rename batching
- [x] Scope toggles in Behaviour tab (posts / postmeta) + `Settings::sr_scope()` helper
- [x] Trash_Manager::restore() integration for reverse rewrite (filename_changed-aware)
- [x] Our own `_tri_*` meta keys are skipped to avoid mutating backup state
- [x] `unserialize` uses `allowed_classes=false` to neutralise object-injection risk

### M7 — Bulk Processor ✅
Admin AJAX runner that processes existing attachments in batches.
**This is the primary workflow** — interactive bulk runs, scheduled cron
(below), and `wp tidy-images process --all` (M9) all share the runner.
Upload-time processing (M5) is a lower-priority safety net.

- [x] Scan: SQL query for image attachments, ID > cursor, not protected, not already-processed
- [x] `Bulk_Processor::run_batch( $cursor, $limit, $dry_run ): Result` — pure runner with bounded work; consumed by admin AJAX and the daily cron (and M9 WP-CLI)
- [x] Admin AJAX endpoints (`tri_bulk_count`, `tri_bulk_step`) with capability + nonce checks
- [x] Bulk admin page under Tidy Images → Bulk with start/stop buttons, progress bar, totals table, log table
- [x] JS driver loops AJAX calls, accumulates totals, appends colour-coded log rows, supports stop mid-run
- [x] Dry-run mode showing predicted actions without mutation
- [x] Pre-run warning banner about marking logos / brand assets as do-not-touch
- [x] Scheduled-cron variant: `wp_schedule_event` daily hook calling `run_batch` with `DEF_CRON_BATCH_SIZE=20`; activation/deactivation hooks register/unregister
- [x] WP-CLI smoke-test snippet `dev-notes/smoke-tests/bulk-runner.php`
- [ ] Cron-scheduling UI (interval/batch-size override) — deferred to M10 polish per agreement

### M8 — Media Library UI
Surface protection, status, restore in the standard Media Library.

- [ ] "Tidy" column (icon: protected / processed / has backup)
- [ ] Row actions: Protect, Unprotect, Restore Original, Optimize Now
- [ ] Bulk actions: Protect, Unprotect, Optimize, Restore
- [ ] Attachment edit screen meta box

### M9 — WP-CLI
Wrap M2–M7 functionality so the operator can run everything from the CLI.

- [ ] `wp tidy-images scan`
- [ ] `wp tidy-images process <id|--all>` with `--dry-run`, `--limit`
- [ ] `wp tidy-images protect <id...>` / `unprotect <id...>`
- [ ] `wp tidy-images restore <id>`
- [ ] `wp tidy-images trash list | purge`
- [ ] `wp tidy-images settings get | set`
- [ ] `wp tidy-images caps` — show detected GD/Imagick capabilities

### M10 — Polish
- [ ] Status tab: live counts and last-run summary
- [ ] Trash retention cron auto-purge
- [ ] uninstall.php — clean removal of options + meta (keep trash files; let user purge manually)
- [ ] Translation files scaffolding

---

## Technical Debt

_None yet. Track decisions here when we trade short-term implementation for follow-up work._

---

## Notes for Development

### Constraints

- **No `shell_exec`**, `exec`, `proc_open`, `system`, etc. PHP GD or Imagick extensions only.
- **No Composer.** Plugin must work from a clean checkout.
- **No `declare(strict_types=1)`.** Per house style.
- **Single-Entry Single-Exit functions.** Per house style.

### AVIF policy

- AVIF is opt-in per site. Default target is WebP.
- Detect support at runtime — `function_exists('imageavif')` for GD, `Imagick::queryFormats()` for Imagick.
- If operator selects AVIF target but capability missing, fall back to WebP and surface a notice.
- AVIF encode is 5–10× slower than WebP. Acceptable for bulk runs; warn for upload-time use.

### Conflict detector — known image plugins to flag

Show admin notice if any of these are active alongside ours:
- Imsanity (`imsanity/imsanity.php`)
- EWWW Image Optimizer (`ewww-image-optimizer/ewww-image-optimizer.php`)
- ShortPixel Image Optimizer (`shortpixel-image-optimiser/wp-shortpixel.php`)
- Smush (`wp-smushit/wp-smush.php`)
- Optimole (`optimole-wp/optimole-wp.php`)
- reSmush.it (`resmushit-image-optimizer/resmushit.php`)
- TinyPNG / Compress JPEG & PNG (`tiny-compress-images/tiny-compress-images.php`)
- Converter for Media (`webp-converter-for-media/webp-converter-for-media.php`)

We never deactivate them. Operator's choice — we warn and let them decide.

### Failed-conversion memoisation

When a conversion attempt produces a file *larger* than the source, we discard
the result and record:

- `_tri_conversion_skipped` meta on the attachment containing:
  - `reason` (string) — `result_larger_than_source`
  - `attempted_target` — e.g. `image/webp`
  - `attempted_at` — datetime
  - `settings_hash` — sha1 of the relevant subset of settings used

On subsequent processing runs:
- If `settings_hash` matches current settings → skip the attachment (we already know it won't help).
- If `settings_hash` differs → discard the marker, re-attempt.

Settings hash inputs: target format, quality knobs, strip-EXIF flag.
Inputs that don't affect output bytes (e.g. dry-run flag) are excluded.

### Per-attachment meta keys

| Key                       | Type     | Purpose                                  |
| ------------------------- | -------- | ---------------------------------------- |
| `_tri_protected`          | bool     | Do-not-touch flag                        |
| `_tri_processed_at`       | string   | Last touch (Y-m-d H:i:s T)               |
| `_tri_processing_log`     | array    | What was done, last N entries            |
| `_tri_backup`             | array    | `{path,url,mime,bytes,width,height,trashed_at}` for restore |
| `_tri_conversion_skipped` | array    | Failed-conversion memoisation (above)    |

### DB search-replace scope (default)

- `wp_posts.post_content` — raw string replace
- `wp_postmeta.meta_value` — serialized-safe walker
- `wp_options.option_value` — serialized-safe walker, scoped to allow-list of option names
- Multisite tables — out of scope for v1

Never touch:
- User-generated commentary in `wp_comments`
- Custom tables outside our scope
- Anything matching a configurable deny-list

### Dry-run discipline

Every destructive operation supports `--dry-run` (CLI) / dry-run setting (admin).
Dry-run must produce a report that exactly enumerates what would change, with no
side effects on disk or DB.

### Format decision logic

**Mode:** Simple / Auto only for v1. Expert mode (from→to mapping matrix) is
a UI placeholder marked "Coming soon".

**Operator inputs (Simple mode):**
- Lossy target: WebP @ quality (default 80) | AVIF @ quality (default 65, capability-gated)
- Alpha-preserving target: WebP @ quality (default 85) | AVIF @ quality | keep PNG
- JPEG recompress quality (in-place, default 82)
- Strip EXIF: yes/no

**Decision tree (default implementation):**

```
1. _tri_protected meta set?                → skip
2. MIME on never-touch list?               → skip
3. _tri_conversion_skipped with matching
   settings_hash?                          → skip
4. Source MIME determines branch:
     image/png    → has alpha? alpha-target : lossy-target
     image/jpeg   → lossy-target (recompress, may also resize)
     image/webp   → lossy-target (recompress, may also resize)
     image/heic   → lossy-target (convert, may also resize) — capability-gated
     image/gif    → static? lossy-target : skip (animated)
     image/svg+xml → skip
5. Apply max-edge resize if needed (always lossless transform)
6. Encode at chosen target/quality
7. If output_bytes >= input_bytes
      AND no dimension change occurred    → discard, write skip marker
   else                                   → commit, backup original
```

**No pixel-noise heuristic.** Operator is responsible for marking visually
sensitive assets (logos, line art, screenshots) as "do not touch" before
running bulk operations. Bulk processor surfaces this warning prominently.

**Hookability:** The decision step is a filter so a future Expert mode (or
third-party plugin) can override:

```php
$decision = apply_filters(
    'tri_format_decision',
    $default_decision,  // computed by Image_Processor::default_decision()
    $source_path,
    $source_meta,       // mime, width, height, bytes, has_alpha, etc.
    $rules              // current ruleset
);
```

`$decision` shape:
```php
array(
    'action'      => 'convert' | 'recompress' | 'resize_only' | 'skip',
    'target_mime' => 'image/webp',
    'quality'     => 80,
    'max_edge'    => 2560,  // null = no resize
    'strip_exif'  => true,
    'reason'      => 'auto_alpha_target',  // for logging
)
```

### HEIC handling

- Capability-gated: only attempted if Imagick reports HEIC in `queryFormats()`.
  GD does not support HEIC reading on most builds.
- Treated as a lossy source — same path as JPEG: convert to lossy-target,
  apply same "if larger, skip with memoisation" rule.
- Note: WordPress core does not allow HEIC uploads by default. HEIC files
  arrive via other plugins or manual placement. We process whatever we find.

### Source file references

- WordPress big_image filter: `wp-admin/includes/image.php` `wp_create_image_subsizes()`
- Upload pipeline: `wp-admin/includes/file.php` `wp_handle_upload()`
- Imsanity reference (do not copy): `wp-content/plugins/imsanity/`

