<?php
/**
 * Image sideloader – downloads supplier images into the WP Media Library.
 *
 * @package ProductUploadForWoocommerce\Import
 */

namespace ProductUploadForWoocommerce\Import;

defined('ABSPATH') || exit;

/**
 * ImageHandler class.
 *
 * Tries the supplier's direct image URL first (faster, preserves original
 * quality, no third-party dependency) and falls back to the ImageKit
 * transcode proxy when the direct download fails — typical cases being
 * hotlink-blocked CDNs, expired certificates, or supplier-returned formats
 * WP rejects (e.g. AVIF).
 *
 * @since 1.0.0
 */
class ImageHandler
{

	/**
	 * ImageKit proxy base URL. Transcodes remote images to PNG. Used only
	 * as a fallback when the direct download fails.
	 *
	 * @var string
	 */
	const IMAGEKIT_BASE = 'https://ik.imagekit.io/c2dr4dawp/tr:f-png/';

	/**
	 * ImageKit proxy that transcodes to JPG. Used as the primary fetch when
	 * the merchant turns on image conversion (e.g. to strip webp for a
	 * downstream system like Odoo that rejects it).
	 *
	 * @var string
	 */
	const IMAGEKIT_JPG_BASE = 'https://ik.imagekit.io/c2dr4dawp/tr:f-jpg/';

	// ------------------------------------------------------------------
	// Public API
	// ------------------------------------------------------------------

	/**
	 * Turn a product name or slug into a filename stem, or '' when nothing
	 * usable survives — which callers read as "keep the supplier's filename".
	 *
	 * Deliberately not sanitize_title(): that builds a *slug*, and it
	 * percent-encodes non-latin scripts (上海丝绸 -> %e4%b8%8a%e6%b5%b7…).
	 * sanitize_file_name() then strips the % signs, leaving hex soup like
	 * `e4b88ae6b5b7-1.jpg` — worse for traceability than the supplier code
	 * this feature exists to replace. ASCII or nothing.
	 *
	 * @param  string $raw Product name or slug.
	 * @return string      Sanitised stem, capped at 80 chars.
	 */
	public static function name_base($raw)
	{
		$slug = strtolower(remove_accents((string)$raw));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
		$slug = trim($slug, '-');
		if (strlen($slug) > 80) {
			$slug = rtrim(substr($slug, 0, 80), '-');
		}
		return $slug;
	}

	/**
	 * Sideload a single image. Direct URL first, ImageKit proxy as
	 * fallback. When $convert is set the proxy becomes the primary fetch and
	 * the image is forced to JPG.
	 *
	 * @param  string $url        Original remote image URL.
	 * @param  int    $product_id Parent post ID for the attachment.
	 * @param  string $alt_text   Optional alt text for the attachment.
	 * @param  bool   $convert    Transcode to JPG via the ImageKit proxy.
	 * @param  string $name_stem  Filename to save under, minus the extension.
	 *                            Empty keeps the supplier's own filename.
	 * @return int|\WP_Error      Attachment ID on success.
	 */
	public function sideload($url, $product_id = 0, $alt_text = '', $convert = false, $name_stem = '')
	{
		$this->require_wp_media_functions();

		if ($convert) {
			// Merchant opted to transcode images (their downstream system
			// rejects webp, e.g. an Odoo bridge). Fetch through the ImageKit
			// proxy as JPG; on failure fall back to the PNG proxy. Both force
			// the saved file's extension so bytes and name agree. We never
			// fall back to the raw URL here — that would reintroduce the webp
			// the merchant is trying to avoid.
			$attachment_id = $this->do_sideload(self::IMAGEKIT_JPG_BASE . $url, $product_id, 'jpg', $name_stem);
			if (is_wp_error($attachment_id) || !$attachment_id) {
				$attachment_id = $this->do_sideload(self::IMAGEKIT_BASE . $url, $product_id, 'png', $name_stem);
			}
		} else {
			$attachment_id = $this->do_sideload($url, $product_id, null, $name_stem);

			if (is_wp_error($attachment_id) || !$attachment_id) {
				// Direct failed — common causes are hotlink protection,
				// non-WP formats (AVIF), or 4xx/5xx from the supplier CDN.
				// Try the ImageKit proxy which fetches the image server-side
				// and transcodes to PNG.
				$attachment_id = $this->do_sideload(self::IMAGEKIT_BASE . $url, $product_id, null, $name_stem);
			}
		}

		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		if (!empty($alt_text)) {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
		}

		return $attachment_id;
	}

