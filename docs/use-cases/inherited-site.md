# Use case — inherited site, full of old/oversized images

You've taken on a WordPress site whose `wp-content/uploads/` folder
has grown to gigabytes over the years. The original developer had no
optimisation pipeline; uploads went straight to disk at whatever size
the camera or stock library produced. You want to bring the library
under control without breaking the live content references.

This is the workflow Tidy Resize Images was built for.

## Before you start

1. **Take a full backup of the site.** The plugin keeps every original
   in its trash directory and provides a restore button, but a
   bird's-eye site backup is your insurance policy in case of operator
   error. Disk snapshot, hosting backup, BackWPup — whatever you
   normally use.
2. **Deactivate any other image-optimiser plugin** before you start
   the bulk run. Imsanity, EWWW, ShortPixel, Smush, Optimole,
   reSmush.it, TinyPNG, and Converter for Media all hook the upload
   pipeline. The plugin surfaces a notice when it detects one active,
   but never auto-deactivates — that's the operator's call. If you
   want them to keep handling new uploads after this round, reactivate
   when the bulk run is finished.
3. **Audit your settings.** Visit **Tidy Images → Settings**. The
   defaults (WebP target, 2560 px max edge, quality 80) are good for
   most sites, but tweak if your site has different needs. For
   example, photographic-portfolio sites often prefer a higher quality
   knob; print-style sites with detailed line art may want to keep
   PNG as the alpha target.

## Step 1 — Protect logos and brand artwork

Before letting the bulk runner loose, walk through the Media Library
and **mark anything that must not be re-encoded** as protected:

- The site logo (often in PNG with transparency)
- Sponsor / partner logos
- Vector-style illustrations rasterised to PNG
- Screenshots and UI captures (compress poorly as WebP)
- Any image where exact pixels matter for legal or brand reasons

Two ways:

- **Single-image protection** — open the attachment in the Media
  Library, tick **Tidy: protect from processing**, save.
- **Bulk protection** — Media Library list view → tick a row or two
  → choose **Protect** from the bulk actions dropdown → Apply.

You can also protect via WP-CLI on the server:

```bash
wp tidy-images protect 123 124 125
```

A protected attachment is invisible to every processing path
(uploads, bulk page, scheduled cron, `wp tidy-images process`).

## Step 2 — Open the bulk page

Navigate to **Tidy Images → Bulk**. You'll see:

- The **candidate count** — every image attachment that isn't
  protected and hasn't already been processed by this plugin.
- A **pre-run banner** reminding you to mark sensitive assets first.
- Two start buttons: **Run dry** (preview, mutates nothing) and
  **Run live** (the real thing).
- A **Stop** button (initially disabled).
- Live counters for examined / changed / skipped / errored / bytes
  saved, plus a recent-activity log table.

Recommended first step: click **Run dry** with a candidate count of
~20–50 attachments. The log shows what *would* happen for each: which
target format, what reason, projected savings. Use this to sanity-
check the plan before committing.

## Step 3 — Run live

Click **Run live**. A confirmation dialog appears (originals will be
backed up to Trash unless you've disabled backups in Settings →
Behaviour). Confirm, and the runner starts.

What you'll see:

- The status line shows `Processing… (batch N)` with a spinner while
  each AJAX batch is in flight (5 attachments per batch, typically
  ~30–60 seconds depending on image size and server speed).
- After each batch, the log table appends one row per attachment
  with a colour-coded action badge:
  - **committed** (green) — the file was successfully replaced
  - **discarded** (orange) — the converted output was *larger* than
    the source, so the plugin discarded the result. A skip-memo is
    recorded so subsequent runs don't re-attempt the same conversion
    under the same settings.
  - **skipped** (grey) — the attachment was protected, the MIME was
    on the excluded list, or a previous run already memoised a skip.
  - **errored** (red) — something went wrong; the per-row reason
    column tells you what.
- The progress bar fills as more attachments are examined.
- Counters at the top tick up. **Bytes saved** is the cumulative
  parent-file delta across the whole run.
- The log table caps at the last 200 rows; older rows fall off the
  top as new ones arrive. The summary counters keep the running
  totals; per-attachment history lives in postmeta.

For a really large library (10,000+ attachments), running flat-out at
the default browser batch size of 5 takes hours — typically several
days for the largest sites. Leave the page open, ideally on a
desktop machine that won't sleep, and let it work through. The Stop
button halts cleanly between batches; you can resume later from where
you left off (the scan picks up on the next ID after the last
processed one).

## Step 4 — Verify on a sample

After a hundred or so attachments have been processed, visit a few
posts on the front-end and check that images render correctly. The
plugin rewrites database references to renamed files (JPEG → WebP),
but custom code in themes or plugins that constructs image URLs
manually may not benefit from the rewrite — that's where you'd see
broken images.

If you find broken references in custom code, options are:
- Restore that one attachment from trash (the row's URL → restores
  the original JPEG, reverses the URL rewrite for that image)
- Adjust the custom code to handle the new file extension
- Mark the attachment as protected and restore it, so future runs
  don't re-encode

## Step 5 — Manage the trash

Once you're confident the run looks good, leave the trash directory
populated for a while. Restore is one click for any individual
attachment via the **Tidy Images → Trash** admin page.

When you want to reclaim disk space, purge:

```bash
wp tidy-images trash list --format=count   # how many backups exist?
wp tidy-images trash purge --all --yes     # delete all backup files
```

There's no admin-UI bulk-purge — destructive ops are deliberate by
design. Single-row purge is fine via the trash page; site-wide purge
goes through WP-CLI.

## Step 6 — The scheduled cron (optional)

The plugin registers a daily WP-cron event that processes a small
batch (`DEF_CRON_BATCH_SIZE = 20` by default) without operator
involvement. For a giant library, this is glacial — full coverage
takes years. The cron is intended as a slow trickle for new uploads
that somehow bypassed the upload-time pipeline. The bulk page is the
right tool for the inherited-site case.

## Common gotchas

- **`result_larger_than_source` for many JPEGs.** If a previous
  optimiser (Imsanity, ShortPixel, etc.) already shrank these images,
  re-encoding as WebP often produces a slightly larger file. The
  plugin discards those results and records a skip-memo. Working as
  designed.
- **Theme regenerates derivatives later.** WordPress's "regenerate
  thumbnails" tools build derivatives at currently-registered sizes.
  After this plugin converts a parent JPEG to WebP, those tools
  regenerate WebP derivatives — that's fine. Old JPEG derivatives at
  size keys WP no longer registers (e.g. `foo-696x461.jpg` from a
  retired theme) are handled by the plugin's gap-fill pass: it
  regenerates them as WebP under the same size key so content
  references keep resolving.
- **Page-load slowness during a bulk run.** Each AJAX batch is a real
  HTTP request that holds a worker for tens of seconds while it
  encodes images. Front-end visitors during peak hours may notice.
  Consider running off-peak.

## When you're done

If you only wanted the plugin for the historical clean-up, you can
deactivate it after the bulk run completes. The converted files stay
in place; nothing reverts on deactivation. If you want it to keep
processing new uploads, leave it active and follow the
[new-site workflow](new-site.md) for ongoing operation.
