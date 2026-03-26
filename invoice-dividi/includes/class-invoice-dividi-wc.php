<?php
/**
 * WooCommerce integration for Invoice Dividi.
 *
 * Adds the "Generate Invoice" / "View Invoice" metabox to the
 * WooCommerce order edit screen and handles the corresponding
 * admin POST actions and AJAX download.
 *
 * @package Invoice_Dividi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invoice_Dividi_WC
 */
class Invoice_Dividi_WC {

	/**
	 * Constructor – register all WooCommerce-related hooks.
	 */
	public function __construct() {
		// Classic order edit screen metabox.
		add_action( 'add_meta_boxes', array( $this, 'add_invoice_metabox' ) );

		// Handle the "Generate Invoice" form submission.
		add_action( 'admin_post_invoice_dividi_generate', array( $this, 'handle_generate' ) );

		// Serve PDF download via admin-ajax.
		add_action( 'wp_ajax_invoice_dividi_download', array( $this, 'handle_download' ) );

		// Show an admin notice with the generation result (after redirect).
		add_action( 'admin_notices', array( $this, 'show_admin_notice' ) );

		// WooCommerce order list column: show invoice indicator.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_invoice_column' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_invoice_column' ) ); // Legacy.
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_invoice_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_invoice_column_legacy' ), 10, 2 );
	}

	// ----------------------------------------------------------------
	// Metabox registration
	// ----------------------------------------------------------------

	/**
	 * Register the Invoice Dividi metabox on the order edit screens.
	 */
	public function add_invoice_metabox() {
		// Support both the classic post-based order screen and the HPOS screen.
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'invoice-dividi-metabox',
				__( 'Invoice Dividi', 'invoice-dividi' ),
				array( $this, 'render_metabox' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the metabox HTML.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order WP_Post (classic) or WC_Order (HPOS).
	 */
	public function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post )
			? wc_get_order( $post_or_order->ID )
			: $post_or_order;

		if ( ! $order ) {
			return;
		}

		$order_id   = $order->get_id();
		$has_invoice = Invoice_Dividi_Invoice::exists( $order_id );
		$invoice    = $has_invoice ? Invoice_Dividi_Invoice::get( $order_id ) : null;

		// Nonce for the generate action.
		$nonce = wp_create_nonce( 'invoice_dividi_generate_' . $order_id );
		?>
		<div class="invoice-dividi-metabox">
			<?php if ( $has_invoice && $invoice ) : ?>
				<p class="invoice-dividi-info">
					<strong><?php esc_html_e( 'Invoice No.:', 'invoice-dividi' ); ?></strong>
					<?php echo esc_html( $invoice['number'] ); ?>
				</p>
				<p class="invoice-dividi-info">
					<strong><?php esc_html_e( 'Date:', 'invoice-dividi' ); ?></strong>
					<?php echo esc_html( $invoice['date'] ); ?>
				</p>

				<!-- View / Download invoice button -->
				<?php
				$download_url = Invoice_Dividi_Invoice::get_download_url( $order_id );
				if ( $download_url ) :
					?>
					<a
						href="<?php echo esc_url( $download_url ); ?>"
						class="button button-primary invoice-dividi-view-btn"
						target="_blank"
					>
						<?php esc_html_e( 'View Invoice', 'invoice-dividi' ); ?>
					</a>
					<br /><br />
				<?php endif; ?>

				<!-- Regenerate form -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action"   value="invoice_dividi_generate" />
					<input type="hidden" name="order_id" value="<?php echo absint( $order_id ); ?>" />
					<?php wp_nonce_field( 'invoice_dividi_generate_' . $order_id, 'invoice_dividi_nonce' ); ?>
					<button type="submit" class="button invoice-dividi-regen-btn">
						<?php esc_html_e( 'Regenerate Invoice', 'invoice-dividi' ); ?>
					</button>
				</form>

			<?php else : ?>
				<!-- Generate invoice form -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action"   value="invoice_dividi_generate" />
					<input type="hidden" name="order_id" value="<?php echo absint( $order_id ); ?>" />
					<?php wp_nonce_field( 'invoice_dividi_generate_' . $order_id, 'invoice_dividi_nonce' ); ?>
					<button type="submit" class="button button-primary invoice-dividi-generate-btn">
						<?php esc_html_e( 'Generate Invoice', 'invoice-dividi' ); ?>
					</button>
				</form>
				<p class="description">
					<?php esc_html_e( 'No invoice has been generated for this order yet.', 'invoice-dividi' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ----------------------------------------------------------------
	// Handle generate action
	// ----------------------------------------------------------------

	/**
	 * Handle the admin POST for invoice generation.
	 *
	 * Verifies the nonce, generates the invoice, adds an order note,
	 * then redirects back to the order page with a result notice.
	 */
	public function handle_generate() {
		// Verify capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'invoice-dividi' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid order ID.', 'invoice-dividi' ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['invoice_dividi_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['invoice_dividi_nonce'] ) ),
				'invoice_dividi_generate_' . $order_id
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'invoice-dividi' ) );
		}

		$result = Invoice_Dividi_Invoice::create( $order_id );

