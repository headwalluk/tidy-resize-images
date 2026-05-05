# Action hooks and filters

The plugin's extension surface is intentionally small. Anywhere it
decides *what to do* to an image, the decision flows through a filter
so theme or plugin code can override it without subclassing or
patching.

## Filters

### `tri_format_decision`

Override the format-decision tree on a per-image basis. Fired once
per call to `Image_Processor::plan()` — that's once per attachment
when the upload pipeline, bulk runner, scheduled cron, or
`wp tidy-images process` decides what to do with a given image.

**Signature:**

```php
apply_filters(
    'tri_format_decision',
    array $decision,           // The default decision computed by Image_Processor::default_decision().
    string $source_path,       // Absolute path to the source image on disk.
    array $source_meta,        // mime, width, height, bytes, has_alpha, is_animated.
    array $rules               // Current ruleset (max_edge, lossy_target, lossy_quality, etc.).
): array;
```

**Returned array shape (Plan):**

```php
array(
    'action'      => 'convert' | 'recompress' | 'resize_only' | 'skip',
    'target_mime' => string,   // empty when action === 'skip'
    'quality'     => int,      // 0 when action === 'skip'; 1-100 otherwise
    'max_edge'    => int|null, // null = no resize required
    'strip_exif'  => bool,
    'reason'      => string,   // short machine-readable reason for logging
)
```

The filter must return the same shape. Returning anything else will
cause `Image_Processor::execute()` to behave unpredictably.

#### Example — keep PNGs as PNGs in a specific upload directory

A site that publishes downloadable diagram PNGs at
`uploads/diagrams/` doesn't want them re-encoded as WebP. Force a
`skip` for those:

```php
add_filter(
    'tri_format_decision',
    function ( array $decision, string $source_path, array $source_meta, array $rules ): array {
        if ( str_contains( $source_path, '/uploads/diagrams/' ) && 'image/png' === ( $source_meta['mime'] ?? '' ) ) {
            return array(
                'action'      => 'skip',
                'target_mime' => '',
                'quality'     => 0,
                'max_edge'    => null,
                'strip_exif'  => false,
                'reason'      => 'diagram_directory_skip',
            );
        }
        return $decision;
    },
    10,
    4
);
```

#### Example — higher quality for the photography portfolio category

Bump JPEG quality from 82 to 92 for images attached to posts in a
specific category:

```php
add_filter(
    'tri_format_decision',
    function ( array $decision, string $source_path, array $source_meta, array $rules ): array {
        if ( 'image/jpeg' !== ( $source_meta['mime'] ?? '' ) ) {
            return $decision;
        }

        // Find the parent post for this attachment by source path…
        // (Helper omitted for brevity. In practice, look it up via attachment ID.)
        $parent_id = my_attachment_parent_for_path( $source_path );

        if ( $parent_id && has_category( 'photography', $parent_id ) ) {
            $decision['quality'] = 92;
            $decision['reason'] .= '_portfolio_quality_bump';
        }

        return $decision;
    },
    10,
    4
);
```

#### Example — reject targets the host can't actually write

If your filter forces `target_mime` to AVIF but the server's GD or
Imagick build doesn't support AVIF encoding, the plugin will fall
back to WebP and tag the reason with `_avif_unsupported_fallback_to_webp`.
You can detect the same condition yourself if you want richer
control:

```php
add_filter(
    'tri_format_decision',
    function ( array $decision ): array {
        if ( 'image/avif' === $decision['target_mime'] ) {
            $caps = new \Tidy_Resize_Images\Capabilities();
            if ( ! $caps->supports( 'image/avif' ) ) {
                // Make this an explicit skip rather than letting it
                // silently fall back to WebP.
                $decision['action'] = 'skip';
                $decision['reason'] = 'avif_unavailable_no_fallback_wanted';
            }
        }
        return $decision;
    }
);
```

## Action hooks

The plugin currently doesn't fire any custom actions. The `commit`
phase (file swap, database rewrite, postmeta writes) all happen in
`Attachment_Processor::commit()` as a single transactional unit; if
you need to react to a successful processing event, hook into the
WordPress core `wp_update_attachment_metadata` action — that fires
near the end of the commit and gets you the post-commit metadata
array.

If you have a use case that needs a dedicated action (e.g. a
`tri_after_commit` so external code can mirror processed files
elsewhere), open an issue with a description of the workflow.

## Constants worth knowing

These aren't extension points so much as inspection points, but they
come up when writing filter code:

| Constant                   | Meaning                                                          |
| -------------------------- | ---------------------------------------------------------------- |
| `MIME_JPEG` / `MIME_PNG` / `MIME_WEBP` / `MIME_AVIF` / `MIME_GIF` / `MIME_HEIC` / `MIME_SVG` | Canonical MIME strings; use these instead of hardcoding |
| `META_PROTECTED`           | Postmeta key for the do-not-touch flag (`_tri_protected`)         |
| `META_BACKUP`              | Postmeta key for the trash backup record (`_tri_backup`)          |
| `META_PROCESSED_AT`        | Postmeta key for the last-processed timestamp                     |
| `META_PROCESSING_LOG`      | Postmeta key for the per-attachment processing-log array          |
| `META_CONVERSION_SKIPPED`  | Postmeta key for the skip-memo (`result_larger_than_source`)      |

All defined in `constants.php` under the `Tidy_Resize_Images`
namespace.

## A note on stability

The filter contract documented here is the only stable extension
surface. Internal classes (`Image_Processor`, `Attachment_Processor`,
`Trash_Manager`, etc.) have public methods, but those are
internal-public — they may change between releases. If you need to
extend the plugin in a way the filter doesn't cover, open an issue
so the surface can be extended deliberately.