	/**
	 * Sideload multiple images, skipping duplicates and failures.
	 *
	 * @param  array  $media      Array of items with 'src_image_url' and optionally 'alt_text'.
	 * @param  int    $product_id Parent post ID.
	 * @param  bool   $convert    Transcode each image to JPG via the ImageKit proxy.
	 * @param  string $base_name  Filename stem; images are saved as
	 *                            `{base}-1`, `{base}-2`, … Empty keeps the
	 *                            supplier's own filenames.
	 * @return int[]              Attachment IDs (failures silently skipped).
	 */
	public function sideload_many(array $media, $product_id = 0, $convert = false, $base_name = '')
	{
		$ids = array();
		$seen_urls = array();
		$position = 0;

		foreach ($media as $item) {
			$src = $item['src_image_url'] ?? '';
			if (empty($src) || isset($seen_urls[$src])) {
				continue;
			}
			$seen_urls[$src] = true;
			$position++;

			$alt = $item['alt_text'] ?? '';
			$stem = $base_name ? $base_name . '-' . $position : '';
			$result = $this->sideload($src, $product_id, $alt, $convert, $stem);
			if (!is_wp_error($result)) {
				$ids[] = $result;
			}
		}

		return $ids;
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Core sideload routine wrapping WordPress media functions.
	 *
	 * @param  string      $url        Image URL to download.
	 * @param  int         $product_id Parent post ID.
	 * @param  string|null $force_ext  Override the saved file extension (e.g.
	 *                                 'jpg' when the proxy transcoded it).
	 * @param  string      $name_stem  Filename to save under, minus extension.
	 * @return int|\WP_Error      Attachment ID or WP_Error.
	 */
	private function do_sideload($url, $product_id, $force_ext = null, $name_stem = '')
	{
		$tmp = download_url($url, 60);
		if (is_wp_error($tmp)) {
			return $tmp;
		}

		$file_array = array(
			'name' => $this->filename_from_url($url, $tmp, $force_ext, $name_stem),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload($file_array, $product_id);

		// Clean up temp file whether or not sideload succeeded.
		if (file_exists($tmp)) {
			wp_delete_file($tmp);
		}

		return $attachment_id;
	}

	/**
	 * Derive a sensible filename from a URL. Handles two edge cases that
	 * silently break sideloads:
	 *
	 *   1. Extension-less URLs (e.g. CDN keys, signed cloudfront URLs).
	 *      We probe the downloaded file's actual MIME and append the
	 *      right extension. Without this the file lands as `.tmp` and
	 *      `wp_check_filetype_and_ext` rejects it.
	 *
	 *   2. Pathologically long basenames (e.g. base64-encoded CloudFront
	 *      image-handler keys can run 280+ chars, past the 255-byte
	 *      filesystem limit). We replace with a short hash so the move
	 *      step in wp_handle_sideload succeeds.
	 *
	 * @param  string      $url       Remote image URL.
	 * @param  string|null $tmp_path  Downloaded temp file (for MIME sniffing).
	 * @param  string|null $force_ext Caller-known output extension to use.
	 * @param  string      $name_stem Replaces the URL-derived basename when set;
	 *                                the extension is still resolved below, so
	 *                                bytes and name always agree.
	 * @return string                Sanitized filename safe for media_handle_sideload.
	 */
	private function filename_from_url($url, $tmp_path = null, $force_ext = null, $name_stem = '')
	{
		$path = wp_parse_url($url, PHP_URL_PATH);
		$name = $path ? basename($path) : '';

		// Merchant asked for product-named files. Keep the extension logic:
		// WP rejects a name whose extension doesn't match the real MIME.
		if ($name_stem !== '') {
			$ext = $force_ext ?: ($tmp_path ? $this->detect_image_extension($tmp_path) : null);
			if (!$ext) {
				$url_ext = strtolower(pathinfo(preg_replace('/[?#].*$/', '', $name), PATHINFO_EXTENSION));
				$ext = in_array($url_ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true) ? $url_ext : 'jpg';
			}
			return sanitize_file_name($name_stem . '.' . $ext);
		}

		// Strip query strings from filename fragments.
		$name = preg_replace('/[?#].*$/', '', $name);

		// Cap absurd basenames. 80 chars is well below the 255-byte limit
		// on every common filesystem with room for the extension; anything
		// longer is almost certainly a CDN-style encoded key, not a real
		// filename worth preserving.
		if (strlen($name) > 80 || $name === '') {
			$name = 'product-image-' . substr(md5($url), 0, 12);
		}

		// Caller knows the output format (the proxy transcoded it). The
		// basename here is still the *original* supplier file (often .webp),
		// so trust the forced extension over the URL's.
		if ($force_ext) {
			$base = pathinfo($name, PATHINFO_FILENAME);
			if ($base === '') {
				$base = 'product-image';
			}
			return sanitize_file_name($base . '.' . $force_ext);
		}

		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true)) {
			// Strip whatever non-image extension is there (a base64 trailer
			// that happens to contain a dot, etc) then append a real one.
			$base = $ext === '' ? $name : pathinfo($name, PATHINFO_FILENAME);
			$detected = $tmp_path ? $this->detect_image_extension($tmp_path) : null;
			$name = $base . '.' . ($detected ?: 'jpg');
		}

		return sanitize_file_name($name);
	}

	/**
	 * Probe a downloaded file's actual image type via getimagesize() so we
	 * can pick a correct extension when the URL doesn't carry one. Returns
	 * null for non-images / unrecognized formats so the caller can fall
	 * back to a default.
	 */
	private function detect_image_extension($path)
	{
		if (!file_exists($path)) {
			return null;
		}
		$info = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if (!$info || empty($info['mime'])) {
			return null;
		}
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/webp' => 'webp',
			'image/gif' => 'gif',
		);
		return $map[$info['mime']] ?? null;
	}

	/**
	 * Load WordPress media helper functions when running outside wp-admin.
	 */
	private function require_wp_media_functions()
	{
		if (!function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}