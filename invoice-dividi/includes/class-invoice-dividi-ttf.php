<?php
/**
 * Minimal TrueType font parser for Invoice Dividi.
 *
 * Reads only the tables needed to embed a TrueType font as a
 * Type0 / CIDFontType2 object inside a hand-crafted PDF:
 *
 *  - head  → units-per-em, font bounding box
 *  - hhea  → ascender / descender / numberOfHMetrics
 *  - OS/2  → cap height, PDF /Flags
 *  - maxp  → total glyph count
 *  - hmtx  → advance width per glyph
 *  - cmap  → Unicode code-point → glyph-ID map (format 4, BMP)
 *  - post  → italic angle
 *
 * Public API:
 *   encode_for_pdf( $utf8 )          – UTF-8 → PDF hex string <NNNN…>
 *   string_width_pt( $utf8, $size )  – advance width in points
 *
 * @package Invoice_Dividi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invoice_Dividi_TTF
 */
class Invoice_Dividi_TTF {

	// ----------------------------------------------------------------
	// Public properties
	// ----------------------------------------------------------------

	/** @var string Absolute path to the TTF file. */
	public $file_path = '';

	/** @var int Design units per em. */
	public $units_per_em = 1000;

	/** @var int Ascender in design units. */
	public $ascent = 800;

	/** @var int Descender in design units (negative). */
	public $descent = -200;

	/** @var int Cap height in design units. */
	public $cap_height = 700;

	/** @var float Italic angle in degrees. */
	public $italic_angle = 0.0;

	/** @var int PDF /Flags bitmask. */
	public $flags = 32; // Non-symbolic.

	/** @var int[] Font bounding box [xMin, yMin, xMax, yMax] (design units). */
	public $bbox = array( -200, -200, 1200, 900 );

	/** @var int Total number of glyphs. */
	public $num_glyphs = 0;

	/** @var int[] Advance widths indexed by glyph ID (design units). */
	public $widths = array();

	/** @var int[] Unicode code-point → glyph ID. */
	public $cmap = array();

	// ----------------------------------------------------------------
	// Private state
	// ----------------------------------------------------------------

	/** @var string Raw TTF binary. */
	private $data = '';

	/** @var int[] Table tag → byte offset within $data. */
	private $tables = array();

	/** @var int Advance width shared by glyphs beyond numberOfHMetrics. */
	private $last_adv_width = 1000;

	/** @var int numberOfHMetrics from the hhea table. */
	private $num_h_metrics = 0;

	// ----------------------------------------------------------------
	// Constructor
	// ----------------------------------------------------------------

	/**
	 * Load and parse a TTF font file.
	 *
	 * @param string $path Absolute file path.
	 */
	public function __construct( $path ) {
		$this->file_path = $path;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return;
		}
		$this->data = $raw;

