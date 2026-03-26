<?php
/**
 * Admin settings for Invoice Dividi.
 *
 * Registers the settings page, all options, and handles logo uploads.
 *
 * @package Invoice_Dividi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invoice_Dividi_Admin
 */
class Invoice_Dividi_Admin {

	/**
	 * Option group used by the Settings API.
	 */
	const OPTION_GROUP = 'invoice_dividi_settings';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'invoice-dividi-settings';

	/**
	 * Constructor – wire up WordPress hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ----------------------------------------------------------------
	// Admin menu
	// ----------------------------------------------------------------

	/**
	 * Register the top-level admin menu entry.
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'Invoice Dividi', 'invoice-dividi' ),
			__( 'Invoice Dividi', 'invoice-dividi' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-media-document',
			58
		);
	}

	// ----------------------------------------------------------------
	// Assets
	// ----------------------------------------------------------------

	/**
	 * Enqueue admin styles and scripts on our settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_media(); // Needed for the logo media uploader.

		wp_enqueue_style(
			'invoice-dividi-admin',
			INVOICE_DIVIDI_URL . 'assets/css/admin.css',
			array(),
			INVOICE_DIVIDI_VERSION
		);

		wp_enqueue_script(
			'invoice-dividi-admin',
			INVOICE_DIVIDI_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			INVOICE_DIVIDI_VERSION,
			true
		);
	}

	// ----------------------------------------------------------------
	// Settings registration
	// ----------------------------------------------------------------

	/**
	 * Register all settings fields and sections via the Settings API.
	 */
	public function register_settings() {
		// ---------------------------------------------------------
		// Company information section
		// ---------------------------------------------------------
		add_settings_section(
			'invoice_dividi_company',
			__( 'Company Information', 'invoice-dividi' ),
			null,
			self::PAGE_SLUG
		);

		$this->add_text_field( 'company_name', __( 'Company Name', 'invoice-dividi' ), 'invoice_dividi_company' );
		$this->add_textarea_field( 'company_address', __( 'Company Address', 'invoice-dividi' ), 'invoice_dividi_company' );
		$this->add_textarea_field( 'company_details', __( 'Company Details (bank, reg. no., etc.)', 'invoice-dividi' ), 'invoice_dividi_company' );
		$this->add_text_field( 'vat_code', __( 'VAT / PVM Code', 'invoice-dividi' ), 'invoice_dividi_company' );
		$this->add_logo_field( 'logo_url', __( 'Company Logo', 'invoice-dividi' ), 'invoice_dividi_company' );

		// ---------------------------------------------------------
		// VAT section
		// ---------------------------------------------------------
		add_settings_section(
			'invoice_dividi_vat',
			__( 'VAT / PVM Settings', 'invoice-dividi' ),
			null,
			self::PAGE_SLUG
		);

		$this->add_checkbox_field( 'vat_enabled', __( 'Enable VAT / PVM', 'invoice-dividi' ), 'invoice_dividi_vat' );
		$this->add_text_field( 'vat_percentage', __( 'VAT Percentage (%)', 'invoice-dividi' ), 'invoice_dividi_vat' );

		// ---------------------------------------------------------
		// Invoice numbering section
		// ---------------------------------------------------------
		add_settings_section(
			'invoice_dividi_numbering',
			__( 'Invoice Numbering', 'invoice-dividi' ),
			function () {
				echo '<p class="description">' .
					esc_html__( 'Available placeholders: {YEAR}, {MONTH}, {NUMBER}', 'invoice-dividi' ) .
					'</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_text_field( 'number_format', __( 'Number Format', 'invoice-dividi' ), 'invoice_dividi_numbering' );
		$this->add_text_field( 'number_counter', __( 'Next Invoice Number', 'invoice-dividi' ), 'invoice_dividi_numbering' );
		$this->add_text_field( 'number_padding', __( 'Number Zero-Padding (digits)', 'invoice-dividi' ), 'invoice_dividi_numbering' );

		// ---------------------------------------------------------
		// Invoice PDF section
		// ---------------------------------------------------------
		add_settings_section(
			'invoice_dividi_pdf',
			__( 'Invoice PDF', 'invoice-dividi' ),
			null,
			self::PAGE_SLUG
		);

		$this->add_wysiwyg_field( 'footer_text', __( 'Invoice Footer Text', 'invoice-dividi' ), 'invoice_dividi_pdf' );

		// Register actual options so they are saved.
		$text_fields = array(
			'company_name',
			'company_address',
			'company_details',
			'vat_code',
			'logo_url',
			'vat_percentage',
			'number_format',
			'number_counter',
			'number_padding',
			'footer_text',
		);

		foreach ( $text_fields as $field ) {
			register_setting(
				self::OPTION_GROUP,
				'invoice_dividi_' . $field,
				array(
					'sanitize_callback' => array( $this, 'sanitize_text_option' ),
				)
			);
		}

		register_setting(
			self::OPTION_GROUP,
			'invoice_dividi_vat_enabled',
			array(
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'invoice_dividi_footer_text',
			array(
				'sanitize_callback' => 'wp_kses_post',
			)
		);
	}

	// ----------------------------------------------------------------
	// Helper: register individual fields
	// ----------------------------------------------------------------

	/**
	 * Register a single-line text field.
	 *
	 * @param string $key     Option key suffix (without prefix).
	 * @param string $label   Field label.
	 * @param string $section Settings section id.
	 */
	private function add_text_field( $key, $label, $section ) {
		add_settings_field(
			'invoice_dividi_' . $key,
			$label,
			function () use ( $key ) {
				$value = get_option( 'invoice_dividi_' . $key, '' );
				printf(
					'<input type="text" id="invoice_dividi_%s" name="invoice_dividi_%s" value="%s" class="regular-text" />',
					esc_attr( $key ),
					esc_attr( $key ),
					esc_attr( $value )
				);
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a textarea field.
	 *
	 * @param string $key     Option key suffix.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_textarea_field( $key, $label, $section ) {
		add_settings_field(
			'invoice_dividi_' . $key,
			$label,
			function () use ( $key ) {
				$value = get_option( 'invoice_dividi_' . $key, '' );
				printf(
					'<textarea id="invoice_dividi_%s" name="invoice_dividi_%s" rows="3" class="large-text">%s</textarea>',
					esc_attr( $key ),
					esc_attr( $key ),
					esc_textarea( $value )
				);
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a checkbox field.
	 *
	 * @param string $key     Option key suffix.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_checkbox_field( $key, $label, $section ) {
		add_settings_field(
			'invoice_dividi_' . $key,
			$label,
			function () use ( $key ) {
				$value = get_option( 'invoice_dividi_' . $key, '0' );
				printf(
					'<input type="checkbox" id="invoice_dividi_%s" name="invoice_dividi_%s" value="1" %s />',
					esc_attr( $key ),
					esc_attr( $key ),
					checked( '1', $value, false )
				);
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a media-upload logo field.
	 *
	 * @param string $key     Option key suffix.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_logo_field( $key, $label, $section ) {
		add_settings_field(
			'invoice_dividi_' . $key,
			$label,
			function () use ( $key ) {
				$logo_url = get_option( 'invoice_dividi_' . $key, '' );
				?>
				<div class="invoice-dividi-logo-field">
					<?php if ( ! empty( $logo_url ) ) : ?>
						<img
							id="invoice_dividi_logo_preview"
							src="<?php echo esc_url( $logo_url ); ?>"
							style="max-height:80px;display:block;margin-bottom:8px;"
							alt="<?php esc_attr_e( 'Logo preview', 'invoice-dividi' ); ?>"
						/>
					<?php else : ?>
						<img
							id="invoice_dividi_logo_preview"
							src=""
							style="max-height:80px;display:none;margin-bottom:8px;"
							alt="<?php esc_attr_e( 'Logo preview', 'invoice-dividi' ); ?>"
						/>
					<?php endif; ?>

					<input
						type="hidden"
						id="invoice_dividi_<?php echo esc_attr( $key ); ?>"
						name="invoice_dividi_<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_url( $logo_url ); ?>"
					/>

					<button
						type="button"
						class="button invoice-dividi-upload-logo"
						data-target="#invoice_dividi_<?php echo esc_attr( $key ); ?>"
						data-preview="#invoice_dividi_logo_preview"
					>
						<?php esc_html_e( 'Upload / Select Logo', 'invoice-dividi' ); ?>
					</button>

					<?php if ( ! empty( $logo_url ) ) : ?>
					<button
						type="button"
						class="button invoice-dividi-remove-logo"
						data-target="#invoice_dividi_<?php echo esc_attr( $key ); ?>"
						data-preview="#invoice_dividi_logo_preview"
					>
						<?php esc_html_e( 'Remove Logo', 'invoice-dividi' ); ?>
					</button>
					<?php endif; ?>
				</div>
				<?php
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a WordPress WYSIWYG editor field.
	 *
	 * @param string $key     Option key suffix.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_wysiwyg_field( $key, $label, $section ) {
		add_settings_field(
			'invoice_dividi_' . $key,
			$label,
			function () use ( $key ) {
				$value   = get_option( 'invoice_dividi_' . $key, '' );
				$editor_id = 'invoice_dividi_' . $key;
				wp_editor(
					$value,
					$editor_id,
					array(
						'textarea_name' => $editor_id,
						'textarea_rows' => 6,
						'media_buttons' => false,
						'teeny'         => true,
					)
				);
			},
			self::PAGE_SLUG,
			$section
		);
	}

	// ----------------------------------------------------------------
	// Sanitization callbacks
	// ----------------------------------------------------------------

	/**
	 * Sanitize a plain-text option value.
	 *
	 * @param mixed $value Raw input value.
	 * @return string Sanitised value.
	 */
	public function sanitize_text_option( $value ) {
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Sanitize a checkbox value (returns '1' or '0').
	 *
	 * @param mixed $value Raw input value.
	 * @return string '1' or '0'.
	 */
	public function sanitize_checkbox( $value ) {
		return ( '1' === $value || true === $value || 1 === $value ) ? '1' : '0';
	}

	// ----------------------------------------------------------------
	// Settings page HTML
	// ----------------------------------------------------------------

	/**
	 * Render the plugin settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'invoice-dividi' ) );
		}
		?>
		<div class="wrap invoice-dividi-settings-wrap">
			<h1>
				<span class="dashicons dashicons-media-document"></span>
				<?php esc_html_e( 'Invoice Dividi – Settings', 'invoice-dividi' ); ?>
			</h1>

			<form method="post" action="options.php" enctype="multipart/form-data">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'invoice-dividi' ) );
				?>
			</form>

			<hr />
			<p class="invoice-dividi-license-notice">
				<?php
				esc_html_e(
					'Invoice Dividi © Dividi (dividi.lt). This plugin may not be used, distributed, or modified without the express written permission of Dividi.',
					'invoice-dividi'
				);
				?>
			</p>
		</div>
		<?php
	}
}
