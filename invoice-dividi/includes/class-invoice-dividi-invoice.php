<?php
/**
 * Invoice model for Invoice Dividi.
 *
 * Handles invoice creation, retrieval, and invoice number generation.
 * Invoice metadata is stored as WooCommerce order meta.
 *
 * @package Invoice_Dividi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invoice_Dividi_Invoice
 */
class Invoice_Dividi_Invoice {

	// Order meta keys.
	const META_NUMBER    = '_invoice_dividi_number';
	const META_DATE      = '_invoice_dividi_date';
	const META_FILE_PATH = '_invoice_dividi_file_path';
	const META_FILE_NAME = '_invoice_dividi_file_name';

	// ----------------------------------------------------------------
	// Factory / static helpers
	// ----------------------------------------------------------------

	/**
	 * Check whether an invoice has already been generated for a given order.
	 *
	 * @param int|\WC_Order $order_id Order ID or WC_Order object.
	 * @return bool
	 */
	public static function exists( $order_id ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		$number = $order->get_meta( self::META_NUMBER, true );
		return ! empty( $number );
	}

	/**
	 * Retrieve invoice data for an order.
	 *
	 * Returns an associative array with keys:
	 *   number, date, file_path, file_name
	 * or null if no invoice exists.
	 *
	 * @param int|\WC_Order $order_id Order ID or WC_Order object.
	 * @return array|null
	 */
	public static function get( $order_id ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$number = $order->get_meta( self::META_NUMBER, true );
		if ( empty( $number ) ) {
			return null;
		}

		return array(
			'number'    => $number,
			'date'      => $order->get_meta( self::META_DATE, true ),
			'file_path' => $order->get_meta( self::META_FILE_PATH, true ),
			'file_name' => $order->get_meta( self::META_FILE_NAME, true ),
		);
	}

	/**
	 * Create (or regenerate) an invoice for the given order.
	 *
	 * Generates the PDF and saves metadata on the order.
	 *
	 * @param int|\WC_Order $order_id Order ID or WC_Order object.
	 * @return array|\WP_Error Invoice data array on success, WP_Error on failure.
	 */
	public static function create( $order_id ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid WooCommerce order.', 'invoice-dividi' ) );
		}

		// Generate the invoice number.
		$invoice_number = self::generate_invoice_number();

		// Build invoice data array.
		$invoice_data = self::build_invoice_data( $order, $invoice_number );

		// Generate PDF file.
		$pdf_generator = new Invoice_Dividi_PDF();
		$pdf_result    = $pdf_generator->generate( $invoice_data );

		if ( is_wp_error( $pdf_result ) ) {
			return $pdf_result;
		}

		// Save file to disk.
		$file_info = self::save_pdf_file( $pdf_result, $invoice_number, $order->get_id() );
		if ( is_wp_error( $file_info ) ) {
			return $file_info;
		}

		$invoice_date = current_time( 'Y-m-d' );

		// Persist to order meta.
		$order->update_meta_data( self::META_NUMBER, $invoice_number );
		$order->update_meta_data( self::META_DATE, $invoice_date );
		$order->update_meta_data( self::META_FILE_PATH, $file_info['path'] );
		$order->update_meta_data( self::META_FILE_NAME, $file_info['name'] );
		$order->save();

		// Increment the global counter.
		self::increment_counter();

