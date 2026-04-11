<?php
/**
 * Import from Yoast SEO, Rank Math, All In One SEO: detection, estimates, dry run, batch with progress.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$import_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-import.php' : '';
if ( $import_file && file_exists( $import_file ) ) {
	require_once $import_file;
}
$can_yoast = class_exists( 'Meyvora_SEO_Import' ) && Meyvora_SEO_Import::can_import_yoast();
$can_rankmath = class_exists( 'Meyvora_SEO_Import' ) && Meyvora_SEO_Import::can_import_rankmath();
$can_aioseo = class_exists( 'Meyvora_SEO_Import' ) && Meyvora_SEO_Import::can_import_aioseo();
$has_yoast_redirects = class_exists( 'Meyvora_SEO_Import' ) && Meyvora_SEO_Import::has_yoast_redirects();
$has_rankmath_redirects = class_exists( 'Meyvora_SEO_Import' ) && Meyvora_SEO_Import::has_rankmath_redirects();
$counts = class_exists( 'Meyvora_SEO_Import' ) ? Meyvora_SEO_Import::get_estimated_counts() : array(
	'yoast'   => array( 'posts' => 0, 'redirects' => 0 ),
	'rankmath' => array( 'posts' => 0, 'redirects' => 0 ),
	'aioseo'  => array( 'posts' => 0 ),
);
$batch_size = class_exists( 'Meyvora_SEO_Import' ) ? Meyvora_SEO_Import::BATCH_SIZE : 100;
$any_detected = $can_yoast || $can_rankmath || $can_aioseo;
?>
<div class="wrap meyvora-dashboard">
	<div class="mev-page-header">
		<div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
				<div class="mev-page-title"><?php esc_html_e( 'Meyvora SEO', 'meyvora-seo' ); ?></div>
				<div class="mev-page-subtitle"><?php esc_html_e( 'Import from other SEO plugins', 'meyvora-seo' ); ?></div>
			</div>
		</div>
		<nav class="mev-page-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-import' ) ); ?>" class="active"><?php esc_html_e( 'Import', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
		</nav>
	</div>

	<?php if ( ! $any_detected ) : ?>
		<div class="mev-card">
			<div class="mev-card-body">
				<p class="mev-text-muted"><?php esc_html_e( 'No supported SEO plugins detected. Install Yoast SEO, Rank Math, or All In One SEO and add SEO data to your content, then return here to import.', 'meyvora-seo' ); ?></p>
			</div>
		</div>
	<?php else : ?>

		<div class="mev-card" style="margin-bottom:20px;">
			<div class="mev-card-header">
				<span class="mev-card-title"><?php esc_html_e( 'Detected plugins & estimated records', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-card-body">
				<table class="mev-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Source', 'meyvora-seo' ); ?></th>
							<th><?php esc_html_e( 'Status', 'meyvora-seo' ); ?></th>
							<th><?php esc_html_e( 'Posts to import', 'meyvora-seo' ); ?></th>
							<th><?php esc_html_e( 'Redirects', 'meyvora-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong>Yoast SEO</strong></td>
							<td><?php echo $can_yoast ? '<span style="color:var(--mev-success);">' . esc_html__( 'Detected', 'meyvora-seo' ) . '</span>' : '<span class="mev-text-muted">' . esc_html__( 'Not detected', 'meyvora-seo' ) . '</span>'; ?></td>
							<td><?php echo (int) $counts['yoast']['posts']; ?></td>
							<td><?php echo (int) $counts['yoast']['redirects']; ?> <?php echo $has_yoast_redirects ? '' : '(' . esc_html__( 'Premium', 'meyvora-seo' ) . ')'; ?></td>
						</tr>
						<tr>
							<td><strong>Rank Math</strong></td>
							<td><?php echo $can_rankmath ? '<span style="color:var(--mev-success);">' . esc_html__( 'Detected', 'meyvora-seo' ) . '</span>' : '<span class="mev-text-muted">' . esc_html__( 'Not detected', 'meyvora-seo' ) . '</span>'; ?></td>
							<td><?php echo (int) $counts['rankmath']['posts']; ?></td>
							<td><?php echo (int) $counts['rankmath']['redirects']; ?></td>
						</tr>
						<tr>
							<td><strong>All In One SEO</strong></td>
							<td><?php echo $can_aioseo ? '<span style="color:var(--mev-success);">' . esc_html__( 'Detected', 'meyvora-seo' ) . '</span>' : '<span class="mev-text-muted">' . esc_html__( 'Not detected', 'meyvora-seo' ) . '</span>'; ?></td>
							<td><?php echo (int) $counts['aioseo']['posts']; ?></td>
							<td>—</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="mev-card" id="mev-import-run-card">
			<div class="mev-card-header">
				<span class="mev-card-title"><?php esc_html_e( 'Run import', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-card-body">
				<p class="mev-field-help" style="margin-bottom:16px;"><?php esc_html_e( 'Import meta (titles, descriptions, focus keywords, noindex, canonical, OG image) and optionally redirects. Use dry run to preview without writing.', 'meyvora-seo' ); ?></p>
				<div class="mev-form-row" style="margin-bottom:16px;">
					<label class="mev-label"><?php esc_html_e( 'Source', 'meyvora-seo' ); ?></label>
					<select id="mev-import-source">
						<?php if ( $can_yoast ) : ?>
							<option value="yoast">Yoast SEO</option>
						<?php endif; ?>
						<?php if ( $can_rankmath ) : ?>
							<option value="rankmath">Rank Math</option>
						<?php endif; ?>
						<?php if ( $can_aioseo ) : ?>
							<option value="aioseo">All In One SEO</option>
						<?php endif; ?>
					</select>
				</div>
				<div class="mev-form-row" style="margin-bottom:16px;">
					<label><input type="checkbox" id="mev-import-dry-run" /> <?php esc_html_e( 'Dry run (preview only, do not write)', 'meyvora-seo' ); ?></label>
				</div>
				<div class="mev-form-row" style="margin-bottom:16px;">
					<label><input type="checkbox" id="mev-import-delete-after" /> <?php esc_html_e( 'Delete source plugin meta after import', 'meyvora-seo' ); ?></label>
				</div>
				<div class="mev-form-row" style="margin-bottom:16px;">
					<label><input type="checkbox" id="mev-import-redirects" checked /> <?php esc_html_e( 'Import redirects (Yoast Premium / Rank Math)', 'meyvora-seo' ); ?></label>
				</div>
				<button type="button" id="mev-import-start" class="mev-btn mev-btn--primary"><?php esc_html_e( 'Start import', 'meyvora-seo' ); ?></button>

				<div id="mev-import-progress" style="display:none; margin-top:20px;">
					<div class="mev-progress-bar" style="height:24px; background:var(--mev-gray-200); border-radius:var(--mev-radius-sm); overflow:hidden;">
						<div id="mev-import-progress-fill" style="height:100%; width:0%; background:var(--mev-primary); transition:width 0.3s ease;"></div>
					</div>
					<p id="mev-import-progress-text" style="margin-top:8px; font-size:13px; color:var(--mev-gray-600);"></p>
				</div>

				<div id="mev-import-summary" style="display:none; margin-top:20px; padding:16px; background:var(--mev-surface-2); border-radius:var(--mev-radius-sm);">
					<h3 style="margin:0 0 12px; font-size:16px;"><?php esc_html_e( 'Import summary', 'meyvora-seo' ); ?></h3>
					<ul id="mev-import-summary-list" style="margin:0; padding-left:20px;"></ul>
				</div>
			</div>
		</div>

	<?php endif; ?>
</div>

<script>
(function($) {
	var batchSize = <?php echo (int) $batch_size; ?>;
	var ajaxurl = typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
	var nonce = '<?php echo esc_js( wp_create_nonce( 'meyvora_seo_import_batch' ) ); ?>';

	$('#mev-import-start').on('click', function() {
		var source = $('#mev-import-source').val();
		var dryRun = $('#mev-import-dry-run').prop('checked');
		var deleteAfter = $('#mev-import-delete-after').prop('checked');
		var importRedirects = $('#mev-import-redirects').prop('checked');
		var total = 0;
		var offset = 0;
		var summary = { titles: 0, descriptions: 0, focus_keywords: 0, noindex: 0, nofollow: 0, canonical: 0, og_image: 0, processed: 0, redirects: 0 };

		$('#mev-import-summary').hide();
		$('#mev-import-progress').show();
		$('#mev-import-start').prop('disabled', true);

		function runBatch() {
			$.post(ajaxurl, {
				action: 'meyvora_seo_import_batch',
				nonce: nonce,
				source: source,
				offset: offset,
				dry_run: dryRun ? 1 : 0,
				delete_after: deleteAfter ? 1 : 0,
				import_redirects: (offset === 0 && importRedirects) ? 1 : 0
			}).done(function(res) {
				if (!res.success || !res.data) {
					$('#mev-import-progress-text').text('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown'));
					$('#mev-import-start').prop('disabled', false);
					return;
				}
				var d = res.data;
				if (d.batch_counts) {
					summary.titles += d.batch_counts.titles || 0;
					summary.descriptions += d.batch_counts.descriptions || 0;
					summary.focus_keywords += d.batch_counts.focus_keywords || 0;
					summary.noindex += d.batch_counts.noindex || 0;
					summary.nofollow += d.batch_counts.nofollow || 0;
					summary.canonical += d.batch_counts.canonical || 0;
					summary.og_image += d.batch_counts.og_image || 0;
					summary.processed += d.batch_counts.processed || 0;
				}
				if (d.redirects !== undefined) summary.redirects = d.redirects;
				if (d.total !== undefined) total = d.total;
				offset = d.offset !== undefined ? d.offset : offset + batchSize;
				var pct = total > 0 ? Math.min(100, Math.round((Math.min(offset, total) / total) * 100)) : 100;
				$('#mev-import-progress-fill').css('width', pct + '%');
				var doneCount = total > 0 ? Math.min(offset, total) : summary.processed;
				$('#mev-import-progress-text').text((d.dry_run ? 'Dry run: ' : '') + 'Processed ' + doneCount + ' of ' + (total || summary.processed) + ' posts…');
				if (d.done) {
					$('#mev-import-progress-text').text(d.dry_run ? 'Dry run complete.' : 'Import complete.');
					$('#mev-import-progress-fill').css('width', '100%');
					var list = $('#mev-import-summary-list').empty();
					list.append('<li>Titles: ' + summary.titles + '</li>');
					list.append('<li>Descriptions: ' + summary.descriptions + '</li>');
					list.append('<li>Focus keywords: ' + summary.focus_keywords + '</li>');
					list.append('<li>Noindex: ' + summary.noindex + '</li>');
					list.append('<li>Canonical: ' + summary.canonical + '</li>');
					list.append('<li>OG image: ' + summary.og_image + '</li>');
					list.append('<li>Posts processed: ' + summary.processed + '</li>');
					if (summary.redirects > 0) list.append('<li>Redirects: ' + summary.redirects + '</li>');
					$('#mev-import-summary').show();
					$('#mev-import-start').prop('disabled', false);
					return;
				}
				setTimeout(runBatch, 100);
			}).fail(function() {
				$('#mev-import-progress-text').text('Request failed.');
				$('#mev-import-start').prop('disabled', false);
			});
		}
		runBatch();
	});
})(jQuery);
</script>
