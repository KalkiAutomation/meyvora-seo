<?php
/**
 * Setup Wizard: full-screen admin flow for initial configuration.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Wizard
 */
class Meyvora_SEO_Wizard {

	const WIZARD_DONE_OPTION = 'meyvora_seo_wizard_done';
	const TOTAL_STEPS        = 6;

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'maybe_remove_wizard_menu', 999, 0 );
		$this->loader->add_action( 'admin_init', $this, 'maybe_redirect_if_done', 5, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_wizard_assets', 10, 1 );
		add_action( 'wp_ajax_meyvora_seo_wizard_save', array( $this, 'ajax_save_step' ) );
		add_action( 'wp_ajax_meyvora_seo_wizard_skip', array( $this, 'ajax_skip_wizard' ) );
		add_action( 'wp_ajax_meyvora_seo_wizard_complete', array( $this, 'ajax_complete' ) );
		add_action( 'wp_ajax_meyvora_seo_wizard_ping', array( $this, 'ajax_ping_sitemap' ) );
	}

	/**
	 * Remove wizard from menu when completed.
	 */
	public function maybe_remove_wizard_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( (int) get_option( self::WIZARD_DONE_OPTION, 0 ) === 1 ) {
			remove_submenu_page( 'meyvora-seo', 'meyvora-seo-wizard' );
		}
	}

	/**
	 * Redirect to dashboard if visiting wizard URL when already done.
	 * On first install, redirect to wizard when activation set the transient.
	 */
	public function maybe_redirect_if_done(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_admin() ) {
			return;
		}

		$wizard_done = (int) get_option( self::WIZARD_DONE_OPTION, 0 ) === 1;
		if ( get_transient( 'meyvora_seo_wizard_redirect' ) && ! $wizard_done ) {
			delete_transient( 'meyvora_seo_wizard_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=meyvora-seo-wizard' ) );
			exit;
		}

		// Page load check, no state change; nonce not required for GET.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'meyvora-seo-wizard' ) {
			return;
		}
		if ( $wizard_done ) {
			wp_safe_redirect( admin_url( 'admin.php?page=meyvora-seo' ) );
			exit;
		}
	}

	/**
	 * Enqueue wizard CSS and JS.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_wizard_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_meyvora-seo-wizard' ) {
			return;
		}
		wp_enqueue_media();
		$css_path = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-admin.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'meyvora-seo-admin',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css',
				array(),
				MEYVORA_SEO_VERSION
			);
		}
		$wizard_css = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-wizard.css';
		if ( file_exists( $wizard_css ) ) {
			wp_enqueue_style(
				'meyvora-seo-wizard',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-wizard.css',
				array( 'meyvora-seo-admin' ),
				MEYVORA_SEO_VERSION
			);
		}
		$wizard_js = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-wizard.js';
		if ( file_exists( $wizard_js ) ) {
			wp_enqueue_script(
				'meyvora-seo-wizard',
				MEYVORA_SEO_URL . 'admin/assets/js/meyvora-wizard.js',
				array( 'jquery' ),
				MEYVORA_SEO_VERSION,
				true
			);
			wp_localize_script(
				'meyvora-seo-wizard',
				'meyvoraWizard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'meyvora_seo_wizard' ),
					'i18n'    => array(
						'saving'   => __( 'Saving…', 'meyvora-seo' ),
						'saved'    => __( 'Saved', 'meyvora-seo' ),
						'error'    => __( 'Something went wrong.', 'meyvora-seo' ),
						'pinging'  => __( 'Pinging…', 'meyvora-seo' ),
						'pingDone' => __( 'Ping sent.', 'meyvora-seo' ),
					),
				)
			);
		}
	}

	/**
	 * Render full-screen wizard.
	 */
	public function render_wizard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = $this->options->get_all();
		$org_name = (string) ( $current['schema_organization_name'] ?? '' );
		$org_logo = (int) ( $current['schema_organization_logo'] ?? 0 );
		$org_logo_url = $org_logo ? wp_get_attachment_image_url( $org_logo, 'medium' ) : '';
		?>
		<div class="mev-wizard-wrap" id="mev-wizard">
			<header class="mev-wizard-header">
				<div class="mev-wizard-brand"><?php esc_html_e( 'Meyvora SEO', 'meyvora-seo' ); ?></div>
				<a href="#" class="mev-wizard-skip" id="mev-wizard-skip"><?php esc_html_e( 'Skip wizard', 'meyvora-seo' ); ?></a>
			</header>
			<div class="mev-wizard-progress">
				<?php for ( $i = 1; $i <= self::TOTAL_STEPS; $i++ ) : ?>
					<span class="mev-wizard-dot <?php echo $i === 1 ? 'is-active' : ''; ?>" data-step="<?php echo (int) $i; ?>" aria-hidden="true"></span>
				<?php endfor; ?>
			</div>
			<div class="mev-wizard-steps">
				<!-- Step 1: Site type -->
				<div class="mev-wizard-step is-active" data-step="1" role="tabpanel">
					<h1 class="mev-wizard-title"><?php esc_html_e( 'What type of site is this?', 'meyvora-seo' ); ?></h1>
					<p class="mev-wizard-desc"><?php esc_html_e( 'We\'ll tailor defaults for your content.', 'meyvora-seo' ); ?></p>
					<div class="mev-wizard-options" role="group" aria-label="<?php esc_attr_e( 'Site type', 'meyvora-seo' ); ?>">
						<?php
						$site_type = (string) ( $current['site_type'] ?? 'blog' );
						$types = array(
							'blog'      => __( 'Blog', 'meyvora-seo' ),
							'business'  => __( 'Business', 'meyvora-seo' ),
							'ecommerce' => __( 'eCommerce', 'meyvora-seo' ),
							'news'      => __( 'News', 'meyvora-seo' ),
						);
						foreach ( $types as $value => $label ) :
							?>
							<label class="mev-wizard-option">
								<input type="radio" name="site_type" value="<?php echo esc_attr( $value ); ?>" <?php checked( $site_type, $value ); ?> />
								<span class="mev-wizard-option-label"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="mev-wizard-actions">
						<button type="button" class="mev-btn mev-btn--primary mev-wizard-next" data-next="2"><?php esc_html_e( 'Continue', 'meyvora-seo' ); ?></button>
					</div>
				</div>

				<!-- Step 2: Organization name + logo -->
				<div class="mev-wizard-step" data-step="2" role="tabpanel" hidden>
					<h1 class="mev-wizard-title"><?php esc_html_e( 'Organization', 'meyvora-seo' ); ?></h1>
					<p class="mev-wizard-desc"><?php esc_html_e( 'Name and logo used in schema and knowledge panels.', 'meyvora-seo' ); ?></p>
					<div class="mev-wizard-field">
						<label for="mev-wizard-org-name"><?php esc_html_e( 'Organization name', 'meyvora-seo' ); ?></label>
						<input type="text" id="mev-wizard-org-name" name="schema_organization_name" value="<?php echo esc_attr( $org_name ); ?>" class="mev-wizard-input" />
					</div>
					<div class="mev-wizard-field">
						<label><?php esc_html_e( 'Logo', 'meyvora-seo' ); ?></label>
						<div class="mev-wizard-logo-wrap">
							<div class="mev-wizard-logo-preview" id="mev-wizard-logo-preview">
								<?php if ( $org_logo_url ) : ?>
									<img src="<?php echo esc_url( $org_logo_url ); ?>" alt="" />
								<?php else : ?>
									<span class="mev-wizard-logo-placeholder"><?php esc_html_e( 'No logo', 'meyvora-seo' ); ?></span>
								<?php endif; ?>
							</div>
							<button type="button" class="mev-btn mev-btn--secondary" id="mev-wizard-logo-picker"><?php esc_html_e( 'Select image', 'meyvora-seo' ); ?></button>
							<input type="hidden" id="mev-wizard-org-logo" name="schema_organization_logo" value="<?php echo (int) $org_logo; ?>" />
						</div>
					</div>
					<div class="mev-wizard-actions">
						<button type="button" class="mev-btn mev-btn--secondary mev-wizard-prev" data-prev="1"><?php esc_html_e( 'Back', 'meyvora-seo' ); ?></button>
						<button type="button" class="mev-btn mev-btn--primary mev-wizard-next" data-next="3"><?php esc_html_e( 'Continue', 'meyvora-seo' ); ?></button>
					</div>
				</div>

				<!-- Step 3: Social profile URLs -->
				<div class="mev-wizard-step" data-step="3" role="tabpanel" hidden>
					<h1 class="mev-wizard-title"><?php esc_html_e( 'Social profiles', 'meyvora-seo' ); ?></h1>
					<p class="mev-wizard-desc"><?php esc_html_e( 'URLs are saved to schema sameAs.', 'meyvora-seo' ); ?></p>
					<?php
					$socials = array(
						'schema_sameas_facebook'   => __( 'Facebook', 'meyvora-seo' ),
						'schema_sameas_twitter'    => __( 'X / Twitter', 'meyvora-seo' ),
						'schema_sameas_linkedin'   => __( 'LinkedIn', 'meyvora-seo' ),
						'schema_sameas_instagram'  => __( 'Instagram', 'meyvora-seo' ),
						'schema_sameas_youtube'    => __( 'YouTube', 'meyvora-seo' ),
					);
					foreach ( $socials as $key => $label ) :
						$val = (string) ( $current[ $key ] ?? '' );
						?>
						<div class="mev-wizard-field">
							<label for="mev-wizard-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
							<input type="url" id="mev-wizard-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>" class="mev-wizard-input" placeholder="https://" />
						</div>
					<?php endforeach; ?>
					<div class="mev-wizard-actions">
						<button type="button" class="mev-btn mev-btn--secondary mev-wizard-prev" data-prev="2"><?php esc_html_e( 'Back', 'meyvora-seo' ); ?></button>
						<button type="button" class="mev-btn mev-btn--primary mev-wizard-next" data-next="4"><?php esc_html_e( 'Continue', 'meyvora-seo' ); ?></button>
					</div>
				</div>

				<!-- Step 4: Feature toggles -->
				<div class="mev-wizard-step" data-step="4" role="tabpanel" hidden>
					<h1 class="mev-wizard-title"><?php esc_html_e( 'Key features', 'meyvora-seo' ); ?></h1>
					<p class="mev-wizard-desc"><?php esc_html_e( 'Enable or disable these modules.', 'meyvora-seo' ); ?></p>
					<div class="mev-wizard-toggles">
						<?php
						$toggles = array(
							'open_graph'        => __( 'Open Graph', 'meyvora-seo' ),
							'twitter_cards'     => __( 'Twitter Cards', 'meyvora-seo' ),
							'sitemap_enabled'   => __( 'Sitemap', 'meyvora-seo' ),
							'redirects_enabled' => __( 'Redirects', 'meyvora-seo' ),
							'ai_enabled'        => __( 'AI', 'meyvora-seo' ),
						);
						foreach ( $toggles as $key => $label ) :
							$checked = (bool) ( $current[ $key ] ?? true );
							?>
							<label class="mev-wizard-toggle">
								<span class="mev-wizard-toggle-label"><?php echo esc_html( $label ); ?></span>
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $checked ); ?> class="mev-wizard-switch" />
								<span class="mev-wizard-switch-ui" aria-hidden="true"></span>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="mev-wizard-actions">
						<button type="button" class="mev-btn mev-btn--secondary mev-wizard-prev" data-prev="3"><?php esc_html_e( 'Back', 'meyvora-seo' ); ?></button>
						<button type="button" class="mev-btn mev-btn--primary mev-wizard-next" data-next="5"><?php esc_html_e( 'Continue', 'meyvora-seo' ); ?></button>
					</div>
				</div>

				<!-- Step 5: Sitemap ping -->
				<div class="mev-wizard-step" data-step="5" role="tabpanel" hidden>
					<h1 class="mev-wizard-title"><?php esc_html_e( 'Notify search engines', 'meyvora-seo' ); ?></h1>
					<p class="mev-wizard-desc"><?php esc_html_e( 'Ping Google with your sitemap URL so they can discover it sooner.', 'meyvora-seo' ); ?></p>
					<div class="mev-wizard-ping-box">
						<button type="button" class="mev-btn mev-btn--primary" id="mev-wizard-ping-btn"><?php esc_html_e( 'Ping Google', 'meyvora-seo' ); ?></button>
						<span class="mev-wizard-ping-status" id="mev-wizard-ping-status" aria-live="polite"></span>
					</div>
					<div class="mev-wizard-actions">
						<button type="button" class="mev-btn mev-btn--secondary mev-wizard-prev" data-prev="4"><?php esc_html_e( 'Back', 'meyvora-seo' ); ?></button>
						<button type="button" class="mev-btn mev-btn--primary mev-wizard-next" data-next="6"><?php esc_html_e( 'Continue', 'meyvora-seo' ); ?></button>
					</div>
				</div>

				<!-- Step 6: Completion -->
				<div class="mev-wizard-step" data-step="6" role="tabpanel" hidden>
					<h1 class="mev-wizard-title"><?php esc_html_e( 'You\'re all set', 'meyvora-seo' ); ?></h1>
					<p class="mev-wizard-desc"><?php esc_html_e( 'Your SEO basics are configured. You can change any of this later in Settings.', 'meyvora-seo' ); ?></p>
					<div class="mev-wizard-actions mev-wizard-actions--center">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>" class="mev-btn mev-btn--primary" id="mev-wizard-go-dashboard"><?php esc_html_e( 'Go to Dashboard', 'meyvora-seo' ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: save step data (merge into meyvora_seo_settings).
	 */
	public function ajax_save_step(): void {
		check_ajax_referer( 'meyvora_seo_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_and_merge.
		$input = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$sanitized = $this->options->sanitize_and_merge( $input );
		$saved = update_option( MEYVORA_SEO_OPTION_KEY, $sanitized, true );
		wp_send_json_success( array( 'saved' => $saved ) );
	}

	/**
	 * AJAX: skip wizard (set wizard_done=1).
	 */
	public function ajax_skip_wizard(): void {
		check_ajax_referer( 'meyvora_seo_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ) );
		}
		update_option( self::WIZARD_DONE_OPTION, 1, true );
		wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=meyvora-seo' ) ) );
	}

	/**
	 * AJAX: complete wizard (set wizard_done=1, return redirect URL).
	 */
	public function ajax_complete(): void {
		check_ajax_referer( 'meyvora_seo_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ) );
		}
		update_option( self::WIZARD_DONE_OPTION, 1, true );
		wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=meyvora-seo' ) ) );
	}

	/**
	 * AJAX: ping Google sitemap (trigger existing ping).
	 */
	public function ajax_ping_sitemap(): void {
		check_ajax_referer( 'meyvora_seo_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ) );
		}
		do_action( 'meyvora_seo_after_publish' );
		wp_send_json_success( array( 'message' => __( 'Ping sent.', 'meyvora-seo' ) ) );
	}
}
