# Project Tracker

**Version:** 0.5.0
**Last Updated:** 2026-05-05
**Current Phase:** Milestone 10 (Polish) — M9 + M11 complete
**Overall Progress:** 94% (M9 + M11 done; M10 outstanding)

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

### M8 — done ✅ (split across two evenings, both 2026-05-04)

M8a (foundation + cheap wins, evening session 1):
- [x] Extract shared `Attachment_Processor::process( $id, $dry_run, $orig_metadata = null )` and rewire Upload_Handler + Bulk_Processor
- [x] Add `_tri_processing_log` meta writer (last 5 entries, newest first) inside Attachment_Processor
- [x] Media Library list-table column: "Tidy" — icons for protected / processed / has-backup / conversion-skipped
- [x] Row actions: Protect, Unprotect (AJAX-driven, nonce + capability checked)
- [x] Track auto-translated POT + per-locale `.po`/`.mo` files (8 locales)
- [x] Trash restore now clears `_tri_processed_at` + `_tri_conversion_skipped`; new "Restore & protect" button on the Trash page

M8b (rest of the Media Library surface, evening session 2):
- [x] Row action: Optimize Now — delegates to `Attachment_Processor::process( $id, false )`; ignores global dry-run; hidden on protected attachments
- [x] Row action: Restore Original — only when `_tri_backup` exists
- [x] Bulk actions: Protect, Unprotect (no bulk Restore — destructive-ops-deliberate; no bulk Optimize — Bulk page covers it)
- [x] Attachment edit-screen meta box: protected toggle + processing log preview
- [x] Grid-mode protection toggle via `attachment_fields_to_edit` (with hidden marker for unticked-checkbox detection)

### M9 — done ✅ (2026-05-05)

WP-CLI surface implemented as an autonomous sprint. Three command
namespaces — top-level, `trash`, and `settings` — wrap the existing
service classes; no behavioural changes. Read-only commands support
`--format=table|json|csv|yaml` (+ `count|ids` where applicable);
`process` defaults to mutating with `--dry-run` / `--no-dry-run`
overrides on top of the site setting. Settings keys accepted as
short form or full wp_options name; writes go through the existing
sanitisers. See CHANGELOG `[Unreleased]` for the full surface.

### M11 — done ✅ (2026-05-05)

Derivative-thumbnail rename shipped ahead of the M10 polish round
because every successful format conversion was silently leaving
orphan derivatives on disk. New surfaces:

- `Image_Processor::execute_derivative( $spec, $source_path )` — focused
  method to regenerate a single derivative at exact width × height,
  hard-cropped from centre, encoded at the target MIME.
- `Attachment_Processor::commit()` gap-fill — after
  `wp_create_image_subsizes`, regenerate any size that lived in the
  pre-rename metadata snapshot but is no longer registered with WP.
  Inject under the original size key so search-replace pairs the URL
  rewrite cleanly.
- `Trash_Manager::backup_derivatives()` — copy every old derivative
  into trash and stash the full pre-rename metadata snapshot on the
  `_tri_backup` record.
- `Trash_Manager::restore()` — replay derivative files from trash; if
  a metadata snapshot is present, write it back directly so orphan
  size entries survive the round-trip.
- Bulk page log + WP-CLI `process` summary — "+N deriv" badge / count.

### Up next (Milestone 10 — Polish)

M9 + M11 are done. Remaining polish is listed in the Milestones
section below.

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

### M8 — Media Library UI ✅
Surface protection, status, restore in the standard Media Library list
view, the modal grid edit form, and the classic attachment edit screen.

- [x] Shared `Attachment_Processor` extracted; Upload_Handler and Bulk_Processor rewired
- [x] `_tri_processing_log` meta writer inside Attachment_Processor (last 5 entries)
- [x] "Tidy" column (icons: protected / processed / has-backup / conversion-skipped)
- [x] Row actions: Protect / Unprotect / Optimize Now / Restore Original (AJAX, vanilla JS, no jQuery)
- [x] Bulk actions: Protect / Unprotect (no bulk Restore or Optimize — by design)
- [x] Attachment edit-screen meta box: protected toggle + processing log preview
- [x] Grid-mode protection toggle via `attachment_fields_to_edit`
- [x] Trash page: "Restore & protect" button alongside Restore + Purge
- [x] Translations: POT + 8 locales tracked in `languages/`

