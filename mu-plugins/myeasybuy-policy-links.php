<?php
/**
 * Plugin Name: MyEasyBuy Policy Links
 * Description: Points registration agreement, statement, and footer policy links at the published policy pages. Uses permalinks by slug (and production IDs only when the slug matches), so it works on local and production.
 * Version:     1.0.0
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permalink for a published policy page, looked up by slug.
 *
 * Optional known IDs (production) are used only when that post is a published
 * page whose slug matches, so a different local ID cannot be used by mistake.
 *
 * @param string $slug     Page slug.
 * @param int    $known_id Optional production page ID.
 * @return string URL, or empty if the page is not published on this site.
 */
function meb_policy_page_url( string $slug, int $known_id = 0 ): string {
	$page = get_page_by_path( $slug );
	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		$url = get_permalink( $page );
		return $url ? (string) $url : '';
	}

	if ( $known_id > 0 ) {
		$post = get_post( $known_id );
		if ( $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status && $slug === $post->post_name ) {
			$url = get_permalink( $post );
			return $url ? (string) $url : '';
		}
	}

	return '';
}

add_filter(
	'myeasybuy_registration_agreement_url',
	static function ( $url ) {
		$terms = meb_policy_page_url( 'terms-of-service', 1203 );
		return $terms ? $terms : $url;
	}
);

add_filter(
	'myeasybuy_registration_statement_url',
	static function ( $url ) {
		$privacy = get_privacy_policy_url();
		if ( $privacy ) {
			return $privacy;
		}
		$found = meb_policy_page_url( 'privacy-policy', 537 );
		return $found ? $found : $url;
	}
);

/**
 * Print footer links for the six policy pages (published pages only).
 */
function meb_footer_policy_links(): void {
	$items = array(
		array( 'return-refund-policy', '退货退款政策', 1202 ),
		array( 'privacy-policy', '隐私政策', 537 ),
		array( 'terms-of-service', '服务条款', 1203 ),
		array( 'shipping-policy', '运输与禁运政策', 1204 ),
		array( 'storage-inspection-policy', '仓储与验货政策', 1205 ),
		array( 'compensation-policy', '赔偿与包裹保障', 1206 ),
	);

	foreach ( $items as $item ) {
		$url = meb_policy_page_url( $item[0], (int) $item[2] );
		if ( ! $url ) {
			continue;
		}
		echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $item[1] ) . '</a></li>' . "
";
	}
}
