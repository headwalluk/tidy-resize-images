<?php
/**
 * Bulk admin page.
 *
 * Operator-driven bulk runner. Renders a control panel with two
 * "start" buttons (dry-run / live), a stop button, progress totals,
 * and an append-only log table that the JS driver populates from
 * AJAX responses.
 *
 * The actual work is done by `Bulk_Processor::run_batch()`, called
 * via `wp_ajax_tri_bulk_step` in batches of 5 attachments per
 * request. JS loops until the runner reports `done=true`.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', 'tidy-resize-images' ) );
}

$tri_bp         = new Bulk_Processor();
$tri_candidates = $tri_bp->count_candidates();
$tri_settings   = get_plugin()->get_settings();
$tri_dry_run    = (bool) $tri_settings->get( OPT_BEHAVIOUR_DRY_RUN );
$tri_backup     = (bool) $tri_settings->get( OPT_BEHAVIOUR_BACKUP_ORIGINALS );

// --- Page heading + intro ------------------------------------------------.

printf(
	'<div class="wrap tri-bulk"><h1>%s</h1>',
	esc_html__( 'Tidy Images — Bulk', 'tidy-resize-images' )
);

printf(
	'<p class="tri-help">%s</p>',
	esc_html__( 'Process existing attachments in your Media Library. The runner scans for image attachments that are not protected and have not previously been touched by this plugin, then resizes / converts / recompresses them according to your Settings.', 'tidy-resize-images' )
);

// --- Pre-run warning -----------------------------------------------------.

printf(
	'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
	esc_html__( 'Before running:', 'tidy-resize-images' ),
	esc_html__( 'mark logos and brand artwork as "do not touch" (Media Library row action — landing in M8). Auto mode applies the same compression rules to every attachment regardless of visual sensitivity. Always run a dry-run first to preview decisions.', 'tidy-resize-images' )
);

// --- Status panel --------------------------------------------------------.

printf(
	'<div class="tri-bulk-status" style="margin-top:20px;padding:16px;background:#fff;border:1px solid #ccd0d4;">'
	. '<p><strong>%1$s</strong> <span class="tri-bulk-candidate-count">%2$d</span></p>'
	. '<p class="tri-help">%3$s</p>'
	. '<p>%4$s <code>%5$s</code> &nbsp; %6$s <code>%7$s</code></p>'
	. '</div>',
	esc_html__( 'Candidates ready to process:', 'tidy-resize-images' ),
	(int) $tri_candidates,
	esc_html__( 'Excludes attachments marked "do not touch" and those previously processed by this plugin.', 'tidy-resize-images' ),
	esc_html__( 'Dry-run setting:', 'tidy-resize-images' ),
	esc_html( $tri_dry_run ? __( 'on (use "Run dry" only)', 'tidy-resize-images' ) : __( 'off', 'tidy-resize-images' ) ),
	esc_html__( 'Backup originals:', 'tidy-resize-images' ),
	esc_html( $tri_backup ? __( 'enabled', 'tidy-resize-images' ) : __( 'disabled — modifications will be irreversible', 'tidy-resize-images' ) )
);

// --- Action buttons ------------------------------------------------------.

printf(
	'<p style="margin-top:20px;">'
	. '<button type="button" class="button tri-bulk-start" data-mode="dry"%1$s>%2$s</button> '
	. '<button type="button" class="button button-primary tri-bulk-start" data-mode="live"%1$s>%3$s</button> '
	. '<button type="button" class="button tri-bulk-stop" disabled>%4$s</button>'
	. '</p>',
	0 === (int) $tri_candidates ? ' disabled' : '',
	esc_html__( 'Run dry (preview)', 'tidy-resize-images' ),
	esc_html__( 'Run live (modify files)', 'tidy-resize-images' ),
	esc_html__( 'Stop', 'tidy-resize-images' )
);

// --- Progress + totals ---------------------------------------------------.

printf(
	'<div class="tri-bulk-progress" style="margin-top:16px;">'
	. '<p class="tri-bulk-status-line">%1$s</p>'
	. '<div style="background:#f0f0f1;height:20px;border:1px solid #ccd0d4;border-radius:3px;overflow:hidden;">'
	. '<div class="tri-bulk-bar" style="background:#2271b1;height:100%%;width:0%%;transition:width .25s;"></div>'
	. '</div>'
	. '<table class="tri-cap-table" style="margin-top:12px;width:auto;">'
	. '<tbody>'
	. '<tr><td>%2$s</td><td><strong class="tri-bulk-examined">0</strong></td></tr>'
	. '<tr><td>%3$s</td><td><strong class="tri-bulk-changed">0</strong></td></tr>'
	. '<tr><td>%4$s</td><td><strong class="tri-bulk-skipped">0</strong></td></tr>'
	. '<tr><td>%5$s</td><td><strong class="tri-bulk-errored">0</strong></td></tr>'
	. '<tr><td>%6$s</td><td><strong class="tri-bulk-saved">0 B</strong></td></tr>'
	. '</tbody></table>'
	. '</div>',
	esc_html__( 'Idle. Press a Run button to begin.', 'tidy-resize-images' ),
	esc_html__( 'Examined', 'tidy-resize-images' ),
	esc_html__( 'Changed', 'tidy-resize-images' ),
	esc_html__( 'Skipped (planned / discarded / memo)', 'tidy-resize-images' ),
	esc_html__( 'Errored', 'tidy-resize-images' ),
	esc_html__( 'Bytes saved', 'tidy-resize-images' )
);

// --- Log table -----------------------------------------------------------.

printf(
	'<h3 style="margin-top:24px;">%1$s</h3>'
	. '<table class="wp-list-table widefat striped tri-bulk-log">'
	. '<thead><tr>'
	. '<th>%2$s</th>'
	. '<th>%3$s</th>'
	. '<th>%4$s</th>'
	. '<th>%5$s</th>'
	. '<th>%6$s</th>'
	. '</tr></thead>'
	. '<tbody></tbody>'
	. '</table>',
	esc_html__( 'Log', 'tidy-resize-images' ),
	esc_html__( 'ID', 'tidy-resize-images' ),
	esc_html__( 'Title', 'tidy-resize-images' ),
	esc_html__( 'Action', 'tidy-resize-images' ),
	esc_html__( 'Reason', 'tidy-resize-images' ),
	esc_html__( 'Saved', 'tidy-resize-images' )
);

echo '</div>';