### M9 — WP-CLI ✅
Wrap M2–M8 functionality so the operator can run everything from the
CLI. The interactive Bulk page is the headline workflow; WP-CLI is for
automation / scripted use / SSH-only ops.

- [x] `wp tidy-images scan` — count_candidates + sample of the first N (`--limit`, `--format=table|json|csv|yaml|count|ids`)
- [x] `wp tidy-images process <id>... | --all` with `--dry-run` / `--no-dry-run`, `--limit`, `--batch-size`
- [x] `wp tidy-images protect <id...>` / `unprotect <id...>`
- [x] `wp tidy-images restore <id>`
- [x] `wp tidy-images trash list | purge` (`--all --yes` for batch purge)
- [x] `wp tidy-images settings get | set` (short or full key form; routes through `Settings::sanitize_*`)
- [x] `wp tidy-images caps` — detected GD/Imagick capabilities

### M10 — Polish
- [ ] Status tab: live counts and last-run summary
- [ ] Trash retention cron auto-purge
- [ ] uninstall.php — clean removal of options + meta (keep trash files; let user purge manually)
- [ ] Translation files scaffolding (build script for re-running the DeepL tool against current strings)
- [ ] Stale trash records: defensive cleanup on the Trash page. ~20 backup records on the dev site have `path`/`orig_path` strings missing the docroot prefix (`/web/...` instead of `/var/www/.../web/...`) — likely written when WP was returning shorter paths. They `restore_failed` cleanly today, but the operator can't recover from them. Two reasonable fixes: (a) detect and offer a one-click "purge stale record" button per row, (b) auto-heal by retrying with the docroot-prefixed path before declaring failure. Lean toward (a) — simpler and keeps the operator in control.
- [ ] DRY the four `now_formatted()` copies (Trash_Manager, Skip_Memo, etc. still hold private versions; functions-private.php has the canonical helper since 0.3.0)

### M11 — Derivative Thumbnail Rename ✅ (2026-05-05)

**Crucial, not nice-to-have.** Shipped ahead of M10 polish — every
successful format conversion was silently creating technical debt
without it.

When a parent file's extension changes (e.g. `foo.jpg` → `foo.webp`),
the old sized derivatives on disk become orphans:

- Content references (`foo-485x360.jpg`) still resolve — the old JPGs
  are still on disk — so nothing visibly breaks today.
- But `_wp_attachment_metadata` no longer tracks them, so Force
  Regenerate Thumbnails / `wp media regenerate` won't manage them.
- A subsequent regenerate produces a parallel `.webp` set alongside the
  orphan `.jpg` set — actively *polluting* the upload tree we're
  meant to be tidying.

**Logic** (slots into the existing convert flow in `Attachment_Processor`):

1. Snapshot the old `_wp_attachment_metadata['sizes']` array (and
   `original_image` if set) **before** converting the parent.
2. Convert parent — existing flow.
3. For each old size entry `[file, width, height, mime-type]`, ask
   `Image_Processor` to produce a `WxH` derivative from the **new**
   parent, encoded at the new target MIME. Output: `foo-WxH.<new-ext>`.
4. `Search_Replace` old derivative URL → new derivative URL across
   the configured scope (per-size, or batched).
5. `Trash_Manager::backup()` each old `.jpg` derivative. Honours
   `backup_originals` setting like the parent path.
6. Write the rebuilt `sizes` array to `_wp_attachment_metadata`.

**Key design points:**

- **Iterate the OLD metadata snapshot, not currently-registered sizes.**
  The whole point is themes that registered odd sizes (`696x461`,
  `534x462`) and are now gone. `wp_create_image_subsizes()` /
  `wp_generate_attachment_metadata()` would lose exactly the sizes
  that have content references.
- **No "is it smaller?" gate on derivatives.** That gate exists for the
  parent (recompress-fallback in 27f614d). Once the parent is renamed,
  the equivalent-named derivative *must* exist; always emit it.
- **Hard-cropped sizes:** old metadata records dimensions but not crop
  offset/focus. Resize from centre — same as WP's own regenerate. Docs
  note required.
- **Restore path:** the existing `Trash_Manager::restore()` does reverse
  search-replace and is `filename_changed`-aware for the parent. The
  backup record schema needs to grow a derivative set (or a separate
  per-derivative backup record per parent rename) so restore reverses
  the per-derivative trash + URL rewrites too.

**Tasks:**

