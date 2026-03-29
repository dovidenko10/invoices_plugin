<?php
/**
 * PDF generator for Invoice Dividi.
 *
 * Produces a single-page PDF 1.4 invoice document.
 *
 * FONT STRATEGY
 * -------------
 * Liberation Sans (Regular + Bold) is bundled in assets/fonts/ and
 * embedded as a Type0 / CIDFontType2 font with Identity-H encoding.
 * This gives full Unicode support, including all Lithuanian characters
 * (ą č ę ė į š ų ū ž and their upper-case equivalents).
 *
 * Text is output as UTF-16BE glyph-ID hex strings <NNNN…>, which the
 * PDF viewer resolves through the embedded font tables.
 *
 * If the font files are absent (e.g. corrupted installation) the code
 * falls back to built-in Type1 Helvetica with WinAnsiEncoding.  In
 * that fallback mode Lithuanian characters will not render, but the
 * invoice will still be generated.
 *
 * WHY THE OLD CODE BROKE LITHUANIAN
 * -----------------------------------
 * The original code used /WinAnsiEncoding (cp1252) together with
 * mb_convert_encoding(…,'Windows-1252').  The Lithuanian characters
 * ą č ę ė į š ų ū ž are NOT part of cp1252 – they live in cp1257
 * (Baltic) which the built-in PDF fonts do not include at all.
 * Converting them to cp1252 produced '?' or garbage bytes.
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

	/** @var string  Content stream being built for the current page. */
	private $stream = '';

	/** @var array   List of page content-stream strings. */
	private $page_streams = array();

	/** @var array   Fonts: name => placeholder (0); real ID assigned in compile). */
	private $font_obj_ids = array();

	/** @var array   TTF objects: font-name => Invoice_Dividi_TTF instance. */
	private $ttf_map = array();

	/** @var array   Images: key => [ 'obj_id', 'w', 'h', 'type', 'data', … ]. */
	private $image_obj_ids = array();

	/** @var int     Page-width in points. */
	private $pw_pt;

	/** @var int     Page-height in points. */
	private $ph_pt;

	// Current drawing state (mm from top-left).
	/** @var string */ private $font_name = 'Helvetica';
	/** @var int    */ private $font_size = 10;
	/** @var int[]  */ private $fill_color = array( 255, 255, 255 );
	/** @var int[]  */ private $text_color = array( 0, 0, 0 );
	/** @var int[]  */ private $draw_color = array( 0, 0, 0 );
	/** @var float  */ private $line_width = 0.3;

	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Generate the complete PDF for an invoice.
	 *
	 * @param array $data Invoice data from Invoice_Dividi_Invoice::build_invoice_data().
	 * @return string|\WP_Error Raw PDF binary or WP_Error on failure.
	 */
	public function generate( array $data ) {
		$this->pw_pt = (int) round( self::PAGE_W_MM * self::PT_PER_MM );
		$this->ph_pt = (int) round( self::PAGE_H_MM * self::PT_PER_MM );

		$this->buf    = '';
		$this->n      = 0;
		$this->stream = '';

		$this->buf .= "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";

		// ---------------------------------------------------------
		// Font registration
		// ---------------------------------------------------------
		$font_dir = INVOICE_DIVIDI_DIR . 'assets/fonts/';
		$reg_path = $font_dir . 'LiberationSans-Regular.ttf';
		$bld_path = $font_dir . 'LiberationSans-Bold.ttf';

		if ( file_exists( $reg_path ) && file_exists( $bld_path ) ) {
			$this->load_ttf_font( 'Helvetica',      $reg_path );
			$this->load_ttf_font( 'Helvetica-Bold', $bld_path );
			} else {
			// Fallback – no Lithuanian support.
			$this->register_type1_font( 'Helvetica' );
			$this->register_type1_font( 'Helvetica-Bold' );
		}

		// ---------------------------------------------------------
		// Logo image
		// ---------------------------------------------------------
		$logo_key = '';
		if ( ! empty( $data['logo_url'] ) ) {
			$logo_key = $this->register_image( $data['logo_url'] );
		}

		$this->begin_page();
		$this->draw_invoice( $data, $logo_key );
		$this->end_page();

		return $this->compile_pdf();
	}

	// ----------------------------------------------------------------
	// Font helpers
	// ----------------------------------------------------------------

	/**
	 * Load a TTF font and register it under the given logical name.
	 *
	 * @param string $logical_name  Name used by drawing code ('Helvetica', 'Helvetica-Bold').
	 * @param string $path          Absolute path to the .ttf file.
	 */
	private function load_ttf_font( $logical_name, $path ) {
		$ttf = new Invoice_Dividi_TTF( $path );
		$this->ttf_map[ $logical_name ]      = $ttf;
		$this->font_obj_ids[ $logical_name ] = 0; // Real ID assigned later.
	}

	/**
	 * Register a built-in Type1 font (fallback when TTF is unavailable).
	 *
	 * @param string $name  Base font name, e.g. 'Helvetica'.
	 */
	private function register_type1_font( $name ) {
		if ( ! isset( $this->font_obj_ids[ $name ] ) ) {
			$this->font_obj_ids[ $name ] = 0;
		}
	}

	// ----------------------------------------------------------------
	// Invoice layout
	// ----------------------------------------------------------------

	/**
	 * Draw the complete invoice layout onto the current page.
	 *
	 * @param array  $data     Invoice data.
	 * @param string $logo_key Image key (empty string = no logo).
	 */
	private function draw_invoice( array $data, $logo_key ) {
		$pw  = self::PAGE_W_MM;
		$ml  = 15;           // left margin
		$mr  = 15;           // right margin
		$cw  = $pw - $ml - $mr; // 180 mm usable width

		// ============================================================
		// SECTION 1 – HEADER  (logo left | company info right)
		// ============================================================
		$hy     = 15;  // header top Y
		$logo_h = 0;

		if ( ! empty( $logo_key ) ) {
			$img    = $this->image_obj_ids[ $logo_key ];
			$lw     = 55.0;
			$lh     = $lw * $img['h'] / max( $img['w'], 1 );
			if ( $lh > 25 ) {
				$lh = 25.0;
				$lw = $lh * $img['w'] / max( $img['h'], 1 );
			}
			$this->draw_image( $logo_key, $ml, $hy, $lw, $lh );
			$logo_h = $lh;
		} else {
			// No logo – render company name as a large text title.
			$this->set_font( 'Helvetica-Bold', 15 );
			$this->set_text_color( 28, 40, 70 );
			$display_name = ! empty( $data['company_name'] ) ? $data['company_name'] : get_bloginfo( 'name' );
			$this->text_at( $ml, $hy + 9, $display_name );
			$logo_h = 12;
		}

		// Right column – company info.
		$cy = $hy;

		if ( ! empty( $data['company_name'] ) && ! empty( $logo_key ) ) {
			$this->set_font( 'Helvetica-Bold', 11 );
			$this->set_text_color( 28, 40, 70 );
			$this->text_right_aligned( $pw - $mr, $cy + 5, $data['company_name'] );
			$cy += 6.5;
		}

		$this->set_font( 'Helvetica', 8 );
		$this->set_text_color( 70, 70, 70 );

		foreach ( explode( "\n", (string) $data['company_address'] ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$this->text_right_aligned( $pw - $mr, $cy + 4, $line );
				$cy += 4.5;
			}
		}

		foreach ( explode( "\n", (string) $data['company_details'] ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$this->text_right_aligned( $pw - $mr, $cy + 4, $line );
				$cy += 4.5;
			}
		}

		if ( ! empty( $data['vat_code'] ) ) {
			$this->text_right_aligned(
			$pw - $mr,
			$cy + 4,
			/* translators: followed by the company VAT code */
			__( 'PVM kodas: ', 'invoice-dividi' ) . $data['vat_code']
			);
			$cy += 4.5;
		}

		$header_bottom = max( $hy + $logo_h, $cy ) + 5;

		// Divider under header.
		$this->set_draw_color( 180, 180, 180 );
		$this->set_line_width( 0.5 );
		$this->h_line( $ml, $header_bottom, $cw );
		$header_bottom += 5;

		// ============================================================
		// SECTION 2 – INVOICE TITLE
		// ============================================================
		$this->set_font( 'Helvetica-Bold', 18 );
		$this->set_text_color( 28, 40, 70 );
		$this->text_at( $ml, $header_bottom + 9, __( 'INVOICE', 'invoice-dividi' ) );

		$title_bottom = $header_bottom + 16;

		// ============================================================
		// SECTION 3 – BILLING ADDRESS (left) + ORDER META (right)
		// ============================================================
		$section3_y  = $title_bottom + 6;
		$billing_w   = $cw * 0.55;
		$order_col_x = $ml + $billing_w + 5;
		$order_col_w = $cw - $billing_w - 5;

		// --- Order meta table (right column) ---
		$meta_rows = array(
		__( 'Invoice No.:', 'invoice-dividi' ) => $data['invoice_number'],
		__( 'Date:', 'invoice-dividi' )        => $data['invoice_date'],
		/* translators: WooCommerce order number, e.g. #1234 */
		__( 'Order No.:', 'invoice-dividi' )   => '#' . $data['order_id'],
		__( 'Order Date:', 'invoice-dividi' )  => $data['order_date'],
		);

		$row_h = 5.5;
		$yd    = $section3_y;

		foreach ( $meta_rows as $label => $value ) {
			$this->set_font( 'Helvetica', 8.5 );
			$this->set_text_color( 100, 100, 100 );
			$this->text_at( $order_col_x, $yd + 3.5, $label );

			$this->set_font( 'Helvetica-Bold', 8.5 );
			$this->set_text_color( 30, 30, 30 );
			$this->text_right_aligned( $pw - $mr, $yd + 3.5, (string) $value );
			$yd += $row_h;
		}

		// --- Billing block (left column) ---
		$yb = $section3_y;

		$this->set_font( 'Helvetica-Bold', 7.5 );
		$this->set_text_color( 130, 130, 130 );
		$this->text_at( $ml, $yb + 3, strtoupper( __( 'Bill To', 'invoice-dividi' ) ) );
		$yb += 5.5;

		if ( ! empty( $data['is_company_purchase'] ) ) {
			// Business customer.
			if ( ! empty( $data['customer_company_name'] ) ) {
				$this->set_font( 'Helvetica-Bold', 9.5 );
				$this->set_text_color( 25, 25, 25 );
				$this->text_at( $ml, $yb + 4, $data['customer_company_name'] );
				$yb += 5.5;
			}
			$this->set_font( 'Helvetica', 8.5 );
			$this->set_text_color( 60, 60, 60 );
			if ( ! empty( $data['customer_vat_code'] ) ) {
				$this->text_at( $ml, $yb + 3.5, __( 'PVM kodas: ', 'invoice-dividi' ) . $data['customer_vat_code'] );
				$yb += 4.5;
			}
			if ( ! empty( $data['customer_reg_code'] ) ) {
				$this->text_at( $ml, $yb + 3.5, __( 'Įm. kodas: ', 'invoice-dividi' ) . $data['customer_reg_code'] );
				$yb += 4.5;
			}
			// Also show billing name and address for the company order.
			if ( ! empty( $data['billing_name'] ) ) {
				$this->text_at( $ml, $yb + 3.5, $data['billing_name'] );
				$yb += 4.5;
			}
			} else {
			// Individual customer.
			if ( ! empty( $data['billing_name'] ) ) {
				$this->set_font( 'Helvetica-Bold', 9.5 );
				$this->set_text_color( 25, 25, 25 );
				$this->text_at( $ml, $yb + 4, $data['billing_name'] );
				$yb += 5.5;
			}
			if ( ! empty( $data['billing_company'] ) ) {
				$this->set_font( 'Helvetica', 8.5 );
				$this->set_text_color( 60, 60, 60 );
				$this->text_at( $ml, $yb + 3.5, $data['billing_company'] );
				$yb += 4.5;
			}
			$this->set_font( 'Helvetica', 8.5 );
			$this->set_text_color( 60, 60, 60 );
			foreach ( explode( "\n", (string) $data['billing_address'] ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$this->text_at( $ml, $yb + 3.5, $line );
					$yb += 4.5;
				}
			}
		}

		if ( ! empty( $data['billing_email'] ) ) {
			$this->set_font( 'Helvetica', 8.5 );
			$this->set_text_color( 60, 60, 60 );
			$this->text_at( $ml, $yb + 3.5, $data['billing_email'] );
			$yb += 4.5;
		}

		$section3_bottom = max( $yb, $yd ) + 8;

		// Light divider between info and table.
		$this->set_draw_color( 210, 215, 220 );
		$this->set_line_width( 0.3 );
		$this->h_line( $ml, $section3_bottom - 4, $cw );

		// ============================================================
		// SECTION 4 – PRODUCTS TABLE
		// ============================================================
		$y = $this->draw_items_table( $data, $ml, $section3_bottom, $cw );

		// ============================================================
		// SECTION 5 – TOTALS
		// ============================================================
		$y            += 5;
		$totals_x      = $ml + $cw * 0.5;
		$totals_end    = $pw - $mr;
		$totals_lbl_w  = $totals_end - $totals_x;
		$currency      = $data['order_currency'];

		// Thin divider above subtotal.
		$this->set_draw_color( 210, 215, 220 );
		$this->set_line_width( 0.3 );
		$this->h_line( $totals_x, $y, $totals_lbl_w );
		$y += 1;

		$this->draw_total_row(
		$totals_x, $totals_end, $y + 4.5,
		__( 'Subtotal', 'invoice-dividi' ),
		$currency . number_format( $data['subtotal'], 2 )
		);
		$y += 6.5;

		if ( $data['vat_enabled'] ) {
			$vat_label = sprintf(
			/* translators: %s = VAT percentage */
			__( 'VAT / PVM (%s%%)', 'invoice-dividi' ),
			$data['vat_percentage']
			);
			$this->draw_total_row(
			$totals_x, $totals_end, $y + 4.5,
			$vat_label,
			$currency . number_format( $data['vat_amount'], 2 )
			);
			$y += 6.5;
		}

		// TOTAL row – dark filled background.
		$total_h = 9.0;
		$this->set_fill_color( 28, 40, 70 );
		$this->filled_rect( $totals_x - 2, $y, $totals_lbl_w + 2, $total_h );

		$this->set_font( 'Helvetica-Bold', 10 );
		$this->set_text_color( 255, 255, 255 );
		$this->text_at( $totals_x + 2, $y + 6, __( 'TOTAL', 'invoice-dividi' ) );
		$this->text_right_aligned( $totals_end, $y + 6, $currency . number_format( $data['total'], 2 ) );
		$y += $total_h + 5;

		// ============================================================
		// SECTION 6 – FOOTER
		// ============================================================
		if ( ! empty( $data['footer_text'] ) ) {
			$footer_plain = wp_strip_all_tags( $data['footer_text'] );
			$footer_plain = html_entity_decode( $footer_plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			$footer_y = max( $y + 10, self::PAGE_H_MM - 28 );

			$this->set_draw_color( 200, 200, 200 );
			$this->set_line_width( 0.3 );
			$this->h_line( $ml, $footer_y, $cw );
			$footer_y += 4;

			$this->set_font( 'Helvetica', 7.5 );
			$this->set_text_color( 130, 130, 130 );

			foreach ( explode( "\n", wordwrap( $footer_plain, 105, "\n", false ) ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$this->text_at( $ml, $footer_y, $line );
					$footer_y += 4;
				}
			}
		}
	}

	/**
	 * Draw the line-items table.  Returns new Y after the table.
	 *
	 * @param array $data  Invoice data.
	 * @param float $x     Left edge (mm).
	 * @param float $y     Top edge (mm).
	 * @param float $width Usable width (mm).
	 * @return float Y after table.
	 */
	private function draw_items_table( array $data, $x, $y, $width ) {
		// Column widths.
		$col_name  = $width * 0.46;
		$col_qty   = $width * 0.10;
		$col_price = $width * 0.20;
		$col_total = $width * 0.24;

		$row_h  = 7.0;
		$head_h = 8.5;
		$text_y = 5.5; // Baseline offset within a row.

		// Header row.
		$this->set_fill_color( 28, 40, 70 );
		$this->filled_rect( $x, $y, $width, $head_h );

		$this->set_font( 'Helvetica-Bold', 9 );
		$this->set_text_color( 255, 255, 255 );

		$cx = $x + 2;
		$this->text_at( $cx, $y + $text_y, __( 'Product', 'invoice-dividi' ) );
		$cx += $col_name;
		$this->text_right_aligned( $cx - 2, $y + $text_y, __( 'Qty', 'invoice-dividi' ) );
		$cx += $col_qty;
		$this->text_right_aligned( $cx - 2, $y + $text_y, __( 'Unit Price', 'invoice-dividi' ) );
		$cx += $col_price;
		$this->text_right_aligned( $cx - 2, $y + $text_y, __( 'Total', 'invoice-dividi' ) );

		$y += $head_h;

		// Data rows.
		$currency = $data['order_currency'];
		foreach ( $data['items'] as $idx => $item ) {
			$bg = ( 0 === $idx % 2 ) ? array( 246, 248, 252 ) : array( 255, 255, 255 );
			$this->set_fill_color( $bg[0], $bg[1], $bg[2] );
			$this->filled_rect( $x, $y, $width, $row_h );

			$this->set_font( 'Helvetica', 8 );
			$this->set_text_color( 40, 40, 40 );

			$cx = $x + 2;

			// Truncate product name to fit the column.
			$name = $item['name'];
			if ( mb_strlen( $name, 'UTF-8' ) > 58 ) {
				$name = mb_substr( $name, 0, 55, 'UTF-8' ) . '…';
			}

			$this->text_at( $cx, $y + $text_y - 1, $name );
			$cx += $col_name;
			$this->text_right_aligned( $cx - 2, $y + $text_y - 1, (string) $item['qty'] );
			$cx += $col_qty;
			$this->text_right_aligned( $cx - 2, $y + $text_y - 1, $currency . number_format( $item['unit_price'], 2 ) );
			$cx += $col_price;
			$this->text_right_aligned( $cx - 2, $y + $text_y - 1, $currency . number_format( $item['line_total'], 2 ) );

			$y += $row_h;
		}

		// Bottom border of table.
		$this->set_draw_color( 160, 175, 200 );
		$this->set_line_width( 0.4 );
		$this->h_line( $x, $y, $width );

		return $y;
	}

	/**
	 * Draw a single totals row (Subtotal / VAT).
	 *
	 * @param float  $label_x Left edge of label (mm).
	 * @param float  $value_x Right edge of value (mm).
	 * @param float  $y       Baseline Y (mm).
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
	// Drawing primitives (mm from top-left)
	// ----------------------------------------------------------------

	/**
	 * Draw text at an absolute position.
	 *
	 * @param float  $x_mm X in mm.
	 * @param float  $y_mm Y in mm (from top).
	 * @param string $text UTF-8 text.
	 */
	private function text_at( $x_mm, $y_mm, $text ) {
		$encoded  = $this->encode_text( $text );
		$x        = $this->mm( $x_mm );
		$y        = $this->y_flip( $y_mm );
		$fs       = $this->font_size;
		$font_ref = $this->font_resource_name( $this->font_name );

		list( $r, $g, $b ) = $this->text_color;
		$rc = round( $r / 255, 3 );
		$gc = round( $g / 255, 3 );
		$bc = round( $b / 255, 3 );

		$this->stream .= sprintf(
		"BT\n/%s %d Tf\n%.3f %.3f %.3f rg\n%.4f %.4f Td\n%s Tj\nET\n",
		$font_ref,
		$fs,
		$rc, $gc, $bc,
		$x, $y,
		$encoded
		);
	}

	/**
	 * Draw right-aligned text with its right edge at $right_x_mm.
	 *
	 * @param float  $right_x_mm Right edge X (mm).
	 * @param float  $y_mm       Y (mm).
	 * @param string $text       UTF-8 text.
	 */
	private function text_right_aligned( $right_x_mm, $y_mm, $text ) {
		$text_w_pt = $this->string_width( $text, $this->font_name, $this->font_size );
		$x_mm      = $right_x_mm - ( $text_w_pt / self::PT_PER_MM );
		$this->text_at( $x_mm, $y_mm, $text );
	}

	/** @param float $x_mm @param float $y_mm @param float $w_mm */
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

	/** Stroked (outline only) rectangle. */
	private function rect_stroke( $x_mm, $y_mm, $w_mm, $h_mm ) {
		$x  = $this->mm( $x_mm );
		$y  = $this->y_flip( $y_mm + $h_mm );
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

	/** Filled (solid) rectangle. */
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

	/** Draw an embedded image XObject. */
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

	private function set_font( $name, $size ) {
		$this->font_name = $name;
		$this->font_size = $size;
	}

	private function set_fill_color( $r, $g, $b ) {
		$this->fill_color = array( $r, $g, $b );
	}

	private function set_text_color( $r, $g, $b ) {
		$this->text_color = array( $r, $g, $b );
	}

	private function set_draw_color( $r, $g, $b ) {
		$this->draw_color = array( $r, $g, $b );
	}

	private function set_line_width( $w ) {
		$this->line_width = $w;
	}

	// ----------------------------------------------------------------
	// Text encoding & width
	// ----------------------------------------------------------------

	/**
	 * Encode a UTF-8 string into the correct PDF string literal.
	 *
	 * With TTF / Identity-H: returns <HHHH…> (hex glyph-ID string).
	 * Fallback Type1:        returns (escaped ISO-8859-1 string).
	 *
	 * @param string $text UTF-8 source.
	 * @return string PDF-ready string token.
	 */
	private function encode_text( $text ) {
		if ( isset( $this->ttf_map[ $this->font_name ] ) ) {
			return $this->ttf_map[ $this->font_name ]->encode_for_pdf( $text );
		}

		// Fallback: Windows-1252 literal string for built-in Type1 fonts.
		// mbstring is always available in WordPress, so no function_exists guard needed.
		$out = mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
		$out = str_replace( '\\', '\\\\', $out );
		$out = str_replace( '(', '\\(',   $out );
		$out = str_replace( ')', '\\)',   $out );
		return '(' . $out . ')';
	}

	/**
	 * Estimate the advance width of a string in points.
	 *
	 * @param string $text      UTF-8 source.
	 * @param string $font_name Font name.
	 * @param float  $font_size Point size.
	 * @return float Width in points.
	 */
	private function string_width( $text, $font_name, $font_size ) {
		if ( isset( $this->ttf_map[ $font_name ] ) ) {
			return $this->ttf_map[ $font_name ]->string_width_pt( $text, $font_size );
		}
		// Fallback approximation for Helvetica.
		$avg        = ( strpos( $font_name, 'Bold' ) !== false ) ? 0.58 : 0.54;
		$char_count = mb_strlen( $text, 'UTF-8' );
		return $char_count * $font_size * $avg;
	}

	/**
	 * Return the PDF resource name for a font ('F1', 'F2', …).
	 *
	 * @param string $font_name Logical font name.
	 * @return string
	 */
	private function font_resource_name( $font_name ) {
		$fonts = array_keys( $this->font_obj_ids );
		$idx   = array_search( $font_name, $fonts, true );
		return 'F' . ( false === $idx ? 1 : $idx + 1 );
	}

	// ----------------------------------------------------------------
	// Image registration
	// ----------------------------------------------------------------

	/**
	 * Register a logo image (JPEG or PNG) for use in the PDF.
	 *
	 * @param string $url Image URL.
	 * @return string Image key, or empty string on failure.
	 */
	private function register_image( $url ) {
		$local = $this->url_to_path( $url );
		if ( empty( $local ) || ! file_exists( $local ) ) {
			return '';
		}
		$ext = strtolower( pathinfo( $local, PATHINFO_EXTENSION ) );
		if ( 'jpg' === $ext || 'jpeg' === $ext ) {
			return $this->register_jpeg( $local );
		}
		if ( 'png' === $ext ) {
			return $this->register_png( $local );
		}
		return '';
	}

	private function register_jpeg( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = @file_get_contents( $path );
		if ( false === $data ) {
			return '';
		}
		$size = @getimagesize( $path );
		if ( ! $size ) {
			return '';
		}
		$key = (string) ( count( $this->image_obj_ids ) + 1 );
		$this->image_obj_ids[ $key ] = array(
		'type'   => 'jpeg',
		'data'   => $data,
		'w'      => $size[0],
		'h'      => $size[1],
		'obj_id' => 0,
		);
		return $key;
	}

	private function register_png( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = @file_get_contents( $path );
		if ( false === $data ) {
			return '';
		}
		$size = @getimagesize( $path );
		if ( ! $size ) {
			return '';
		}
		$key = (string) ( count( $this->image_obj_ids ) + 1 );
		$this->image_obj_ids[ $key ] = array(
		'type'    => 'png',
		'data'    => $data,
		'w'       => $size[0],
		'h'       => $size[1],
		'obj_id'  => 0,
		);
		return $key;
	}

	/** Convert an absolute URL to a local file path. */
	private function url_to_path( $url ) {
		$upload_dir = wp_upload_dir();
		if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
			return str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
		}
		$site_url = site_url( '/' );
		if ( strpos( $url, $site_url ) === 0 ) {
			return ABSPATH . ltrim( str_replace( $site_url, '', $url ), '/' );
		}
		return false;
	}

	// ----------------------------------------------------------------
	// Page management
	// ----------------------------------------------------------------

	private function begin_page() {
		$this->stream = '';
	}

	private function end_page() {
		$this->page_streams[] = $this->stream;
	}

	// ----------------------------------------------------------------
	// PDF compilation
	// ----------------------------------------------------------------

	/**
	 * Compile all page streams, fonts, and images into a valid PDF binary.
	 *
	 * Object allocation:
	 *   1        – Catalog
	 *   2        – Pages
	 *   3..      – Page objects
	 *   then     – For each font:
	 *                TTF  → 4 objects (Type0, CIDFont, FontDescriptor, FontFile2)
	 *                Type1→ 1 object
	 *   then     – Image XObjects (1 each)
	 *   then     – Page content streams (1 each)
	 *
	 * @return string Raw PDF binary.
	 */
	private function compile_pdf() {
		$out      = $this->buf;
		$n_pages  = count( $this->page_streams );
		$n_images = count( $this->image_obj_ids );

		// ---- Assign object IDs ----
		$catalog_id   = 1;
		$pages_id     = 2;
		$page_base_id = 3;

		$font_names     = array_keys( $this->font_obj_ids );
		$current_id     = $page_base_id + $n_pages;

		foreach ( $font_names as $fname ) {
			$this->font_obj_ids[ $fname ] = $current_id;
			$current_id += isset( $this->ttf_map[ $fname ] ) ? 4 : 1;
		}

		$image_base_id  = $current_id;
		$stream_base_id = $image_base_id + $n_images;
		$total_objs     = $stream_base_id + $n_pages;

		$image_keys = array_keys( $this->image_obj_ids );
		foreach ( $image_keys as $i => $key ) {
			$this->image_obj_ids[ $key ]['obj_id'] = $image_base_id + $i;
		}

		$offsets = array();

		// ---- Catalog ----
		$offsets[ $catalog_id ] = strlen( $out );
		$out .= "$catalog_id 0 obj\n<< /Type /Catalog /Pages $pages_id 0 R >>\nendobj\n";

		// ---- Pages ----
		$page_refs = '';
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$page_refs .= ( $page_base_id + $i ) . ' 0 R ';
		}
		$offsets[ $pages_id ] = strlen( $out );
		$out .= "$pages_id 0 obj\n<< /Type /Pages /Kids [$page_refs] /Count $n_pages >>\nendobj\n";

		// ---- Page objects ----
		$font_res = '';
		foreach ( $font_names as $fi => $fname ) {
			$font_res .= '/F' . ( $fi + 1 ) . ' ' . $this->font_obj_ids[ $fname ] . ' 0 R ';
		}

		$image_res = '';
		foreach ( $image_keys as $ii => $ikey ) {
			$image_res .= '/Im' . $ikey . ' ' . $this->image_obj_ids[ $ikey ]['obj_id'] . ' 0 R ';
		}

		for ( $i = 0; $i < $n_pages; $i++ ) {
			$page_id   = $page_base_id + $i;
			$stream_id = $stream_base_id + $i;

			$res = '<< /Font << ' . $font_res . '>> /ProcSet [/PDF /Text /ImageC /ImageB /ImageI]';
			if ( $image_res ) {
				$res .= ' /XObject << ' . $image_res . '>>';
			}
			$res .= ' >>';

			$offsets[ $page_id ] = strlen( $out );
			$out .= sprintf(
			"%d 0 obj\n<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources %s >>\nendobj\n",
			$page_id, $pages_id, $this->pw_pt, $this->ph_pt, $stream_id, $res
			);
		}

		// ---- Font objects ----
		foreach ( $font_names as $fname ) {
			$fid = $this->font_obj_ids[ $fname ];

			if ( isset( $this->ttf_map[ $fname ] ) ) {
				// --- Type0 / CIDFontType2 (4 objects) ---
				$ttf          = $this->ttf_map[ $fname ];
				$cidfont_id   = $fid + 1;
				$fontdesc_id  = $fid + 2;
				$fontfile_id  = $fid + 3;

				$safe_name = preg_replace(
				'/[^A-Za-z0-9\-]/',
				'',
				pathinfo( $ttf->file_path, PATHINFO_FILENAME )
				);

				// Width array: all glyph widths scaled to 1000-unit text space.
				$scale   = ( $ttf->units_per_em > 0 ) ? 1000.0 / $ttf->units_per_em : 1.0;
				$w_parts = array();
				for ( $gid = 0; $gid < $ttf->num_glyphs; $gid++ ) {
					$w_parts[] = (int) round(
					( isset( $ttf->widths[ $gid ] ) ? $ttf->widths[ $gid ] : 0 ) * $scale
					);
				}
				$w_array = '0 [' . implode( ' ', $w_parts ) . ']';

				// Scaled font metrics.
				$ascent  = (int) round( $ttf->ascent      * $scale );
				$descent = (int) round( $ttf->descent     * $scale );
				$cap_h   = (int) round( $ttf->cap_height  * $scale );
				$bbox    = array_map(
				static function ( $v ) use ( $scale ) { return (int) round( $v * $scale ); },
				$ttf->bbox
				);
				$bbox_str = implode( ' ', $bbox );
				$ital     = (int) round( $ttf->italic_angle );

				// Type0 (top-level composite font).
				$offsets[ $fid ] = strlen( $out );
				$out .= sprintf(
				"%d 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /%s\n"
				. "/Encoding /Identity-H /DescendantFonts [%d 0 R] >>\nendobj\n",
				$fid, $safe_name, $cidfont_id
				);

				// CIDFont.
				$offsets[ $cidfont_id ] = strlen( $out );
				$out .= sprintf(
				"%d 0 obj\n<< /Type /Font /Subtype /CIDFontType2\n"
				. "/BaseFont /%s\n"
				. "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>\n"
				. "/FontDescriptor %d 0 R\n"
				. "/DW 0\n/W [%s]\n>>\nendobj\n",
				$cidfont_id, $safe_name, $fontdesc_id, $w_array
				);

				// FontDescriptor.
				$offsets[ $fontdesc_id ] = strlen( $out );
				$out .= sprintf(
				"%d 0 obj\n<< /Type /FontDescriptor\n"
				. "/FontName /%s\n/Flags %d\n/FontBBox [%s]\n"
				. "/ItalicAngle %d\n/Ascent %d\n/Descent %d\n"
				. "/CapHeight %d\n/StemV 80\n/FontFile2 %d 0 R\n>>\nendobj\n",
				$fontdesc_id, $safe_name, $ttf->flags, $bbox_str,
				$ital, $ascent, $descent, $cap_h, $fontfile_id
				);

				// Font stream (raw TTF binary).
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$font_data = file_get_contents( $ttf->file_path );
				$font_len  = strlen( $font_data );
				$offsets[ $fontfile_id ] = strlen( $out );
				$out .= sprintf(
				"%d 0 obj\n<< /Length %d /Length1 %d >>\nstream\n",
				$fontfile_id, $font_len, $font_len
				);
				$out .= $font_data;
				$out .= "\nendstream\nendobj\n";

				} else {
				// --- Fallback: Type1 built-in ---
				$offsets[ $fid ] = strlen( $out );
				$out .= sprintf(
				"%d 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>\nendobj\n",
				$fid, $fname
				);
			}
		}

		// ---- Image XObjects ----
		foreach ( $image_keys as $ikey ) {
			$img     = $this->image_obj_ids[ $ikey ];
			$iobj_id = $img['obj_id'];

			if ( 'jpeg' === $img['type'] ) {
				$raw     = $img['data'];
				$raw_len = strlen( $raw );
				$offsets[ $iobj_id ] = strlen( $out );
				$out .= sprintf(
				"%d 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d"
				. " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n",
				$iobj_id, $img['w'], $img['h'], $raw_len
				);
				$out .= $raw;
				$out .= "\nendstream\nendobj\n";

				} elseif ( 'png' === $img['type'] ) {
				$img_data = $this->png_to_rgb_stream( $img );
				if ( null !== $img_data ) {
					$raw     = $img_data['data'];
					$raw_len = strlen( $raw );
					$offsets[ $iobj_id ] = strlen( $out );
					$out .= sprintf(
					"%d 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d"
					. " /ColorSpace /%s /BitsPerComponent %d /Length %d >>\nstream\n",
					$iobj_id, $img['w'], $img['h'], $img_data['cs'], $img_data['bpc'], $raw_len
					);
					$out .= $raw;
					$out .= "\nendstream\nendobj\n";
					} else {
					// Dummy 1×1 white pixel fallback.
					$dummy = "\xff\xff\xff";
					$offsets[ $iobj_id ] = strlen( $out );
					$out .= sprintf(
					"%d 0 obj\n<< /Type /XObject /Subtype /Image /Width 1 /Height 1"
					. " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length 3 >>\nstream\n%s\nendstream\nendobj\n",
					$iobj_id, $dummy
					);
				}
			}
		}

		// ---- Content streams ----
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$stream_id  = $stream_base_id + $i;
			$stream_raw = $this->page_streams[ $i ];
			$stream_len = strlen( $stream_raw );

			$offsets[ $stream_id ] = strlen( $out );
			$out .= sprintf(
			"%d 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n",
			$stream_id, $stream_len, $stream_raw
			);
		}

		// ---- Cross-reference table ----
		$xref_offset = strlen( $out );
		$out .= "xref\n0 $total_objs\n";
		$out .= "0000000000 65535 f \n";
		for ( $oid = 1; $oid < $total_objs; $oid++ ) {
			if ( isset( $offsets[ $oid ] ) ) {
				$out .= sprintf( "%010d 00000 n \n", $offsets[ $oid ] );
				} else {
				$out .= "0000000000 65535 f \n";
			}
		}

		// ---- Trailer ----
		$out .= sprintf(
		"trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
		$total_objs, $catalog_id, $xref_offset
		);

		return $out;
	}

	// ----------------------------------------------------------------
	// PNG helper
	// ----------------------------------------------------------------

	/**
	 * Convert a PNG image to a flat uncompressed RGB stream via GD.
	 *
	 * @param array $img Image info.
	 * @return array|null  { data, cs, bpc } or null on failure.
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
		$gd   = @imagecreatefrompng( $path );
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
				$raw  .= chr( ( $color >> 16 ) & 0xFF )
				. chr( ( $color >>  8 ) & 0xFF )
				. chr(   $color         & 0xFF );
			}
		}
		imagedestroy( $gd );

		return array( 'data' => $raw, 'cs' => 'DeviceRGB', 'bpc' => 8 );
	}

	// ----------------------------------------------------------------
	// Coordinate helpers
	// ----------------------------------------------------------------

	private function mm( $mm ) {
		return $mm * self::PT_PER_MM;
	}

	private function y_flip( $y_mm ) {
		return $this->ph_pt - ( $y_mm * self::PT_PER_MM );
	}
}
