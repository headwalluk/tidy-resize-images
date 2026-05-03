# Project Tracker

**Version:** 0.1.0-dev
**Last Updated:** 2026-05-03
**Current Phase:** Milestone 5 (Upload Handler)
**Overall Progress:** 40%

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

### Up next (Milestone 5 — Upload Handler)

- [ ] `includes/class-upload-handler.php` — wires Image_Processor into the WP upload pipeline
- [ ] `wp_handle_upload_prefilter` hook — early gate on the temp-uploaded file
- [ ] `big_image_size_threshold` filter — disable WP's scaled-rotation when our max_edge is lower
- [ ] `wp_generate_attachment_metadata` hook — final pass after intermediate sizes are made
- [ ] Front-end vs admin context detection (the rules may differ later — for v1 use the same rules everywhere, structure the call to allow per-context overrides)
- [ ] Trash_Manager::backup integration before any mutation
- [ ] Skip_Memo::record after a `result_larger_than_source` discard
- [ ] WP-CLI smoke-test snippet `dev-notes/smoke-tests/upload-handler.php`

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

### M5 — Upload Handler
Hook the WP upload pipeline so new uploads are processed at arrival.

**Lower-priority workflow** — operator preference is to skip upload-time
processing on their own sites and rely on the daily cron run instead
(see M7's scheduled cron variant). Build M5 cleanly per the plan but do
not gold-plate; it's the safety net, not the headline feature.

- [ ] `big_image_size_threshold` — disable WP scaled-rotation if our limit is lower
- [ ] `wp_generate_attachment_metadata` — final pass after intermediate sizes
- [ ] Trash_Manager::backup before any mutation
- [ ] Skip_Memo::record after a `result_larger_than_source` discard

### M6 — DB Search & Replace
Required before bulk processor can rename safely. Serialized-data-aware.

- [ ] `Search_Replace::rewrite( $old_url, $new_url, $scope ): Report`
- [ ] Serialized-safe walker for postmeta / options
- [ ] Dry-run report (paths, counts, sample rows)
- [ ] Scope toggles (posts, postmeta, options, configurable allow-list)
- [ ] Update `_wp_attached_file` and `_wp_attachment_metadata` (sub-size filenames)

### M7 — Bulk Processor
Admin AJAX runner that processes existing attachments in batches.
**This is the primary workflow** — interactive bulk runs, scheduled cron
(below), and `wp tidy-images process --all` (M9) all share the runner.
Upload-time processing (M5) is a lower-priority safety net.

- [ ] Scan: query attachments matching processor criteria
- [ ] `Bulk_Processor::run_batch( $limit, $cursor ): Result` — pure runner with bounded work; reusable by admin AJAX, WP-CLI, and cron
- [ ] Admin AJAX runner that calls `run_batch` repeatedly, surfaces progress, handles stop/resume
- [ ] Dry-run mode showing predicted savings
- [ ] Per-attachment log displayed in UI
- [ ] Pre-run warning banner: "Mark logos and brand assets as 'do not touch' before bulk processing — auto mode will not protect them for you."
- [ ] **Scheduled cron variant:** `wp_schedule_event` registers a daily hook that invokes `run_batch` with a memory-safe `$limit`; idempotent so it picks up where it left off. Cron-scheduling UI deferred to M10 polish.

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

