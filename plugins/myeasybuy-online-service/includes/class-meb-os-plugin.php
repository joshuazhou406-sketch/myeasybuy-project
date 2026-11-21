<?php
/**
 * Front-end widget bootstrap.
 *
 * @package MyEasyBuy_Online_Service
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin hooks.
 */
class MEB_OS_Plugin {

	/**
	 * Hook everything.
	 */
	public static function init(): void {
		add_action( 'save_post_page', array( 'MEB_OS_KB', 'maybe_rebuild' ) );
		add_filter( 'the_content', array( 'MEB_OS_KB', 'add_heading_anchors' ), 12 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_anchor_css' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_bootstrap' ), 40 );
	}


	/**
	 * Keep clause heading targets visible below the sticky site header.
	 */
	public static function print_anchor_css(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}
		$slug  = get_post_field( 'post_name', get_queried_object_id() );
		$slugs = array_column( MEB_OS_KB::policies(), 'slug' );
		if ( ! in_array( $slug, $slugs, true ) ) {
			return;
		}
		echo '<style id="meb-os-anchors">:is(h2,h3,h4)[id^="meb-os-"]{scroll-margin-top:9rem}</style>' . "\n";
	}

	/**
	 * Public index endpoint (cached via the KB option).
	 */
	public static function register_rest(): void {
		register_rest_route(
			'myeasybuy/v1',
			'/help-index',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return new WP_REST_Response( MEB_OS_KB::get(), 200 );
				},
			)
		);
	}

	/**
	 * Whether the widget should be hidden on this request.
	 */
	public static function should_hide(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return true;
		}
		return (bool) apply_filters( 'myeasybuy_os_hide', false );
	}

	/**
	 * Current mascot key (folder prefix). Default is B, "beaver".
	 */
	public static function mascot(): string {
		$opt = get_option( 'myeasybuy_os_mascot', '' );
		$key = $opt ? sanitize_key( (string) $opt ) : MEB_OS_MASCOT;
		return $key ? $key : 'beaver';
	}

	/**
	 * Lazy-load a tiny bootstrap; CSS/JS load after idle or first click intent.
	 */
	public static function print_bootstrap(): void {
		if ( self::should_hide() ) {
			return;
		}
		$css    = MEB_OS_URL . 'assets/css/online-service.css';
		$search = MEB_OS_URL . 'assets/js/search.js';
		$app    = MEB_OS_URL . 'assets/js/online-service.js';
		$ver    = MEB_OS_VERSION;
		$base   = MEB_OS_URL . 'assets/mascot/' . rawurlencode( self::mascot() );
		$cfg    = array(
			'kbUrl'        => rest_url( 'myeasybuy/v1/help-index' ),
			'mascotBase'   => $base,
			'mascotAnim'   => $base . '-anim.webp',
			'contactEmail' => MEB_OS_CONTACT_EMAIL,
			'hide'         => false,
		);
		$json = wp_json_encode( $cfg );
		$webp = esc_url( $base . '-200.webp' );
		echo '<link rel="preload" as="image" href="' . $webp . '" type="image/webp" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
<script>
window.mebOnlineService = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
(function(){
	var loaded=false;
	function load(){
		if(loaded) return;
		loaded=true;
		var c=document.createElement('link'); c.rel='stylesheet'; c.href=<?php echo wp_json_encode( $css . '?ver=' . $ver ); ?>;
		document.head.appendChild(c);
		function add(src){ var s=document.createElement('script'); s.src=src; s.defer=true; document.body.appendChild(s); return s; }
		var a=add(<?php echo wp_json_encode( $search . '?ver=' . $ver ); ?>);
		a.onload=function(){ add(<?php echo wp_json_encode( $app . '?ver=' . $ver ); ?>); };
	}
	if('requestIdleCallback' in window){ requestIdleCallback(load,{timeout:1800}); }
	else { window.addEventListener('load', function(){ setTimeout(load, 400); }); }
	['pointerdown','keydown','scroll'].forEach(function(ev){
		window.addEventListener(ev, load, {once:true, passive:true});
	});
})();
</script>
		<?php
	}
}
