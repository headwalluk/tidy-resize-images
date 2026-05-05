# Use case — brand new site

You're installing Tidy Resize Images on a fresh WordPress site. There's
no historical media baggage; you want sensible defaults so every new
upload arrives lean.

## Install and activate

1. Install the plugin (from WordPress.org, a release ZIP, or a clone
   into `wp-content/plugins/tidy-resize-images/`).
2. Activate it.
3. Visit **Tidy Images → Settings**. The defaults are designed for a
   typical content-marketing site:

   | Setting           | Default      | Notes                                     |
   | ----------------- | ------------ | ----------------------------------------- |
   | Max edge          | 2560 px      | Longest-edge cap on uploads               |
   | Lossy target      | `image/webp` | JPEG → WebP conversion on the way in      |
   | Lossy quality     | 80           | WebP quality knob                          |
   | Alpha target      | `image/webp` | PNG → WebP for transparent images          |
   | Alpha quality     | 85           | Higher than lossy because alpha is fussier |
   | JPEG quality      | 82           | Used when the operator chose JPEG as lossy target, *or* as a fallback when WebP would be larger than the source |
   | Strip EXIF        | on           | Removes camera metadata for privacy + bytes |
   | Backup originals  | on           | Originals go to `wp-content/uploads/tri-trash/` so a restore is one click |
   | Excluded MIMEs    | `image/svg+xml`, `image/gif` | Vector formats and animated GIFs are skipped |
   | Dry run           | off          | New uploads are mutated for real           |

   Tweak if you have a strong opinion (e.g. some sites prefer
   `image/avif` as the lossy target — capability-gated, opt-in).

4. Save.

## What happens on upload

Every image uploaded through the WordPress media handler now goes
through the plugin's processing pipeline:

1. **Resize** if the longest edge exceeds the cap.
2. **Convert** to the target MIME (e.g. JPEG → WebP) when the source
   format is on the conversion list.
3. **Recompress** in source format if the converted output would
   actually be *larger* than the source — common for already-tiny
   images or low-quality JPEGs.
4. **Backup** the original to the trash directory before the swap so
   you can restore later if needed.
5. **Regenerate** WordPress's intermediate sizes (thumbnails) at the
   new format / dimensions.
6. **Search-replace** any database references when the filename
   changes (rare on uploads — relevant for re-runs over existing
   content; see the inherited-site guide).

If you want to preview without mutating, flip **Dry run** on. The
upload pipeline then plans the work but writes no files.

## Logos, brand artwork, and other "do not touch" assets

Images that should never be re-encoded (logos, line art, screenshots
with sharp edges that compress poorly) need an explicit opt-out. Use
the **Protect** row action on the Media Library list view, or tick
**Tidy: protect from processing** on the attachment edit screen. A
protected attachment is skipped by every code path the plugin owns.

## Day-to-day

After the initial setup, there's nothing to do. Uploads land lean.
Check **Tidy Images → Status** occasionally for capability detection
notices (e.g. if Imagick goes away after a server upgrade).

## When to switch over to the bulk workflow

If you later import a media archive from another site, or a redesign
brings in a backlog of historical images, switch to the
[inherited-site workflow](inherited-site.md) — same plugin, different
operating mode.
