<?php
/**
 * Self-contained PDF generator for Invoice Dividi.
 *
 * Generates a complete, professional PDF invoice without any external
 * dependencies.  Uses PDF 1.4 with standard Type1 fonts (Helvetica /
 * Helvetica-Bold) so no font-embedding is required.  JPEG and PNG
 * logos are supported through inline XObjects.
 *
 * @package Invoice_Dividi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invoice_Dividi_PDF
 */
class Invoice_Dividi_PDF {

	// -----------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------

	/** Points per millimetre. */
	const PT_PER_MM = 2.8346;

	/** A4 page width in mm. */
	const PAGE_W_MM = 210;

	/** A4 page height in mm. */
	const PAGE_H_MM = 297;

	// -----------------------------------------------------------------
	// Internal state
	// -----------------------------------------------------------------

	/** @var string  Raw PDF binary output accumulator. */
	private $buf = '';

	/** @var int     Current object number. */
	private $n = 0;

	/** @var int[]   Byte offset of each object in $buf (1-indexed). */
	private $offsets = array();

	/** @var string  Content stream being built for the current page. */
	private $stream = '';

	/** @var array   List of page content-stream strings. */
	private $page_streams = array();

	/** @var int[]   List of page object IDs. */
	private $page_obj_ids = array();

	/** @var array   Fonts registered: name => object-id. */
	private $font_obj_ids = array();

	/** @var array   Images registered: key => [ 'obj_id', 'w', 'h' ]. */
	private $image_obj_ids = array();

	/** @var int     Page-width points (after unit conversion). */
	private $pw_pt;

	/** @var int     Page-height points. */
	private $ph_pt;

	// Current drawing state (all coordinates in mm, origin top-left).
	/** @var float */ private $x = 15.0;
	/** @var float */ private $y = 15.0;
	/** @var string */ private $font_name  = 'Helvetica';
	/** @var int    */ private $font_size  = 10;
	/** @var int[]  */ private $fill_color  = array( 255, 255, 255 );
	/** @var int[]  */ private $text_color  = array( 0, 0, 0 );
	/** @var int[]  */ private $draw_color  = array( 0, 0, 0 );
	/** @var float  */ private $line_width  = 0.3;

	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Generate the complete PDF for an invoice.
	 *
	 * @param array $data Invoice data as returned by Invoice_Dividi_Invoice::build_invoice_data().
	 * @return string|\WP_Error  Raw PDF binary or WP_Error on failure.
	 */
	public function generate( array $data ) {
		$this->pw_pt = (int) round( self::PAGE_W_MM * self::PT_PER_MM );
		$this->ph_pt = (int) round( self::PAGE_H_MM * self::PT_PER_MM );

		$this->buf    = '';
		$this->n      = 0;
		$this->stream = '';

		$this->buf .= "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";

		// Register the two fonts we use.
		$this->register_font( 'Helvetica' );
		$this->register_font( 'Helvetica-Bold' );

		// Register logo image if provided.
		$logo_key = '';
		if ( ! empty( $data['logo_url'] ) ) {
			$logo_key = $this->register_image( $data['logo_url'] );
		}

		// Begin page.
		$this->begin_page();

		// Draw the invoice content.
		$this->draw_invoice( $data, $logo_key );

		// End page.
		$this->end_page();

		// Compile final PDF.
		return $this->compile_pdf();
	}

	// ----------------------------------------------------------------
	// Invoice layout
	// ----------------------------------------------------------------