		$order = wc_get_order( $order_id );

		if ( is_wp_error( $result ) ) {
			// Store error message in transient for admin notice.
			set_transient(
				'invoice_dividi_notice_' . get_current_user_id(),
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				),
				60
			);
		} else {
			// Add an order note visible in the order history.
			if ( $order ) {
				/* translators: %s: invoice number */
				$order->add_order_note(
					sprintf(
						__( 'Invoice Dividi: Invoice %s generated successfully.', 'invoice-dividi' ),
						esc_html( $result['number'] )
					)
				);
			}

			set_transient(
				'invoice_dividi_notice_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => sprintf(
						/* translators: %s: invoice number */
						__( 'Invoice %s generated successfully.', 'invoice-dividi' ),
						esc_html( $result['number'] )
					),
				),
				60
			);
		}

		// Redirect back to the order.
		$redirect_url = $this->get_order_edit_url( $order_id );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	// ----------------------------------------------------------------
	// PDF download handler
	// ----------------------------------------------------------------

	/**
	 * Serve the PDF file for download via AJAX.
	 *
	 * Only accessible to users with manage_woocommerce capability.
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'invoice-dividi' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid order.', 'invoice-dividi' ) );
		}

		if ( ! isset( $_GET['nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['nonce'] ) ),
				'invoice_dividi_download_' . $order_id
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'invoice-dividi' ) );
		}

		$invoice = Invoice_Dividi_Invoice::get( $order_id );
		if ( ! $invoice || empty( $invoice['file_path'] ) ) {
			wp_die( esc_html__( 'Invoice not found.', 'invoice-dividi' ) );
		}

		$file_path = $invoice['file_path'];

		if ( ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'Invoice file not found on disk.', 'invoice-dividi' ) );
		}

		// Validate the file is within the expected uploads directory.
		$upload_dir  = wp_upload_dir();
		$allowed_dir = realpath( trailingslashit( $upload_dir['basedir'] ) . 'invoice-dividi' );
		$real_path   = realpath( $file_path );

		if ( false === $real_path || false === $allowed_dir || 0 !== strpos( $real_path, $allowed_dir ) ) {
			wp_die( esc_html__( 'Invalid file path.', 'invoice-dividi' ) );
		}

		$file_name = basename( $file_path );

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $file_name ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		header( 'Pragma: public' );

		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// ----------------------------------------------------------------
	// Admin notices
	// ----------------------------------------------------------------

	/**
	 * Display a transient-based admin notice after invoice generation.
	 */
	public function show_admin_notice() {
		$user_id = get_current_user_id();
		$notice  = get_transient( 'invoice_dividi_notice_' . $user_id );

		if ( ! $notice ) {
			return;
		}
		delete_transient( 'invoice_dividi_notice_' . $user_id );

		$class = ( 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	// ----------------------------------------------------------------
	// Order list column
	// ----------------------------------------------------------------

	/**
	 * Add an "Invoice" column to the WooCommerce orders list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_invoice_column( array $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['invoice_dividi'] = __( 'Invoice', 'invoice-dividi' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render the invoice column for HPOS order list.
	 *
	 * @param string    $column   Column slug.
	 * @param \WC_Order $order    WC_Order object.
	 */
	public function render_invoice_column( $column, $order ) {
		if ( 'invoice_dividi' !== $column ) {
			return;
		}
		$this->render_invoice_column_content( $order->get_id() );
	}

	/**
	 * Render the invoice column for the legacy post-based order list.
	 *
	 * @param string $column   Column slug.
	 * @param int    $post_id  Post (order) ID.
	 */
	public function render_invoice_column_legacy( $column, $post_id ) {
		if ( 'invoice_dividi' !== $column ) {
			return;
		}
		$this->render_invoice_column_content( $post_id );
	}

	/**
	 * Shared invoice column output.
	 *
	 * @param int $order_id Order ID.
	 */
	private function render_invoice_column_content( $order_id ) {
		if ( Invoice_Dividi_Invoice::exists( $order_id ) ) {
			$url = Invoice_Dividi_Invoice::get_download_url( $order_id );
			if ( $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" title="' . esc_attr__( 'View Invoice', 'invoice-dividi' ) . '">';
				echo '<span class="dashicons dashicons-media-document" style="color:#0073aa;"></span>';
				echo '</a>';
			} else {
				echo '<span class="dashicons dashicons-media-document" style="color:#ccc;"></span>';
			}
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	/**
	 * Get the admin URL for a WooCommerce order edit page.
	 *
	 * Supports both HPOS and classic post-based orders.
	 *
	 * @param int $order_id Order ID.
	 * @return string Admin URL.
	 */
	private function get_order_edit_url( $order_id ) {
		// Try HPOS-aware URL first.
		if ( function_exists( 'wc_get_container' ) ) {
			try {
				$order = wc_get_order( $order_id );
				if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
					return $order->get_edit_order_url();
				}
			} catch ( \Exception $e ) {
				// The HPOS-aware URL lookup failed; fall through to the legacy URL.
				// This is safe because the legacy admin URL always works as a fallback.
				unset( $e );
			}
		}

		return admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
	}
}
