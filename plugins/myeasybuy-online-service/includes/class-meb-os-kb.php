<?php
/**
 * Build and cache a bilingual policy-clause index from live WP pages.
 *
 * @package MyEasyBuy_Online_Service
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge-base builder.
 */
class MEB_OS_KB {

	public const OPTION_KEY   = 'myeasybuy_os_kb';
	public const OPTION_STAMP = 'myeasybuy_os_kb_stamp';

	/**
	 * Policy slugs (same on local and production; IDs differ).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function policies(): array {
		return array(
			array(
				'slug'     => 'return-refund-policy',
				'title_en' => 'Return & Refund Policy',
				'title_zh' => '退货退款政策',
			),
			array(
				'slug'     => 'privacy-policy',
				'title_en' => 'Privacy Policy',
				'title_zh' => '隐私政策',
			),
			array(
				'slug'     => 'terms-of-service',
				'title_en' => 'Terms of Service',
				'title_zh' => '服务条款',
			),
			array(
				'slug'     => 'shipping-policy',
				'title_en' => 'Shipping & Prohibited Items Policy',
				'title_zh' => '运输与禁运政策',
			),
			array(
				'slug'     => 'storage-inspection-policy',
				'title_en' => 'Storage & Inspection Policy',
				'title_zh' => '仓储与验货政策',
			),
			array(
				'slug'     => 'compensation-policy',
				'title_en' => 'Compensation & Parcel Protection Policy',
				'title_zh' => '赔偿与包裹保障',
			),
		);
	}

	/**
	 * Get the index, rebuilding if policy pages changed.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$packaged = self::packaged();
		$stamp    = self::pages_stamp();
		$stored   = get_option( self::OPTION_KEY, null );
		$old      = (string) get_option( self::OPTION_STAMP, '' );
		if ( is_array( $stored ) && $old === $stamp && ! empty( $stored['clauses'] ) ) {
			return $stored;
		}
		$built = self::build_from_posts();
		if ( ! empty( $built['clauses'] ) ) {
			update_option( self::OPTION_KEY, $built, false );
			update_option( self::OPTION_STAMP, $stamp, false );
			return $built;
		}
		return $packaged ? $packaged : array(
			'version'        => 'empty',
			'contact_email'  => MEB_OS_CONTACT_EMAIL,
			'policies'       => array(),
			'clauses'        => array(),
			'faqs'           => array(),
		);
	}

	/**
	 * Rebuild when a policy page is saved.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_rebuild( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}
		$slugs = array_column( self::policies(), 'slug' );
		if ( ! in_array( $post->post_name, $slugs, true ) ) {
			return;
		}
		delete_option( self::OPTION_STAMP );
		self::get();
	}

	/**
	 * Packaged JSON shipped with the plugin (from a production snapshot).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function packaged(): ?array {
		$file = MEB_OS_DIR . 'data/kb.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Stamp of live policy pages.
	 */
	private static function pages_stamp(): string {
		$parts = array();
		foreach ( self::policies() as $meta ) {
			$page = get_page_by_path( $meta['slug'] );
			if ( $page instanceof WP_Post ) {
				$parts[] = $page->ID . ':' . $page->post_modified_gmt;
			} else {
				$parts[] = $meta['slug'] . ':missing';
			}
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Parse all published policy pages.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_from_posts(): array {
		$policies = array();
		$clauses  = array();
		$modified = array();
		foreach ( self::policies() as $meta ) {
			$page = get_page_by_path( $meta['slug'] );
			if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
				continue;
			}
			$url        = get_permalink( $page );
			$modified[] = $page->post_modified_gmt;
			$policies[] = array(
				'slug'     => $meta['slug'],
				'title_en' => $meta['title_en'],
				'title_zh' => $meta['title_zh'],
				'url'      => $url,
				'modified' => $page->post_modified_gmt,
			);
			foreach ( self::pair_sections( (string) $page->post_content ) as $sec ) {
				$en_parts = self::extract_tables( $sec['html_en'] );
				$zh_parts = self::extract_tables( $sec['html_zh'] );
				$text_en  = self::html_to_text( $en_parts['html'] );
				$text_zh  = self::html_to_text( $zh_parts['html'] );
				if ( '' === $text_en && '' === $text_zh ) {
					continue;
				}
				$num = $sec['num'];
				$anc = 'meb-os-' . $meta['slug'] . '-' . str_replace( '.', '-', $num );
				$clauses[] = array(
					'id'        => $meta['slug'] . ':' . $num,
					'slug'      => $meta['slug'],
					'num'       => $num,
					'anchor'    => $anc,
					'url'       => trailingslashit( $url ) . '#' . $anc,
					'title_en'  => $sec['title_en'],
					'title_zh'  => $sec['title_zh'],
					'text_en'   => $text_en,
					'text_zh'   => $text_zh,
					'tables_en' => $en_parts['tables'],
					'tables_zh' => $zh_parts['tables'],
				);
			}
		}
		$packaged = self::packaged();
		return array(
			'version'       => $modified ? max( $modified ) : ( $packaged['version'] ?? '1.0' ),
			'source'        => 'wordpress',
			'contact_email' => MEB_OS_CONTACT_EMAIL,
			'policies'      => $policies,
			'clauses'       => $clauses,
			'faqs'          => $packaged['faqs'] ?? array(),
		);
	}

	/**
	 * Split English / Chinese halves and pair by section number.
	 *
	 * @param string $html Content.
	 * @return array<int, array<string, string>>
	 */
	public static function pair_sections( string $html ): array {
		if ( preg_match( '/<h2[^>]*>\s*中文版[:：]/u', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$en_html = substr( $html, 0, (int) $m[0][1] );
			$rest    = substr( $html, (int) $m[0][1] );
			$rest    = preg_replace( '/^<h2[^>]*>.*?<\/h2>/us', '', $rest, 1 );
			$zh_html = (string) $rest;
		} else {
			$en_html = $html;
			$zh_html = '';
		}
		$en = self::split_sections( $en_html );
		$zh = self::split_sections( $zh_html );
		$zh_by = array();
		$zh_intro = array( 'title' => '', 'html' => '' );
		foreach ( $zh as $sec ) {
			if ( $sec['num'] ) {
				$zh_by[ $sec['num'] ] = $sec;
			} else {
				$zh_intro = $sec;
			}
		}
		$out = array();
		$used = array();
		foreach ( $en as $sec ) {
			$num = $sec['num'] ? $sec['num'] : 'intro';
			$z   = ( 'intro' === $num ) ? $zh_intro : ( $zh_by[ $sec['num'] ] ?? array( 'title' => '', 'html' => '' ) );
			if ( $sec['num'] ) {
				$used[ $sec['num'] ] = true;
			}
			$out[] = array(
				'num'      => $num,
				'title_en' => $sec['title'] ?: ( 'intro' === $num ? 'Introduction' : '' ),
				'title_zh' => ( ! empty( $z['title'] ) && 'Introduction' !== $z['title'] ) ? $z['title'] : ( 'intro' === $num ? '简介' : '' ),
				'html_en'  => $sec['html'],
				'html_zh'  => $z['html'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * @param string $html HTML.
	 * @return array<int, array<string, string>>
	 */
	public static function split_sections( string $html ): array {
		if ( ! preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>/us', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$text = trim( $html );
			return $text ? array( array( 'num' => 'intro', 'title' => 'Introduction', 'html' => $html ) ) : array();
		}
		$sections = array();
		$used     = array( 'intro' => true );
		$first    = $matches[0][0][1];
		$intro    = substr( $html, 0, $first );
		if ( '' !== self::html_to_text( $intro ) ) {
			$sections[] = array( 'num' => 'intro', 'title' => 'Introduction', 'html' => $intro );
		}
		$count = count( $matches );
		for ( $i = 0; $i < $count; $i++ ) {
			$title = self::html_to_text( $matches[ $i ][2][0] );
			$start = $matches[ $i ][0][1] + strlen( $matches[ $i ][0][0] );
			$end   = ( $i + 1 < $count ) ? $matches[ $i + 1 ][0][1] : strlen( $html );
			$num   = self::section_num( $title, $used );
			$sections[] = array(
				'num'   => $num,
				'title' => $title,
				'html'  => substr( $html, $start, $end - $start ),
			);
		}
		return $sections;
	}



	/**
	 * Stable section key: leading number when present, otherwise a unique slug of the title.
	 * Unnumbered headings must not all collapse to "intro" (duplicate clause IDs and anchors).
	 *
	 * @param string             $title Heading text.
	 * @param array<string,bool> $used  Keys already taken (updated in place).
	 */
	public static function section_num( string $title, array &$used ): string {
		if ( preg_match( '/^\s*(\d+(?:\.\d+)*)\b/u', $title, $nm ) ) {
			$base = $nm[1];
		} else {
			$base = sanitize_title( $title );
			if ( '' === $base ) {
				$base = 'section';
			}
		}
		$num = $base;
		$i   = 2;
		while ( isset( $used[ $num ] ) ) {
			$num = $base . '-' . $i;
			++$i;
		}
		$used[ $num ] = true;
		return $num;
	}

	/**
	 * Parse HTML tables into {headers, rows} and replace them with "cell — cell" lines.
	 *
	 * @param string $html HTML.
	 * @return array{html:string,tables:array<int,array<string,mixed>>}
	 */
	public static function extract_tables( string $html ): array {
		$tables = array();
		if ( '' === $html || false === stripos( $html, '<table' ) ) {
			return array( 'html' => $html, 'tables' => $tables );
		}
		$out = preg_replace_callback(
			'/<table\b[^>]*>.*?<\/table>/is',
			static function ( $m ) use ( &$tables ) {
				$raw     = $m[0];
				$headers = array();
				$rows    = array();
				if ( preg_match( '/<thead\b[^>]*>(.*?)<\/thead>/is', $raw, $thead ) ) {
					if ( preg_match( '/<tr\b[^>]*>(.*?)<\/tr>/is', $thead[1], $tr ) ) {
						$headers = self::table_cells( $tr[1] );
					}
					$raw_body = preg_replace( '/<thead\b[^>]*>.*?<\/thead>/is', '', $raw );
				} else {
					$raw_body = $raw;
				}
				if ( preg_match_all( '/<tr\b[^>]*>(.*?)<\/tr>/is', $raw_body, $trs ) ) {
					foreach ( $trs[1] as $tr_html ) {
						$cells = self::table_cells( $tr_html );
						if ( ! $cells ) {
							continue;
						}
						if ( ! $headers && preg_match( '/<th\b/i', $tr_html ) ) {
							$headers = $cells;
							continue;
						}
						if ( $headers && $cells === $headers ) {
							continue;
						}
						$rows[] = $cells;
					}
				}
				if ( ! $headers && ! $rows ) {
					return $m[0];
				}
				$tables[] = array( 'headers' => $headers, 'rows' => $rows );
				$lines    = array();
				if ( $headers ) {
					$lines[] = implode( ' — ', $headers );
				}
				foreach ( $rows as $row ) {
					$lines[] = implode( ' — ', $row );
				}
				return "\n" . implode( "\n", $lines ) . "\n";
			},
			$html
		);
		return array( 'html' => (string) $out, 'tables' => $tables );
	}

	/**
	 * @param string $row_html Row inner HTML.
	 * @return array<int, string>
	 */
	private static function table_cells( string $row_html ): array {
		if ( ! preg_match_all( '/<t[hd]\b[^>]*>(.*?)<\/t[hd]>/is', $row_html, $m ) ) {
			return array();
		}
		$cells = array();
		foreach ( $m[1] as $cell ) {
			$cells[] = self::html_to_text( $cell );
		}
		return $cells;
	}

	/**
	 * @param string $html HTML.
	 */
	public static function html_to_text( string $html ): string {
		$t = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$t = preg_replace( '/<\/(p|div|h[1-6]|li|tr)>/i', "\n", (string) $t );
		$t = preg_replace( '/<li[^>]*>/i', '• ', (string) $t );
		$t = wp_strip_all_tags( (string) $t );
		$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( "/[ \t]+\n/", "\n", $t );
		$t = preg_replace( "/[ \t]{2,}/", ' ', (string) $t );
		$t = preg_replace( "/\n{3,}/", "\n\n", (string) $t );
		return trim( (string) $t );
	}

	/**
	 * Inject stable anchors into policy headings on the front end.
	 *
	 * @param string $content Content.
	 */
	public static function add_heading_anchors( $content ) {
		if ( ! is_singular( 'page' ) ) {
			return $content;
		}
		$slug  = get_post_field( 'post_name', get_queried_object_id() );
		$slugs = array_column( self::policies(), 'slug' );
		if ( ! in_array( $slug, $slugs, true ) ) {
			return $content;
		}
		$used = array( 'intro' => true );
		return preg_replace_callback(
			'/<h([23])([^>]*)>(.*?)<\/h\1>/us',
			static function ( $m ) use ( $slug, &$used ) {
				$title = self::html_to_text( $m[3] );
				if ( preg_match( '/中文版[:：]/u', $title ) ) {
					return $m[0];
				}
				$num  = self::section_num( $title, $used );
				$id   = 'meb-os-' . $slug . '-' . str_replace( '.', '-', $num );
				$attr = $m[2];
				if ( preg_match( '/\sid=/i', $attr ) ) {
					return $m[0];
				}
				return '<h' . $m[1] . $attr . ' id="' . esc_attr( $id ) . '">' . $m[3] . '</h' . $m[1] . '>';
			},
			$content
		);
	}
}
