<?php
/**
 * Plugin Name:       Invoice Dividi
 * Plugin URI:        https://dividi.lt
 * Description:       Professional WooCommerce PDF invoice generation plugin. Generates invoices directly from WooCommerce orders with full VAT/PVM support and customisable company details.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Dividi
 * Author URI:        https://dividi.lt
 * License:           Proprietary
 * License URI:       https://dividi.lt/invoice-dividi-license
 * Text Domain:       invoice-dividi
 * Domain Path:       /languages
 *
 * WC requires at least: 5.0
 * WC tested up to:      9.0
 *
 * LICENSE NOTICE
 * --------------
 * This plugin is proprietary software developed by Dividi (https://dividi.lt).
 * It may NOT be used, copied, modified, merged, published, distributed,
 * sublicensed, or sold without the prior written permission of Dividi.
 * Unauthorised use of this plugin is strictly prohibited and may result in
 * legal action. For licensing enquiries, contact: info@dividi.lt
 *
 * © 2024 Dividi. All rights reserved.
 *
 * @package Invoice_Dividi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// -----------------------------------------------------------------
// Plugin constants
// -----------------------------------------------------------------
define( 'INVOICE_DIVIDI_VERSION', '1.0.0' );
define( 'INVOICE_DIVIDI_FILE', __FILE__ );
define( 'INVOICE_DIVIDI_DIR', plugin_dir_path( __FILE__ ) );
define( 'INVOICE_DIVIDI_URL', plugin_dir_url( __FILE__ ) );
define( 'INVOICE_DIVIDI_SLUG', 'invoice-dividi' );

// -----------------------------------------------------------------
// Activation / deactivation hooks
// -----------------------------------------------------------------
register_activation_hook( __FILE__, 'invoice_dividi_activate' );
register_deactivation_hook( __FILE__, 'invoice_dividi_deactivate' );

/**
 * Plugin activation callback.
 */
function invoice_dividi_activate() {
	// Create the upload directory for storing PDF files.
	$upload_dir = wp_upload_dir();
	$pdf_dir    = trailingslashit( $upload_dir['basedir'] ) . 'invoice-dividi';

	if ( ! file_exists( $pdf_dir ) ) {
		wp_mkdir_p( $pdf_dir );
	}

	// Write an .htaccess file to restrict direct HTTP access to PDFs.
	$htaccess = $pdf_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$htaccess,
			"Options -Indexes\nDeny from all\n"
		);
	}

	// Initialise default options if they do not already exist.
	$defaults = array(
		'company_name'    => get_bloginfo( 'name' ),
		'company_address' => '',
		'company_details' => '',
		'vat_code'        => '',
		'logo_url'        => '',
		'vat_enabled'     => '0',
		'vat_percentage'  => '21',
		'footer_text'     => '',
		'number_format'   => 'INV-{YEAR}-{NUMBER}',
		'number_counter'  => '1',
		'number_padding'  => '4',
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( 'invoice_dividi_' . $key ) ) {
			update_option( 'invoice_dividi_' . $key, $value );
		}
	}

	flush_rewrite_rules();
}

/**
 * Plugin deactivation callback.
 */
function invoice_dividi_deactivate() {
	flush_rewrite_rules();
}

// -----------------------------------------------------------------
// Load classes
// -----------------------------------------------------------------
require_once INVOICE_DIVIDI_DIR . 'includes/class-invoice-dividi-ttf.php';
require_once INVOICE_DIVIDI_DIR . 'includes/class-invoice-dividi-pdf.php';
require_once INVOICE_DIVIDI_DIR . 'includes/class-invoice-dividi-invoice.php';
require_once INVOICE_DIVIDI_DIR . 'includes/class-invoice-dividi-admin.php';
require_once INVOICE_DIVIDI_DIR . 'includes/class-invoice-dividi-wc.php';

/**
 * Boot the plugin once all plugins have loaded.
 */
function invoice_dividi_init() {
	// Load plugin translations.
	load_plugin_textdomain(
		'invoice-dividi',
		false,
		dirname( plugin_basename( INVOICE_DIVIDI_FILE ) ) . '/languages'
	);

	// Check that WooCommerce is active.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'invoice_dividi_woocommerce_missing_notice' );
		return;
	}

	// Instantiate plugin components.
	new Invoice_Dividi_Admin();
	new Invoice_Dividi_WC();
}
add_action( 'plugins_loaded', 'invoice_dividi_init' );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function invoice_dividi_woocommerce_missing_notice() {
	echo '<div class="error"><p>' .
		esc_html__( 'Invoice Dividi requires WooCommerce to be installed and active.', 'invoice-dividi' ) .
		'</p></div>';
}

/**
 * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);