		return array(
			'number'    => $invoice_number,
			'date'      => $invoice_date,
			'file_path' => $file_info['path'],
			'file_name' => $file_info['name'],
		);
	}

	// ----------------------------------------------------------------
	// Invoice number generation
	// ----------------------------------------------------------------

	/**
	 * Generate the next invoice number string.
	 *
	 * @return string
	 */
	public static function generate_invoice_number() {
		$format  = get_option( 'invoice_dividi_number_format', 'INV-{YEAR}-{NUMBER}' );
		$counter = (int) get_option( 'invoice_dividi_number_counter', 1 );
		$padding = (int) get_option( 'invoice_dividi_number_padding', 4 );

		$number_str = str_pad( (string) $counter, $padding, '0', STR_PAD_LEFT );

		// Use site-local time so {YEAR}/{MONTH} match the invoice date shown on the PDF.
		$invoice_number = str_replace(
			array( '{YEAR}', '{MONTH}', '{NUMBER}' ),
			array(
				current_time( 'Y' ),
				current_time( 'm' ),
				$number_str,
			),
			$format
		);

		return sanitize_text_field( $invoice_number );
	}

	/**
	 * Increment the invoice counter stored in the database.
	 */
	private static function increment_counter() {
		$counter = (int) get_option( 'invoice_dividi_number_counter', 1 );
		update_option( 'invoice_dividi_number_counter', $counter + 1 );
	}

	// ----------------------------------------------------------------
	// Build invoice data
	// ----------------------------------------------------------------

	/**
	 * Assemble all data needed to render the PDF invoice.
	 *
	 * @param \WC_Order $order          WooCommerce order object.
	 * @param string    $invoice_number Formatted invoice number.
	 * @return array
	 */
	private static function build_invoice_data( WC_Order $order, $invoice_number ) {
		$vat_enabled    = '1' === get_option( 'invoice_dividi_vat_enabled', '0' );
		$vat_percentage = (float) get_option( 'invoice_dividi_vat_percentage', 21 );

		// Order items.
		$items = array();
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product   = $item->get_product();
			$qty       = (int) $item->get_quantity();
			$line_total = (float) $item->get_total(); // Subtotal excl. tax.

			$unit_price = ( $qty > 0 ) ? round( $line_total / $qty, 4 ) : 0.0;

			$items[] = array(
				'name'        => $item->get_name(),
				'sku'         => $product ? $product->get_sku() : '',
				'qty'         => $qty,
				'unit_price'  => $unit_price,
				'line_total'  => $line_total,
			);
		}

		// Subtotal (sum of line totals, excl. tax).
		$subtotal = array_sum( array_column( $items, 'line_total' ) );

		// VAT.
		$vat_amount = 0.0;
		if ( $vat_enabled ) {
			$vat_amount = round( $subtotal * $vat_percentage / 100, 2 );
		}

		$total = round( $subtotal + $vat_amount, 2 );

		return array(
			// Invoice meta.
			'invoice_number' => $invoice_number,
			'invoice_date'   => current_time( 'Y-m-d' ),

			// Company info.
			'company_name'    => get_option( 'invoice_dividi_company_name', '' ),
			'company_address' => get_option( 'invoice_dividi_company_address', '' ),
			'company_details' => get_option( 'invoice_dividi_company_details', '' ),
			'vat_code'        => get_option( 'invoice_dividi_vat_code', '' ),
			'logo_url'        => get_option( 'invoice_dividi_logo_url', '' ),

			// VAT.
			'vat_enabled'    => $vat_enabled,
			'vat_percentage' => $vat_percentage,
			'vat_amount'     => $vat_amount,

			// Order info.
			'order_id'       => $order->get_id(),
			'order_date'     => wc_format_datetime( $order->get_date_created() ),
			'order_currency' => get_woocommerce_currency_symbol( $order->get_currency() ),

			// Billing address.
			'billing_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_company' => $order->get_billing_company(),
			'billing_address' => self::format_billing_address( $order ),
			'billing_email'   => $order->get_billing_email(),

			// Line items & totals.
			'items'    => $items,
			'subtotal' => $subtotal,
			'total'    => $total,

			// Footer.
			'footer_text' => wp_kses_post( get_option( 'invoice_dividi_footer_text', '' ) ),
		);
	}

	/**
	 * Format the billing address into a multi-line string.
	 *
	 * @param \WC_Order $order Order object.
	 * @return string
	 */
	private static function format_billing_address( WC_Order $order ) {
		$parts = array_filter(
			array(
				$order->get_billing_address_1(),
				$order->get_billing_address_2(),
				$order->get_billing_city(),
				$order->get_billing_state(),
				$order->get_billing_postcode(),
				$order->get_billing_country(),
			)
		);
		return implode( "\n", $parts );
	}

	// ----------------------------------------------------------------
	// File storage
	// ----------------------------------------------------------------

	/**
	 * Save the raw PDF binary to disk in the uploads directory.
	 *
	 * @param string $pdf_content  Raw PDF binary.
	 * @param string $invoice_number Invoice number (used in filename).
	 * @param int    $order_id      WooCommerce order ID.
	 * @return array|\WP_Error  Array with 'path' and 'name' on success, WP_Error on failure.
	 */
	private static function save_pdf_file( $pdf_content, $invoice_number, $order_id ) {
		$upload_dir = wp_upload_dir();
		$pdf_dir    = trailingslashit( $upload_dir['basedir'] ) . 'invoice-dividi';

		if ( ! file_exists( $pdf_dir ) ) {
			wp_mkdir_p( $pdf_dir );
		}

		// Sanitise the invoice number to be safe for file names.
		$safe_number = preg_replace( '/[^A-Za-z0-9\-_]/', '-', $invoice_number );
		$file_name   = 'invoice-' . $safe_number . '-order-' . absint( $order_id ) . '.pdf';
		$file_path   = trailingslashit( $pdf_dir ) . $file_name;

		$bytes_written = file_put_contents( $file_path, $pdf_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $bytes_written ) {
			return new WP_Error(
				'pdf_write_failed',
				__( 'Could not write PDF file to disk. Please check directory permissions.', 'invoice-dividi' )
			);
		}

		return array(
			'path' => $file_path,
			'name' => $file_name,
		);
	}

	/**
	 * Build the URL to download a stored invoice PDF.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string|false URL string or false if no invoice exists.
	 */
	public static function get_download_url( $order_id ) {
		$invoice = self::get( $order_id );
		if ( ! $invoice || empty( $invoice['file_name'] ) ) {
			return false;
		}

		// Serve via admin-ajax.php to keep the file behind authorisation.
		return add_query_arg(
			array(
				'action'   => 'invoice_dividi_download',
				'order_id' => absint( $order_id ),
				'nonce'    => wp_create_nonce( 'invoice_dividi_download_' . absint( $order_id ) ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	// ----------------------------------------------------------------
	// Private helpers
	// ----------------------------------------------------------------

	/**
	 * Resolve an order ID or object to a WC_Order.
	 *
	 * @param int|\WC_Order $order_id Order ID or WC_Order.
	 * @return \WC_Order|false
	 */
	private static function get_order( $order_id ) {
		if ( $order_id instanceof WC_Order ) {
			return $order_id;
		}
		return wc_get_order( absint( $order_id ) );
	}
}
