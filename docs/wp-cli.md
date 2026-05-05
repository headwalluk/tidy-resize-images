# WP-CLI command reference

Every admin-UI capability is also available through WP-CLI. Useful for
SSH-only sites, scripted pipelines, and operators who prefer the
shell.

The plugin registers three command namespaces under `tidy-images`:

- Top-level commands — `caps`, `scan`, `protect`, `unprotect`,
  `process`, `restore`
- `tidy-images trash` — `list`, `purge`
- `tidy-images settings` — `get`, `set`

Read-only commands (`scan`, `caps`, `trash list`, `settings get`)
support `--format=table|json|csv|yaml` and, where applicable,
`--format=count|ids` via the standard `WP_CLI\Utils\format_items`
formatter.

## Top-level commands

### `wp tidy-images caps`

Show detected GD / Imagick capabilities for image processing —
which encoders the host can read and write.

```bash
wp tidy-images caps
wp tidy-images caps --format=json
```

Output rows: per-MIME `gd | imagick | any` plus an `(extension)` row
showing whether the underlying PHP extensions are present at all.
AVIF and HEIC are commonly absent from older GD builds; the plugin
gates AVIF target writes and HEIC source reads on this detection.

### `wp tidy-images scan`

Show the pool of attachments that would be processed by a bulk run —
images that aren't protected and haven't been processed yet.

```bash
wp tidy-images scan                       # first 10, table
wp tidy-images scan --limit=50 --format=json
wp tidy-images scan --format=count        # just the candidate count
wp tidy-images scan --format=ids          # space-separated IDs (good for piping)
```

`--format=count` is the cheapest variant — single SQL count, no
per-row data fetched.

### `wp tidy-images process`

The workhorse. Processes one or more attachments, mutating by default.

```bash
wp tidy-images process 1234
wp tidy-images process 1234 1235 1236
wp tidy-images process 1234 --dry-run
wp tidy-images process --all
wp tidy-images process --all --limit=100
wp tidy-images process --all --batch-size=50 --no-dry-run
```

Flags:

- `[<id>...]` — explicit attachment IDs to process. Mutually
  exclusive with `--all`.
- `[--all]` — process every candidate the scan returns, batched.
- `[--dry-run]` — plan the work without mutating files or DB.
- `[--no-dry-run]` — force mutation even if the site setting has
  dry-run enabled. Resolution order: explicit `--dry-run` /
  `--no-dry-run` on the command line wins, otherwise the site's
  Settings → Behaviour → Dry run value applies.
- `[--limit=<n>]` — cap how many attachments `--all` processes in
  this invocation (`0` = no cap, the default).
- `[--batch-size=<n>]` — per-batch size when looping in `--all`
  mode (default 20).

Per-row output for `--all` looks like:

```
  #1234 my-image -> committed (committed) saved=12345
  #1235 logo -> skipped (protected) saved=0
  #1236 banner -> discarded (result_larger_than_source) saved=-456
```

The summary line at the end totals `examined / changed / skipped /
errored / bytes_saved` and surfaces dry-run mode when active.

### `wp tidy-images protect <id>...`
### `wp tidy-images unprotect <id>...`

Mark or clear the do-not-touch flag on one or more attachments.
Protected attachments are skipped by every processing path.

```bash
wp tidy-images protect 1234 1235 1236
wp tidy-images unprotect 1234
```

Useful in pipelines:

```bash
# Protect every image with "logo" in the title.
wp post list --post_type=attachment --s=logo --field=ID | xargs wp tidy-images protect
```

### `wp tidy-images restore <id>`

Restore an attachment to its pre-processing state from the trash
backup. Reverses the on-disk file swap and any DB URL rewrites that
were applied when the format changed.

```bash
wp tidy-images restore 1234
```

Errors out if the attachment has no backup record (i.e. it was never
processed by this plugin, or the backup has been purged).

## `wp tidy-images trash`

### `wp tidy-images trash list`

List attachments that currently have a trash backup record.

```bash
wp tidy-images trash list
wp tidy-images trash list --limit=200 --format=json
wp tidy-images trash list --format=count    # how many backups exist?
wp tidy-images trash list --format=ids      # space-separated IDs
```

### `wp tidy-images trash purge`

Delete trash backup files and clear the per-attachment record.

```bash
wp tidy-images trash purge 1234       # one attachment, no prompt
wp tidy-images trash purge --all --yes
```

Single-id purge runs unprompted by design (destructive ops are
deliberate; you typed the ID). `--all` requires `--yes` to skip the
confirmation prompt.

## `wp tidy-images settings`

### `wp tidy-images settings get`

Read current settings.

```bash
wp tidy-images settings get                    # all settings, table
wp tidy-images settings get max_edge           # one value (short key)
wp tidy-images settings get tri_limits_max_edge  # one value (full key)
wp tidy-images settings get --format=json
```

Both short keys (e.g. `max_edge`) and full `wp_options` names (e.g.
`tri_limits_max_edge`) are accepted.

### `wp tidy-images settings set <key> <value>`

Write a setting. Values route through the same sanitisers the admin
UI uses, so out-of-range numbers get clamped, unknown enum values
get rejected, etc.

```bash
wp tidy-images settings set max_edge 2560
wp tidy-images settings set lossy_target image/webp
wp tidy-images settings set lossy_quality 80
wp tidy-images settings set strip_exif true
wp tidy-images settings set excluded_mimes image/svg+xml,image/gif
wp tidy-images settings set dry_run 1
```

Array values (e.g. `excluded_mimes`) take a comma-separated list.
Booleans accept any of `1 / 0 / true / false / yes / no / on / off`.

### Settings keys

| Short key              | Full `wp_options` name                  | Type   |
| ---------------------- | --------------------------------------- | ------ |
| `max_edge`             | `tri_limits_max_edge`                   | int    |
| `max_bytes`            | `tri_limits_max_bytes`                  | int    |
| `lossy_target`         | `tri_format_lossy_target`               | string |
| `lossy_quality`        | `tri_format_lossy_quality`              | int    |
| `alpha_target`         | `tri_format_alpha_target`               | string |
| `alpha_quality`        | `tri_format_alpha_quality`              | int    |
| `jpeg_quality`         | `tri_format_jpeg_quality`               | int    |
| `dry_run`              | `tri_behaviour_dry_run`                 | bool   |
| `strip_exif`           | `tri_behaviour_strip_exif`              | bool   |
| `backup_originals`     | `tri_behaviour_backup_originals`        | bool   |
| `trash_retention_days` | `tri_behaviour_trash_retention_days`    | int    |
| `excluded_mimes`       | `tri_behaviour_excluded_mimes`          | array  |
| `sr_posts`             | `tri_behaviour_sr_posts`                | bool   |
| `sr_postmeta`          | `tri_behaviour_sr_postmeta`             | bool   |

## Pipeline recipes

A few patterns that compose well:

```bash
# How big is the candidate pool right now?
wp tidy-images scan --format=count

# Process exactly the next 100 candidates.
wp tidy-images process --all --limit=100 --no-dry-run

# Pipe scan results to xargs.
wp tidy-images scan --limit=20 --format=ids | xargs wp tidy-images process

# Restore everything that has a backup.
wp tidy-images trash list --format=ids | xargs -n 1 wp tidy-images restore

# Dump full settings to JSON for backup.
wp tidy-images settings get --format=json > tri-settings.json
```