- [x] Snapshot pre-convert sizes in `Attachment_Processor` (only when the planned action is `convert` and the target MIME differs from source) — done via the existing `$orig_metadata` parameter to `commit()`
- [x] Per-size regenerate via `Image_Processor` — `execute_derivative( $spec, $source_path )` with explicit width/height/mime/quality
- [x] Per-derivative `Search_Replace` rewrite — handled by the existing `rewrite_attachment_rename` once the gap-fill step injects orphans into the new metadata under their original size keys
- [x] Per-derivative trash backup; extend `_tri_backup` schema — `Trash_Manager::backup_derivatives()` writes a basename-keyed `derivatives` map plus the full `metadata` snapshot
- [x] Restore path: replay each derivative file from trash; if the backup carries a `metadata` snapshot, write it back directly (preserves orphan size entries on the round-trip)
- [x] Bulk page log: `+N deriv` pill appended to the action cell when `derivatives_renamed > 0`
- [x] WP-CLI `process` summary line: appends `derivatives=+N` when > 0
- [x] Docs note on hard-cropped sizes resize-from-centre behaviour — see Notes for Development → "Derivative thumbnail rename (M11)"

---

## Technical Debt

- **M11 size-key collision corner case.** When a registered size's
  *dimensions* change after an attachment was first uploaded (e.g. a
  theme bumps `medium` from 250×200 to 300×240), `wp_create_image_subsizes`
  on the renamed parent produces the new dimensions under the same
  size key, so the M11 gap-fill pass never fires for that key. Old
  content references to the old dimensions then point at filenames
  that don't exist after the rename. Pre-M11 behaviour was already
  broken in this case; M11 makes it no worse. Future fix: detect
  width/height divergence between old and new metadata under the same
  size key and regenerate at the old dimensions under a synthetic
  size key. Low priority — operators rarely change registered size
  dimensions mid-life.

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
     image/jpeg   → lossy-target (convert if different MIME, else recompress)
     image/webp   → lossy-target (convert if different MIME, else recompress)
     image/heic   → lossy-target (convert, may also resize) — capability-gated
     image/gif    → static? lossy-target : skip (animated)
     image/svg+xml → skip
5. Apply max-edge resize if needed (always lossless transform)
6. Encode at chosen target/quality
7. If output_bytes >= input_bytes AND no dimension change:
     a. If source is a writable lossy format (JPEG / WebP) AND the
        plan was a `convert` (target ≠ source MIME), retry with
        `Image_Processor::recompress_plan()` — recompress in source
        format at jpeg_quality (JPEG) or lossy_quality (WebP).
     b. If retry also yields larger-than-source, OR no fallback was
        applicable (PNG/HEIC/GIF source, or primary was already a
        recompress) → discard, write skip-memo.
     c. Otherwise → commit the fallback result, backup original.
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

### Derivative thumbnail rename (M11)

When a parent's extension changes during a convert (`foo.jpg` → `foo.webp`),
the orchestrator runs a gap-fill pass after `wp_create_image_subsizes`:
any size present in the **pre-rename** `_wp_attachment_metadata` snapshot
but absent from the regenerated metadata is regenerated via
`Image_Processor::execute_derivative()` and injected back into the
metadata under its original size key. This keeps content references
to historically-registered theme sizes (`foo-696x461.webp` etc.) live
even after themes have deregistered the size.

**Hard-cropped sizes — resize from centre.** Old metadata records
dimensions but not crop offset or focus point. `execute_derivative()`
therefore always crops from centre, matching WordPress's own
`wp_create_image_subsizes` behaviour for hard-cropped registered sizes.
Operators with focus-point requirements should mark affected
attachments as protected (`_tri_protected`) before bulk runs.

**Backup record growth.** When the rename branch fires (filename
will change) and `backup_originals` is on, `Trash_Manager::backup_derivatives()`
copies every old derivative file into the trash directory and records
two new fields on `_tri_backup`:

- `metadata` — the full pre-convert `_wp_attachment_metadata` snapshot
- `derivatives` — a basename-keyed map of `{trash_path, orig_path}`
  for every old derivative that made it into trash

`Trash_Manager::restore()` consumes both: derivatives are renamed back
into place, and if `metadata` is present it's written back directly via
`wp_update_attachment_metadata()` instead of regenerated via
`wp_create_image_subsizes()`. The direct-write path is what makes
orphan size entries survive a round-trip — regeneration would lose
them.

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