	/**
	 * Draw the complete invoice layout onto the current page.
	 *
	 * @param array  $data     Invoice data.
	 * @param string $logo_key Image key (or empty string if no logo).
	 */
	private function draw_invoice( array $data, $logo_key ) {
		$page_w = self::PAGE_W_MM;
		$ml     = 15; // left margin
		$mr     = 15; // right margin
		$col_w  = $page_w - $ml - $mr; // usable width

		// ---------------------------------------------------------
		// Header: logo (left) + company details (right)
		// ---------------------------------------------------------
		$header_y = 15;
		$logo_h   = 0;

		if ( ! empty( $logo_key ) ) {
			$img_info = $this->image_obj_ids[ $logo_key ];
			$logo_w   = 50; // max 50 mm wide
			$logo_h   = $logo_w * $img_info['h'] / max( $img_info['w'], 1 );
			if ( $logo_h > 25 ) {
				$logo_h = 25;
				$logo_w = $logo_h * $img_info['w'] / max( $img_info['h'], 1 );
			}
			$this->draw_image( $logo_key, $ml, $header_y, $logo_w, $logo_h );
		}

		// Company block (right-aligned).
		$right_col_x = $page_w / 2;
		$right_col_w = $page_w - $right_col_x - $mr;
		$cy          = $header_y;

		if ( ! empty( $data['company_name'] ) ) {
			$this->set_font( 'Helvetica-Bold', 12 );
			$this->set_text_color( 30, 30, 30 );
			$this->text_right_aligned( $page_w - $mr, $cy + 4, $data['company_name'] );
			$cy += 6;
		}

		$this->set_font( 'Helvetica', 8 );
		$this->set_text_color( 60, 60, 60 );

		if ( ! empty( $data['company_address'] ) ) {
			foreach ( explode( "\n", $data['company_address'] ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$this->text_right_aligned( $page_w - $mr, $cy + 3, $line );
					$cy += 4.5;
				}
			}
		}

		if ( ! empty( $data['company_details'] ) ) {
			foreach ( explode( "\n", $data['company_details'] ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$this->text_right_aligned( $page_w - $mr, $cy + 3, $line );
					$cy += 4.5;
				}
			}
		}

		if ( ! empty( $data['vat_code'] ) ) {
			$this->text_right_aligned( $page_w - $mr, $cy + 3, __( 'VAT Code: ', 'invoice-dividi' ) . $data['vat_code'] );
			$cy += 4.5;
		}

		$header_bottom = max( $header_y + $logo_h, $cy ) + 6;

		// Divider line under header.
		$this->set_draw_color( 200, 200, 200 );
		$this->set_line_width( 0.5 );
		$this->h_line( $ml, $header_bottom, $col_w );
		$header_bottom += 4;

		// ---------------------------------------------------------
		// Invoice title + number
		// ---------------------------------------------------------
		$this->set_font( 'Helvetica-Bold', 16 );
		$this->set_text_color( 30, 30, 30 );
		$this->text_at( $ml, $header_bottom + 8, __( 'INVOICE', 'invoice-dividi' ) );

		$this->set_font( 'Helvetica', 9 );
		$this->set_text_color( 80, 80, 80 );
		$this->text_right_aligned(
			$page_w - $mr,
			$header_bottom + 5,
			__( 'Invoice No.: ', 'invoice-dividi' ) . $data['invoice_number']
		);
		$this->text_right_aligned(
			$page_w - $mr,
			$header_bottom + 10,
			__( 'Date: ', 'invoice-dividi' ) . $data['invoice_date']
		);
		$this->text_right_aligned(
			$page_w - $mr,
			$header_bottom + 15,
			__( 'Order: ', 'invoice-dividi' ) . '#' . $data['order_id']
		);

		$y = $header_bottom + 22;

		// ---------------------------------------------------------
		// Billing address block
		// ---------------------------------------------------------
		$this->set_font( 'Helvetica-Bold', 8 );
		$this->set_text_color( 100, 100, 100 );
		$this->text_at( $ml, $y, strtoupper( __( 'Bill To', 'invoice-dividi' ) ) );
		$y += 5;

		if ( ! empty( $data['is_company_purchase'] ) ) {
			// Company customer: show company name, VAT code, registration code.
			$this->set_font( 'Helvetica-Bold', 9 );
			$this->set_text_color( 30, 30, 30 );
			if ( ! empty( $data['customer_company_name'] ) ) {
				$this->text_at( $ml, $y, $data['customer_company_name'] );
				$y += 5;
			}

			$this->set_font( 'Helvetica', 9 );
			if ( ! empty( $data['customer_vat_code'] ) ) {
				$this->text_at( $ml, $y, __( 'VAT Code: ', 'invoice-dividi' ) . $data['customer_vat_code'] );
				$y += 4.5;
			}
			if ( ! empty( $data['customer_reg_code'] ) ) {
				$this->text_at( $ml, $y, __( 'Reg. Code: ', 'invoice-dividi' ) . $data['customer_reg_code'] );
				$y += 4.5;
			}
		} else {
			// Individual customer: show standard WooCommerce billing details.
			$this->set_font( 'Helvetica-Bold', 9 );
			$this->set_text_color( 30, 30, 30 );
			if ( ! empty( $data['billing_name'] ) ) {
				$this->text_at( $ml, $y, $data['billing_name'] );
				$y += 5;
			}
			if ( ! empty( $data['billing_company'] ) ) {
				$this->text_at( $ml, $y, $data['billing_company'] );
				$y += 5;
			}

			$this->set_font( 'Helvetica', 9 );
			if ( ! empty( $data['billing_address'] ) ) {
				foreach ( explode( "\n", $data['billing_address'] ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$this->text_at( $ml, $y, $line );
						$y += 4.5;
					}
				}
			}
			if ( ! empty( $data['billing_email'] ) ) {
				$this->text_at( $ml, $y, $data['billing_email'] );
				$y += 4.5;
			}
		}

		$y += 6;

		// ---------------------------------------------------------
		// Products table
		// ---------------------------------------------------------
		$y = $this->draw_items_table( $data, $ml, $y, $col_w );

		// ---------------------------------------------------------
		// Totals block
		// ---------------------------------------------------------
		$y += 4;
		$totals_x     = $page_w / 2;
		$totals_label = $totals_x;
		$totals_value = $page_w - $mr;

		$currency = $data['order_currency'];

		$this->draw_total_row( $totals_label, $totals_value, $y, __( 'Subtotal', 'invoice-dividi' ), $currency . number_format( $data['subtotal'], 2 ) );
		$y += 6;

		if ( $data['vat_enabled'] ) {
			$vat_label = sprintf( '%s (%s%%)', __( 'VAT / PVM', 'invoice-dividi' ), $data['vat_percentage'] );
			$this->draw_total_row( $totals_label, $totals_value, $y, $vat_label, $currency . number_format( $data['vat_amount'], 2 ) );
			$y += 6;
		}

		// Total row with background.
		$total_row_h = 8;
		$this->set_fill_color( 40, 60, 100 );
		$this->filled_rect( $totals_x - 2, $y, $totals_value - $totals_x + 4, $total_row_h );

		$this->set_font( 'Helvetica-Bold', 10 );
		$this->set_text_color( 255, 255, 255 );
		$this->text_at( $totals_label, $y + 5.5, __( 'TOTAL', 'invoice-dividi' ) );
		$this->text_right_aligned( $totals_value, $y + 5.5, $currency . number_format( $data['total'], 2 ) );
		$y += $total_row_h + 4;

		// ---------------------------------------------------------
		// Footer text
		// ---------------------------------------------------------
		if ( ! empty( $data['footer_text'] ) ) {
			// Strip HTML to plain text for the PDF.
			$footer_plain = wp_strip_all_tags( $data['footer_text'] );
			$footer_plain = html_entity_decode( $footer_plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			// Ensure footer doesn't overlap content — push to near-bottom.
			$footer_y = max( $y + 10, self::PAGE_H_MM - 35 );

			$this->set_draw_color( 200, 200, 200 );
			$this->set_line_width( 0.3 );
			$this->h_line( $ml, $footer_y, $col_w );
			$footer_y += 5;

			$this->set_font( 'Helvetica', 7 );
			$this->set_text_color( 120, 120, 120 );

			foreach ( explode( "\n", wordwrap( $footer_plain, 110, "\n", false ) ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$this->text_at( $ml, $footer_y, $line );
					$footer_y += 4;
				}
			}
		}

		// Page border.
		$this->set_draw_color( 220, 220, 220 );
		$this->set_line_width( 0.3 );
		$this->rect_stroke( 8, 8, $page_w - 16, self::PAGE_H_MM - 16 );
	}

	/**
	 * Draw the line-items table. Returns the new Y position after the table.
	 *
	 * @param array $data  Invoice data.
	 * @param float $x     Left edge in mm.
	 * @param float $y     Top edge in mm.
	 * @param float $width Total usable width in mm.
	 * @return float New Y after table.
	 */
	private function draw_items_table( array $data, $x, $y, $width ) {
		// Column widths.
		$col_name  = $width * 0.46;
		$col_qty   = $width * 0.10;
		$col_price = $width * 0.20;
		$col_total = $width * 0.24;

		$row_h   = 7;
		$head_h  = 8;
		$text_dy = 5; // vertical text offset within cell.

		// Header row background.
		$this->set_fill_color( 40, 60, 100 );
		$this->filled_rect( $x, $y, $width, $head_h );

		$this->set_font( 'Helvetica-Bold', 9 );
		$this->set_text_color( 255, 255, 255 );

		$cx = $x + 2;
		$this->text_at( $cx, $y + $text_dy, __( 'Product', 'invoice-dividi' ) );
		$cx += $col_name;
		$this->text_right_aligned( $cx - 2, $y + $text_dy, __( 'Qty', 'invoice-dividi' ) );
		$cx += $col_qty;
		$this->text_right_aligned( $cx - 2, $y + $text_dy, __( 'Unit Price', 'invoice-dividi' ) );
		$cx += $col_price;
		$this->text_right_aligned( $cx - 2, $y + $text_dy, __( 'Total', 'invoice-dividi' ) );

		$y += $head_h;

		// Data rows.
		$currency = $data['order_currency'];
		foreach ( $data['items'] as $idx => $item ) {
			$bg = ( 0 === $idx % 2 ) ? array( 248, 249, 252 ) : array( 255, 255, 255 );
			$this->set_fill_color( $bg[0], $bg[1], $bg[2] );
			$this->filled_rect( $x, $y, $width, $row_h );

			$this->set_font( 'Helvetica', 8 );
			$this->set_text_color( 40, 40, 40 );

			$cx = $x + 2;
			$this->text_at( $cx, $y + $text_dy - 1, mb_strimwidth( $item['name'], 0, 55, '...' ) );
			$cx += $col_name;
			$this->text_right_aligned( $cx - 2, $y + $text_dy - 1, (string) $item['qty'] );
			$cx += $col_qty;
			$this->text_right_aligned( $cx - 2, $y + $text_dy - 1, $currency . number_format( $item['unit_price'], 2 ) );
			$cx += $col_price;
			$this->text_right_aligned( $cx - 2, $y + $text_dy - 1, $currency . number_format( $item['line_total'], 2 ) );

			$y += $row_h;
		}

		// Bottom border of table.
		$this->set_draw_color( 180, 190, 210 );
		$this->set_line_width( 0.3 );
		$this->h_line( $x, $y, $width );

		return $y;
	}

	/**
	 * Draw a labelled total row (e.g. Subtotal, VAT).
	 *
	 * @param float  $label_x X of label start.
	 * @param float  $value_x X of right-aligned value.
	 * @param float  $y       Vertical position.
	 * @param string $label   Label text.
	 * @param string $value   Value text.
	 */
	private function draw_total_row( $label_x, $value_x, $y, $label, $value ) {
		$this->set_font( 'Helvetica', 9 );
		$this->set_text_color( 60, 60, 60 );
		$this->text_at( $label_x, $y, $label );
		$this->text_right_aligned( $value_x, $y, $value );
	}

	// ----------------------------------------------------------------
	// Drawing primitives (coordinates in mm, origin top-left)
	// ----------------------------------------------------------------

	/**
	 * Draw text at an absolute position.
	 *
	 * @param float  $x_mm X in mm.
	 * @param float  $y_mm Y in mm (from top).
	 * @param string $text UTF-8 text.
	 */
	private function text_at( $x_mm, $y_mm, $text ) {
		$text = $this->encode_text( $text );
		$x    = $this->mm( $x_mm );
		$y    = $this->y_flip( $y_mm );
		$fs   = $this->font_size;

		list( $r, $g, $b ) = $this->text_color;
		$rc = round( $r / 255, 3 );
		$gc = round( $g / 255, 3 );
		$bc = round( $b / 255, 3 );

		$font_ref = $this->font_resource_name( $this->font_name );

		$this->stream .= sprintf(
			"BT\n/%s %d Tf\n%.3f %.3f %.3f rg\n%.4f %.4f Td\n(%s) Tj\nET\n",
			$font_ref,
			$fs,
			$rc, $gc, $bc,
			$x, $y,
			$text
		);
	}

	/**
	 * Draw right-aligned text, with its right edge at $right_x_mm.
	 *
	 * @param float  $right_x_mm Right edge X in mm.
	 * @param float  $y_mm       Y in mm (from top).
	 * @param string $text       UTF-8 text.
	 */
	private function text_right_aligned( $right_x_mm, $y_mm, $text ) {
		$text_encoded = $this->encode_text( $text );
		$text_w_pt    = $this->string_width( $text_encoded, $this->font_name, $this->font_size );
		$x_mm         = $right_x_mm - ( $text_w_pt / self::PT_PER_MM );
		$this->text_at( $x_mm, $y_mm, $text );
	}

	/**
	 * Draw a horizontal line.
	 *
	 * @param float $x_mm  Left x.
	 * @param float $y_mm  Y (from top).
	 * @param float $w_mm  Width.
	 */
	private function h_line( $x_mm, $y_mm, $w_mm ) {
		$x1 = $this->mm( $x_mm );
		$x2 = $this->mm( $x_mm + $w_mm );
		$y  = $this->y_flip( $y_mm );

		list( $r, $g, $b ) = $this->draw_color;
		$rc = round( $r / 255, 3 );
		$gc = round( $g / 255, 3 );
		$bc = round( $b / 255, 3 );
		$lw = round( $this->line_width * self::PT_PER_MM, 4 );

		$this->stream .= sprintf(
			"%.3f %.3f %.3f RG\n%.4f w\n%.4f %.4f m %.4f %.4f l S\n",
			$rc, $gc, $bc, $lw, $x1, $y, $x2, $y
		);
	}

	/**
	 * Draw a stroked rectangle (no fill).
	 *
	 * @param float $x_mm X.
	 * @param float $y_mm Y (from top).
	 * @param float $w_mm Width.
	 * @param float $h_mm Height.
	 */
	private function rect_stroke( $x_mm, $y_mm, $w_mm, $h_mm ) {
		$x  = $this->mm( $x_mm );
		$y  = $this->y_flip( $y_mm + $h_mm ); // PDF y-coords from bottom.
		$w  = $this->mm( $w_mm );
		$h  = $this->mm( $h_mm );
		$lw = round( $this->line_width * self::PT_PER_MM, 4 );

		list( $r, $g, $b ) = $this->draw_color;
		$rc = round( $r / 255, 3 );
		$gc = round( $g / 255, 3 );
		$bc = round( $b / 255, 3 );

		$this->stream .= sprintf(
			"%.3f %.3f %.3f RG\n%.4f w\n%.4f %.4f %.4f %.4f re\nS\n",
			$rc, $gc, $bc, $lw, $x, $y, $w, $h
		);
	}

	/**
	 * Draw a filled rectangle.
	 *
	 * @param float $x_mm X.
	 * @param float $y_mm Y (from top).
	 * @param float $w_mm Width.
	 * @param float $h_mm Height.
	 */
	private function filled_rect( $x_mm, $y_mm, $w_mm, $h_mm ) {
		$x = $this->mm( $x_mm );
		$y = $this->y_flip( $y_mm + $h_mm );
		$w = $this->mm( $w_mm );
		$h = $this->mm( $h_mm );

		list( $r, $g, $b ) = $this->fill_color;
		$rc = round( $r / 255, 3 );
		$gc = round( $g / 255, 3 );
		$bc = round( $b / 255, 3 );

		$this->stream .= sprintf(
			"%.3f %.3f %.3f rg\n%.4f %.4f %.4f %.4f re\nf\n",
			$rc, $gc, $bc, $x, $y, $w, $h
		);
	}

	/**
	 * Draw an image XObject on the current page.
	 *
	 * @param string $key   Image key registered with register_image().
	 * @param float  $x_mm  X (left edge).
	 * @param float  $y_mm  Y (top edge).
	 * @param float  $w_mm  Display width in mm.
	 * @param float  $h_mm  Display height in mm.
	 */
	private function draw_image( $key, $x_mm, $y_mm, $w_mm, $h_mm ) {
		if ( ! isset( $this->image_obj_ids[ $key ] ) ) {
			return;
		}
		$img_ref = 'Im' . $key;
		$x = $this->mm( $x_mm );
		$y = $this->y_flip( $y_mm + $h_mm );
		$w = $this->mm( $w_mm );
		$h = $this->mm( $h_mm );

		$this->stream .= sprintf(
			"q\n%.4f 0 0 %.4f %.4f %.4f cm\n/%s Do\nQ\n",
			$w, $h, $x, $y, $img_ref
		);
	}

	// ----------------------------------------------------------------
	// State setters
	// ----------------------------------------------------------------

	/**
	 * @param string $name Font name ('Helvetica' or 'Helvetica-Bold').
	 * @param int    $size Point size.
	 */
	private function set_font( $name, $size ) {
		$this->font_name = $name;
		$this->font_size = $size;
	}

	/** @param int $r @param int $g @param int $b */
	private function set_fill_color( $r, $g, $b ) {
		$this->fill_color = array( $r, $g, $b );
	}

	/** @param int $r @param int $g @param int $b */
	private function set_text_color( $r, $g, $b ) {
		$this->text_color = array( $r, $g, $b );
	}

	/** @param int $r @param int $g @param int $b */
	private function set_draw_color( $r, $g, $b ) {
		$this->draw_color = array( $r, $g, $b );
	}

	/** @param float $w Line width in mm. */
	private function set_line_width( $w ) {
		$this->line_width = $w;
	}

	// ----------------------------------------------------------------
	// Font registration & width estimation
	// ----------------------------------------------------------------

	/**
	 * Register a standard PDF font.
	 *
	 * @param string $name Base font name (e.g. 'Helvetica-Bold').
	 */
	private function register_font( $name ) {
		if ( isset( $this->font_obj_ids[ $name ] ) ) {
			return;
		}
		// Font object will be written during compile. Just record the name.
		$this->font_obj_ids[ $name ] = 0; // Placeholder; ID assigned in compile.
	}

	/**
	 * Return the PDF resource name for a font.
	 *
	 * @param string $font_name Font name.
	 * @return string  e.g. 'F1'.
	 */
	private function font_resource_name( $font_name ) {
		$fonts = array_keys( $this->font_obj_ids );
		$idx   = array_search( $font_name, $fonts, true );
		return 'F' . ( $idx + 1 );
	}

	/**
	 * Estimate the width of a string in points.
	 *
	 * Uses approximate character widths for Helvetica.
	 *
	 * @param string $text      Encoded string.
	 * @param string $font_name Font name.
	 * @param int    $font_size Point size.
	 * @return float Width in points.
	 */
	private function string_width( $text, $font_name, $font_size ) {
		// Average char width as a fraction of font size for Helvetica.
		$avg = ( strpos( $font_name, 'Bold' ) !== false ) ? 0.58 : 0.54;
		return strlen( $text ) * $font_size * $avg;
	}

	// ----------------------------------------------------------------
	// Image registration
	// ----------------------------------------------------------------

	/**
	 * Register an image file for use in the PDF.
	 *
	 * Supports JPEG and PNG.  Returns a key string used with draw_image().
	 * Returns empty string on failure.
	 *
	 * @param string $url Image URL (will be resolved to a local path).
	 * @return string Image key, or empty string on failure.
	 */
	private function register_image( $url ) {
		// Try to resolve URL to a local file path.
		$local_path = $this->url_to_path( $url );

		if ( empty( $local_path ) || ! file_exists( $local_path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $local_path, PATHINFO_EXTENSION ) );

		if ( 'jpg' === $ext || 'jpeg' === $ext ) {
			return $this->register_jpeg( $local_path );
		}

		if ( 'png' === $ext ) {
			return $this->register_png( $local_path );
		}

		return '';
	}

	/**
	 * Register a JPEG image.
	 *
	 * @param string $path Absolute file path.
	 * @return string Image key.
	 */
	private function register_jpeg( $path ) {
		$data = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data ) {
			return '';
		}
		$size = @getimagesize( $path );
		if ( ! $size ) {
			return '';
		}
		$key = (string) ( count( $this->image_obj_ids ) + 1 );
		$this->image_obj_ids[ $key ] = array(
			'type'    => 'jpeg',
			'data'    => $data,
			'w'       => $size[0],
			'h'       => $size[1],
			'obj_id'  => 0,
		);
		return $key;
	}