		$this->parse_tables();
		$this->parse_head();
		$this->parse_hhea();
		$this->parse_os2();
		$this->parse_maxp();
		$this->parse_hmtx();
		$this->parse_cmap();
		$this->parse_post();
	}

	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Encode a UTF-8 string as a PDF hex string using glyph IDs.
	 *
	 * With /Identity-H encoding each character is represented by its
	 * two-byte (big-endian) glyph ID, written as a hex string.
	 * Unmapped code points use glyph 0 (.notdef).
	 *
	 * @param  string $utf8 Source text.
	 * @return string PDF hex string, e.g. '<00410042>'.
	 */
	public function encode_for_pdf( $utf8 ) {
		$ids = $this->utf8_to_glyph_ids( $utf8 );
		$hex = '';
		foreach ( $ids as $id ) {
			$hex .= sprintf( '%04X', $id );
		}
		return '<' . $hex . '>';
	}

	/**
	 * Calculate the advance width of a UTF-8 string in PDF points.
	 *
	 * @param  string $utf8      Source text.
	 * @param  float  $font_size Font size in points.
	 * @return float  Width in points.
	 */
	public function string_width_pt( $utf8, $font_size ) {
		$ids   = $this->utf8_to_glyph_ids( $utf8 );
		$total = 0;
		foreach ( $ids as $id ) {
			$total += isset( $this->widths[ $id ] ) ? $this->widths[ $id ] : $this->last_adv_width;
		}
		return ( $this->units_per_em > 0 )
			? $total * $font_size / $this->units_per_em
			: 0.0;
	}

	// ----------------------------------------------------------------
	// UTF-8 → glyph ID mapping
	// ----------------------------------------------------------------

	/**
	 * Convert a UTF-8 string to an array of glyph IDs.
	 *
	 * @param  string $utf8 Source text.
	 * @return int[]
	 */
	private function utf8_to_glyph_ids( $utf8 ) {
		$ids = array();
		$len = strlen( $utf8 );
		$i   = 0;

		while ( $i < $len ) {
			$b = ord( $utf8[ $i ] );

			if ( $b < 0x80 ) {
				$cp = $b;
				++$i;
			} elseif ( $b < 0xC0 ) {
				// Stray continuation byte – skip.
				++$i;
				continue;
			} elseif ( $b < 0xE0 ) {
				if ( $i + 1 >= $len ) {
					break;
				}
				$cp = ( ( $b & 0x1F ) << 6 )
				    | ( ord( $utf8[ $i + 1 ] ) & 0x3F );
				$i += 2;
			} elseif ( $b < 0xF0 ) {
				if ( $i + 2 >= $len ) {
					break;
				}
				$cp = ( ( $b & 0x0F ) << 12 )
				    | ( ( ord( $utf8[ $i + 1 ] ) & 0x3F ) << 6 )
				    |   ( ord( $utf8[ $i + 2 ] ) & 0x3F );
				$i += 3;
			} else {
				if ( $i + 3 >= $len ) {
					break;
				}
				$cp = ( ( $b & 0x07 ) << 18 )
				    | ( ( ord( $utf8[ $i + 1 ] ) & 0x3F ) << 12 )
				    | ( ( ord( $utf8[ $i + 2 ] ) & 0x3F ) << 6 )
				    |   ( ord( $utf8[ $i + 3 ] ) & 0x3F );
				$i += 4;
			}

			$ids[] = isset( $this->cmap[ $cp ] ) ? $this->cmap[ $cp ] : 0;
		}

		return $ids;
	}

	// ----------------------------------------------------------------
	// TTF table parsers
	// ----------------------------------------------------------------

	private function parse_tables() {
		$num = $this->uint16( 4 );
		for ( $i = 0; $i < $num; $i++ ) {
			$base                  = 12 + $i * 16;
			$tag                   = substr( $this->data, $base, 4 );
			$offset                = $this->uint32( $base + 8 );
			$this->tables[ $tag ] = $offset;
		}
	}

	private function parse_head() {
		if ( ! isset( $this->tables['head'] ) ) {
			return;
		}
		$o                  = $this->tables['head'];
		$this->units_per_em = $this->uint16( $o + 18 );
		$this->bbox         = array(
			$this->int16( $o + 36 ),
			$this->int16( $o + 38 ),
			$this->int16( $o + 40 ),
			$this->int16( $o + 42 ),
		);
	}

	private function parse_hhea() {
		if ( ! isset( $this->tables['hhea'] ) ) {
			return;
		}
		$o                   = $this->tables['hhea'];
		$this->ascent        = $this->int16( $o + 4 );
		$this->descent       = $this->int16( $o + 6 );
		$this->num_h_metrics = $this->uint16( $o + 34 );
	}

	private function parse_os2() {
		if ( ! isset( $this->tables['OS/2'] ) ) {
			return;
		}
		$o       = $this->tables['OS/2'];
		$version = $this->uint16( $o );

		if ( $version >= 2 ) {
			$cap = $this->int16( $o + 88 );
			if ( $cap > 0 ) {
				$this->cap_height = $cap;
			}
		}

		$fs_sel       = $this->uint16( $o + 62 );
		$this->flags  = 32; // Non-symbolic.
		if ( $fs_sel & 1 ) {
			$this->flags |= 64; // Italic.
		}
	}

	private function parse_maxp() {
		if ( ! isset( $this->tables['maxp'] ) ) {
			return;
		}
		$this->num_glyphs = $this->uint16( $this->tables['maxp'] + 4 );
	}

	private function parse_hmtx() {
		if ( ! isset( $this->tables['hmtx'] ) ) {
			return;
		}
		$o          = $this->tables['hmtx'];
		$n_metrics  = $this->num_h_metrics > 0 ? $this->num_h_metrics : $this->num_glyphs;
		$last_width = 1000;

		for ( $i = 0; $i < $n_metrics; $i++ ) {
			$last_width          = $this->uint16( $o + $i * 4 );
			$this->widths[ $i ] = $last_width;
		}
		// Remaining glyphs repeat the last advance width.
		for ( $i = $n_metrics; $i < $this->num_glyphs; $i++ ) {
			$this->widths[ $i ] = $last_width;
		}
		$this->last_adv_width = $last_width;
	}

	private function parse_cmap() {
		if ( ! isset( $this->tables['cmap'] ) ) {
			return;
		}
		$base     = $this->tables['cmap'];
		$num_sub  = $this->uint16( $base + 2 );
		$chosen   = null;
		$priority = 0;

		for ( $i = 0; $i < $num_sub; $i++ ) {
			$entry    = $base + 4 + $i * 8;
			$platform = $this->uint16( $entry );
			$encoding = $this->uint16( $entry + 2 );
			$offset   = $this->uint32( $entry + 4 );
			$fmt      = $this->uint16( $base + $offset );

			if ( 4 !== $fmt ) {
				continue; // Only handle cmap format 4.
			}

			// Prefer Windows Unicode BMP (3,1), then Unicode (0,3), then any.
			if ( 3 === $platform && 1 === $encoding ) {
				$p = 3;
			} elseif ( 0 === $platform && 3 === $encoding ) {
				$p = 2;
			} elseif ( 0 === $platform ) {
				$p = 1;
			} else {
				$p = 0;
			}

			if ( $p >= $priority ) {
				$priority = $p;
				$chosen   = $base + $offset;
			}
		}

		if ( null !== $chosen ) {
			$this->parse_cmap4( $chosen );
		}
	}

	/**
	 * Parse a cmap subtable in format 4.
	 *
	 * @param int $base Byte offset of the subtable start within $this->data.
	 */
	private function parse_cmap4( $base ) {
		$seg_count  = $this->uint16( $base + 6 ) >> 1;
		$end_off    = $base + 14;
		$start_off  = $base + 14 + $seg_count * 2 + 2; // +2 for reservedPad
		$delta_off  = $start_off + $seg_count * 2;
		$range_off  = $delta_off  + $seg_count * 2;

		for ( $seg = 0; $seg < $seg_count; $seg++ ) {
			$end   = $this->uint16( $end_off   + $seg * 2 );
			$start = $this->uint16( $start_off + $seg * 2 );
			$delta = $this->int16(  $delta_off + $seg * 2 );
			$range = $this->uint16( $range_off + $seg * 2 );

			if ( 0xFFFF === $end ) {
				break; // Sentinel.
			}

			for ( $c = $start; $c <= $end; $c++ ) {
				if ( 0 === $range ) {
					$gid = ( $c + $delta ) & 0xFFFF;
				} else {
					// range offset is relative to the position of that entry in
					// the idRangeOffset array.
					$addr = $range_off + $seg * 2 + $range + ( $c - $start ) * 2;
					$gid  = $this->uint16( $addr );
					if ( 0 !== $gid ) {
						$gid = ( $gid + $delta ) & 0xFFFF;
					}
				}
				if ( 0 !== $gid ) {
					$this->cmap[ $c ] = $gid;
				}
			}
		}
	}

	private function parse_post() {
		if ( ! isset( $this->tables['post'] ) ) {
			return;
		}
		$o                  = $this->tables['post'];
		$int_part           = $this->int16( $o + 4 );
		$frac_part          = $this->uint16( $o + 6 ) / 65536.0;
		$this->italic_angle = (float) $int_part + $frac_part;
	}

	// ----------------------------------------------------------------
	// Binary read helpers
	// ----------------------------------------------------------------

	private function uint16( $offset ) {
		return ( ord( $this->data[ $offset ] ) << 8 )
		     |   ord( $this->data[ $offset + 1 ] );
	}

	private function uint32( $offset ) {
		return ( ( ord( $this->data[ $offset ] )     << 24 )
		       | ( ord( $this->data[ $offset + 1 ] ) << 16 )
		       | ( ord( $this->data[ $offset + 2 ] ) << 8  )
		       |   ord( $this->data[ $offset + 3 ] ) ) & 0xFFFFFFFF;
	}

	private function int16( $offset ) {
		$v = $this->uint16( $offset );
		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}
}
