<?php
/**
 * Technical settings view: robots.txt and .htaccess editors.
 *
 * @package Meyvora_SEO
 * @var array $data From Meyvora_SEO_Technical::get_view_data()
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$robots_content   = $data['robots_content'] ?? '';
$robots_warnings  = $data['robots_warnings'] ?? array();
$robots_writable  = $data['robots_writable'] ?? false;
$robots_virtual   = $data['robots_virtual'] ?? false;
$htaccess_before  = $data['htaccess_before'] ?? '';
$htaccess_wp_block= $data['htaccess_wp_block'] ?? '';
$htaccess_after   = $data['htaccess_after'] ?? '';
$htaccess_writable= $data['htaccess_writable'] ?? false;
?>
<div class="mev-technical-wrap">
	<?php settings_errors( 'meyvora_technical' ); ?>

	<!-- Robots.txt -->
	<section class="mev-technical-section">
		<h2 class="mev-technical-title"><?php esc_html_e( 'Robots.txt', 'meyvora-seo' ); ?></h2>
		<?php if ( $robots_virtual ) : ?>
			<p class="description"><?php esc_html_e( 'Robots.txt is managed virtually (via filter). Content is stored in the database.', 'meyvora-seo' ); ?></p>
		<?php else : ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: path to robots.txt file */ __( 'File: %s', 'meyvora-seo' ), Meyvora_SEO_Technical::get_robots_file_path() ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! $robots_writable ) : ?>
			<p class="notice notice-warning inline"><strong><?php esc_html_e( 'Warning:', 'meyvora-seo' ); ?></strong> <?php esc_html_e( 'The file or directory is not writable. You can copy the content and update robots.txt manually.', 'meyvora-seo' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $robots_warnings ) ) : ?>
			<div class="mev-technical-warnings">
				<?php foreach ( $robots_warnings as $w ) : ?>
					<p class="notice notice-warning inline"><?php echo esc_html( $w ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<form method="post" action="" class="mev-technical-form">
			<?php wp_nonce_field( Meyvora_SEO_Technical::NONCE_ACTION, Meyvora_SEO_Technical::NONCE_NAME ); ?>
			<div class="mev-robots-editor-wrap">
				<label for="meyvora_robots_txt" class="screen-reader-text"><?php esc_html_e( 'Robots.txt content', 'meyvora-seo' ); ?></label>
				<textarea name="meyvora_robots_txt" id="meyvora_robots_txt" class="mev-robots-textarea code" rows="14" <?php echo esc_attr( $robots_writable ? '' : 'readonly' ); ?>><?php echo esc_textarea( $robots_content ); ?></textarea>
				<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Preview:', 'meyvora-seo' ); ?></p>
				<div id="mev-robots-preview" class="mev-robots-preview code" aria-hidden="false"></div>
			</div>
			<p class="mev-robots-actions">
				<button type="button" id="mev-robots-load-default" class="button"><?php esc_html_e( 'Load default template', 'meyvora-seo' ); ?></button>
				<button type="button" id="meyvora_robots_restore" class="button"
				data-sitemap="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>"
				data-confirm="<?php esc_attr_e( 'Restore to WordPress default robots.txt? Current content will be replaced.', 'meyvora-seo' ); ?>">
				<?php esc_html_e( 'Restore default', 'meyvora-seo' ); ?>
			</button>
				<button type="submit" name="meyvora_save_robots" class="button button-primary" <?php echo esc_attr( $robots_writable ? '' : 'disabled' ); ?>><?php esc_html_e( 'Save robots.txt', 'meyvora-seo' ); ?></button>
			</p>
			<div class="mev-test-url-wrap">
				<label for="mev-test-url-path"><?php esc_html_e( 'Test URL path (e.g. /wp-admin/ or /):', 'meyvora-seo' ); ?></label>
				<input type="text" id="mev-test-url-path" class="regular-text" value="/" placeholder="/" />
				<button type="button" id="mev-test-url-btn" class="button"><?php esc_html_e( 'Test URL', 'meyvora-seo' ); ?></button>
				<span id="mev-test-url-result"></span>
			</div>
		</form>
	</section>

	<!-- .htaccess -->
	<section class="mev-technical-section">
		<h2 class="mev-technical-title"><?php esc_html_e( '.htaccess', 'meyvora-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Edit only the content outside the WordPress block. The section between # BEGIN WordPress and # END WordPress is locked.', 'meyvora-seo' ); ?></p>
		<?php if ( ! $htaccess_writable ) : ?>
			<p class="notice notice-warning inline"><strong><?php esc_html_e( 'Warning:', 'meyvora-seo' ); ?></strong> <?php esc_html_e( 'The file is not writable. Changes will not be saved.', 'meyvora-seo' ); ?></p>
		<?php endif; ?>

		<form method="post" action="" class="mev-technical-form">
			<?php wp_nonce_field( Meyvora_SEO_Technical::NONCE_ACTION, Meyvora_SEO_Technical::NONCE_NAME ); ?>
			<div class="mev-htaccess-editor-wrap">
				<div class="mev-htaccess-block mev-htaccess-before">
					<label for="meyvora_htaccess_before"><?php esc_html_e( 'Before WordPress block (editable)', 'meyvora-seo' ); ?></label>
					<textarea name="meyvora_htaccess_before" id="meyvora_htaccess_before" class="code" rows="6" <?php echo esc_attr( $htaccess_writable ? '' : 'readonly' ); ?>><?php echo esc_textarea( $htaccess_before ); ?></textarea>
				</div>
				<?php if ( $htaccess_wp_block !== '' ) : ?>
					<div class="mev-htaccess-block mev-htaccess-wp-locked">
						<label><?php esc_html_e( 'WordPress block (locked)', 'meyvora-seo' ); ?></label>
						<pre class="mev-htaccess-wp-block code"><?php echo esc_html( $htaccess_wp_block ); ?></pre>
					</div>
				<?php endif; ?>
				<div class="mev-htaccess-block mev-htaccess-after">
					<label for="meyvora_htaccess_after"><?php esc_html_e( 'After WordPress block (editable)', 'meyvora-seo' ); ?></label>
					<textarea name="meyvora_htaccess_after" id="meyvora_htaccess_after" class="code" rows="6" <?php echo esc_attr( $htaccess_writable ? '' : 'readonly' ); ?>><?php echo esc_textarea( $htaccess_after ); ?></textarea>
				</div>
			</div>
			<p>
				<button type="submit" name="meyvora_save_htaccess" class="button button-primary" <?php echo esc_attr( $htaccess_writable ? '' : 'disabled' ); ?>><?php esc_html_e( 'Save .htaccess', 'meyvora-seo' ); ?></button>
				<span class="description"><?php esc_html_e( 'Last 3 versions are backed up in the database before saving.', 'meyvora-seo' ); ?></span>
			</p>
		</form>
	</section>
</div>