	/**
	 * Register a PNG image.
	 *
	 * @param string $path Absolute file path.
	 * @return string Image key.
	 */
	private function register_png( $path ) {
		$data = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data ) {
			return '';
		}
		$size = @getimagesize( $path );
		if ( ! $size ) {
			return '';
		}

		// Parse PNG header to determine colour type and bit depth.
		if ( strlen( $data ) < 33 ) {
			return '';
		}
		$bit_depth  = ord( $data[24] );
		$color_type = ord( $data[25] );

		$cs = 'DeviceRGB';
		if ( 0 === $color_type ) {
			$cs = 'DeviceGray';
		} elseif ( 2 === $color_type ) {
			$cs = 'DeviceRGB';
		} elseif ( 3 === $color_type ) {
			$cs = 'DeviceRGB'; // Palette – we convert below.
		} elseif ( 4 === $color_type ) {
			$cs = 'DeviceGray'; // Gray + alpha – simplified.
		} elseif ( 6 === $color_type ) {
			$cs = 'DeviceRGB'; // RGBA.
		}

		$key = (string) ( count( $this->image_obj_ids ) + 1 );
		$this->image_obj_ids[ $key ] = array(
			'type'       => 'png',
			'data'       => $data,
			'w'          => $size[0],
			'h'          => $size[1],
			'bit_depth'  => $bit_depth,
			'color_type' => $color_type,
			'cs'         => $cs,
			'obj_id'     => 0,
		);
		return $key;
	}

	/**
	 * Convert a URL to an absolute server-side file path.
	 *
	 * @param string $url Image URL.
	 * @return string|false Absolute path, or false.
	 */
	private function url_to_path( $url ) {
		$upload_dir = wp_upload_dir();

		// Replace upload URL with path.
		if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
			return str_replace(
				$upload_dir['baseurl'],
				$upload_dir['basedir'],
				$url
			);
		}

		// Replace site URL with ABSPATH.
		$site_url = site_url( '/' );
		if ( strpos( $url, $site_url ) === 0 ) {
			return ABSPATH . ltrim( str_replace( $site_url, '', $url ), '/' );
		}

		return false;
	}

	// ----------------------------------------------------------------
	// Page management
	// ----------------------------------------------------------------

	/**
	 * Start a new page.
	 */
	private function begin_page() {
		$this->stream = '';
	}

	/**
	 * Finish the current page and store its stream.
	 */
	private function end_page() {
		$this->page_streams[] = $this->stream;
	}

	// ----------------------------------------------------------------
	// PDF compilation
	// ----------------------------------------------------------------

	/**
	 * Compile all objects into a valid PDF binary string.
	 *
	 * @return string Raw PDF binary.
	 */
	private function compile_pdf() {
		$out = $this->buf; // Contains the header so far.

		// We need to know obj IDs ahead of time, so let's assign them:
		// 1 = Catalog, 2 = Pages, 3..n = Page objects, then fonts, then images, then content streams.

		$n_pages  = count( $this->page_streams );
		$n_fonts  = count( $this->font_obj_ids );
		$n_images = count( $this->image_obj_ids );

		// Assign IDs:
		$catalog_id    = 1;
		$pages_id      = 2;
		$page_base_id  = 3;                     // Pages 3 .. (3 + n_pages - 1)
		$font_base_id  = $page_base_id + $n_pages;
		$image_base_id = $font_base_id + $n_fonts;
		$stream_base_id = $image_base_id + $n_images;
		$total_objs    = $stream_base_id + $n_pages; // streams start at stream_base_id.

		// Update font obj IDs.
		$font_names = array_keys( $this->font_obj_ids );
		foreach ( $font_names as $i => $name ) {
			$this->font_obj_ids[ $name ] = $font_base_id + $i;
		}

		// Update image obj IDs.
		$image_keys = array_keys( $this->image_obj_ids );
		foreach ( $image_keys as $i => $key ) {
			$this->image_obj_ids[ $key ]['obj_id'] = $image_base_id + $i;
		}

		$offsets = array(); // obj_id => byte offset

		// ---------------------------------------------------------
		// Catalog object
		// ---------------------------------------------------------
		$offsets[ $catalog_id ] = strlen( $out );
		$out .= "$catalog_id 0 obj\n<< /Type /Catalog /Pages $pages_id 0 R >>\nendobj\n";

		// ---------------------------------------------------------
		// Pages object
		// ---------------------------------------------------------
		$page_refs = '';
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$page_refs .= ( $page_base_id + $i ) . ' 0 R ';
		}
		$offsets[ $pages_id ] = strlen( $out );
		$out .= "$pages_id 0 obj\n<< /Type /Pages /Kids [$page_refs] /Count $n_pages >>\nendobj\n";

		// ---------------------------------------------------------
		// Page objects
		// ---------------------------------------------------------
		// Build resource dictionaries.
		$font_res = '';
		foreach ( $font_names as $i => $fname ) {
			$fobj_id  = $this->font_obj_ids[ $fname ];
			$fres_key = 'F' . ( $i + 1 );
			$font_res .= "/$fres_key $fobj_id 0 R ";
		}

		$image_res = '';
		foreach ( $image_keys as $i => $ikey ) {
			$iobj_id  = $this->image_obj_ids[ $ikey ]['obj_id'];
			$ires_key = 'Im' . $ikey;
			$image_res .= "/$ires_key $iobj_id 0 R ";
		}

		for ( $i = 0; $i < $n_pages; $i++ ) {
			$page_id    = $page_base_id + $i;
			$stream_id  = $stream_base_id + $i;

			$resources  = '<< /Font << ' . $font_res . '>> /ProcSet [/PDF /Text /ImageC /ImageB /ImageI]';
			if ( $image_res ) {
				$resources .= ' /XObject << ' . $image_res . '>>';
			}
			$resources .= ' >>';

			$offsets[ $page_id ] = strlen( $out );
			$out .= sprintf(
				"%d 0 obj\n<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources %s >>\nendobj\n",
				$page_id,
				$pages_id,
				$this->pw_pt,
				$this->ph_pt,
				$stream_id,
				$resources
			);
		}

		// ---------------------------------------------------------
		// Font objects
		// ---------------------------------------------------------
		foreach ( $font_names as $i => $fname ) {
			$fobj_id = $this->font_obj_ids[ $fname ];
			$offsets[ $fobj_id ] = strlen( $out );
			$out .= sprintf(
				"%d 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>\nendobj\n",
				$fobj_id,
				$fname
			);
		}

		// ---------------------------------------------------------
		// Image objects
		// ---------------------------------------------------------
		foreach ( $image_keys as $ikey ) {
			$img     = $this->image_obj_ids[ $ikey ];
			$iobj_id = $img['obj_id'];

			if ( 'jpeg' === $img['type'] ) {
				$raw     = $img['data'];
				$raw_len = strlen( $raw );
				$offsets[ $iobj_id ] = strlen( $out );
				$out .= sprintf(
					"%d 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n",
					$iobj_id,
					$img['w'],
					$img['h'],
					$raw_len
				);
				$out .= $raw;
				$out .= "\nendstream\nendobj\n";
			} elseif ( 'png' === $img['type'] ) {
				// Extract raw image data from PNG using GD, then write as a flat RGB stream.
				$img_data = $this->png_to_rgb_stream( $img );
				if ( null !== $img_data ) {
					$raw     = $img_data['data'];
					$raw_len = strlen( $raw );
					$cs      = $img_data['cs'];
					$bpc     = $img_data['bpc'];

					$offsets[ $iobj_id ] = strlen( $out );
					$out .= sprintf(
						"%d 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Length %d >>\nstream\n",
						$iobj_id,
						$img['w'],
						$img['h'],
						$cs,
						$bpc,
						$raw_len
					);
					$out .= $raw;
					$out .= "\nendstream\nendobj\n";
				} else {
					// Fallback: write a 1×1 white pixel.
					$dummy = "\xff\xff\xff";
					$offsets[ $iobj_id ] = strlen( $out );
					$out .= sprintf(
						"%d 0 obj\n<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length 3 >>\nstream\n%s\nendstream\nendobj\n",
						$iobj_id,
						$dummy
					);
				}
			}
		}

		// ---------------------------------------------------------
		// Content stream objects
		// ---------------------------------------------------------
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$stream_id  = $stream_base_id + $i;
			$stream_raw = $this->page_streams[ $i ];
			$stream_len = strlen( $stream_raw );

			$offsets[ $stream_id ] = strlen( $out );
			$out .= sprintf(
				"%d 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n",
				$stream_id,
				$stream_len,
				$stream_raw
			);
		}

		// ---------------------------------------------------------
		// Cross-reference table
		// ---------------------------------------------------------
		$xref_offset = strlen( $out );
		$total_objs_count = $total_objs; // total objects INCLUDING object 0.
		$out .= "xref\n0 $total_objs_count\n";
		// Object 0 (free).
		$out .= "0000000000 65535 f \n";

		for ( $oid = 1; $oid < $total_objs_count; $oid++ ) {
			if ( isset( $offsets[ $oid ] ) ) {
				$out .= sprintf( "%010d 00000 n \n", $offsets[ $oid ] );
			} else {
				$out .= "0000000000 65535 f \n";
			}
		}

		// ---------------------------------------------------------
		// Trailer
		// ---------------------------------------------------------
		$out .= sprintf(
			"trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
			$total_objs_count,
			$catalog_id,
			$xref_offset
		);

		return $out;
	}

	// ----------------------------------------------------------------
	// PNG helper
	// ----------------------------------------------------------------

	/**
	 * Convert a PNG image to a flat uncompressed RGB (or Gray) stream using GD.
	 *
	 * @param array $img Image info array.
	 * @return array|null Array with 'data', 'cs', 'bpc'; or null on failure.
	 */
	private function png_to_rgb_stream( array $img ) {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return null;
		}

		$tmp = tmpfile();
		if ( ! $tmp ) {
			return null;
		}
		fwrite( $tmp, $img['data'] );
		$meta = stream_get_meta_data( $tmp );
		$path = $meta['uri'];

		$gd = @imagecreatefrompng( $path );
		fclose( $tmp );

		if ( ! $gd ) {
			return null;
		}

		$w   = $img['w'];
		$h   = $img['h'];
		$raw = '';

		for ( $row = 0; $row < $h; $row++ ) {
			for ( $col = 0; $col < $w; $col++ ) {
				$color = imagecolorat( $gd, $col, $row );
				$r     = ( $color >> 16 ) & 0xFF;
				$g     = ( $color >> 8 ) & 0xFF;
				$b     = $color & 0xFF;
				$raw  .= chr( $r ) . chr( $g ) . chr( $b );
			}
		}

		imagedestroy( $gd );

		return array(
			'data' => $raw,
			'cs'   => 'DeviceRGB',
			'bpc'  => 8,
		);
	}

	// ----------------------------------------------------------------
	// Coordinate / encoding helpers
	// ----------------------------------------------------------------

	/**
	 * Convert mm to PDF points.
	 *
	 * @param float $mm Millimetres.
	 * @return float Points.
	 */
	private function mm( $mm ) {
		return $mm * self::PT_PER_MM;
	}

	/**
	 * Flip Y coordinate from top-origin (mm) to PDF bottom-origin (pt).
	 *
	 * @param float $y_mm Y from top in mm.
	 * @return float Y from bottom in points.
	 */
	private function y_flip( $y_mm ) {
		return $this->ph_pt - ( $y_mm * self::PT_PER_MM );
	}

	/**
	 * Encode a UTF-8 string for embedding in a PDF string literal.
	 *
	 * Converts to ISO-8859-1 where possible, escaping special PDF
	 * characters.  Characters outside ISO-8859-1 are replaced with '?'.
	 *
	 * @param string $text UTF-8 text.
	 * @return string PDF-safe encoded string (without surrounding parentheses).
	 */
	private function encode_text( $text ) {
		// Convert UTF-8 to Windows-1252 (a superset of ISO-8859-1).
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
		} else {
			$text = utf8_decode( $text );
		}

		// Escape PDF special characters.
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( '(', '\\(', $text );
		$text = str_replace( ')', '\\)', $text );

		return $text;
	}
}
