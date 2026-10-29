<?php
/**
 * Blueprint description sections rendered as native WooCommerce product tabs.
 *
 * @package ProductUploadForWoocommerce
 */

namespace ProductUploadForWoocommerce\Import;

defined('ABSPATH') || exit;

/**
 * When "Show sections as separate tabs" is on, the server splits the layout:
 * product_description keeps only the lead copy and every section arrives in
 * product_info_sections as {key, title, html}. Each one becomes its own tab.
 *
 * Tabs sit between Description (10) and Additional information (20), which is
 * WooCommerce core's attributes/dimensions table and unrelated to these.
 */
class ProductTabs
{
	/** Sections stored on the product at import time. */
	const META = '_pu_wc_info_sections';

	/** Namespaced so a section can never collide with a core tab key. */
	const TAB_PREFIX = 'pu_section_';

	public function register()
	{
		add_filter('woocommerce_product_tabs', array($this, 'add_tabs'));

		// Core prints an <h2> inside its own tabs that just repeats the tab
		// label. Ours don't, so on a product with section tabs the Description
		// and Additional information tabs are the odd ones out.
		add_filter('woocommerce_product_description_heading', array($this, 'drop_core_tab_heading'));
		add_filter('woocommerce_product_additional_information_heading', array($this, 'drop_core_tab_heading'));
	}

	/**
	 * Sections stored on the product currently in the loop, if any.
	 *
	 * @return array
	 */
	private function current_sections()
	{
		global $product;

		if (!$product instanceof \WC_Product) {
			return array();
		}

		$sections = $product->get_meta(self::META);

		return is_array($sections) ? $sections : array();
	}

	/**
	 * Drop the redundant core heading, but only on a product this plugin gave
	 * section tabs. Every other product in the store keeps WooCommerce's own.
	 *
	 * @param  string $heading Default heading.
	 * @return string
	 */
	public function drop_core_tab_heading($heading)
	{
		return empty($this->current_sections()) ? $heading : '';
	}

	/**
	 * Stage sections on the product. The caller saves.
	 *
	 * Sanitized here rather than at render so whatever is stored is already
	 * safe: the drain runs under cron with no logged-in user, so it never has
	 * unfiltered_html and the description gets the same treatment.
	 *
	 * @param \WC_Product $product  Product being written.
	 * @param mixed       $sections Raw product_info_sections from the API.
	 */
	public static function stash($product, $sections)
	{
		$clean = self::sanitize($sections);
		if (empty($clean)) {
			// A re-import with sections off must not leave stale tabs behind.
			$product->delete_meta_data(self::META);
			return;
		}
		$product->update_meta_data(self::META, $clean);
	}

	/**
	 * @param  mixed $sections Raw list from the API.
	 * @return array           Entries with a non-empty title and body.
	 */
	private static function sanitize($sections)
	{
		if (!is_array($sections)) {
			return array();
		}

		$out = array();
		foreach ($sections as $index => $section) {
			if (!is_array($section)) {
				continue;
			}
			$title = trim(wp_strip_all_tags((string)($section['title'] ?? '')));
			$html = trim((string)($section['html'] ?? ''));
			if ($title === '' || $html === '') {
				continue;
			}
			$key = sanitize_key((string)($section['key'] ?? ''));
			if ($key === '') {
				$key = 's' . $index;
			}
			$out[] = array(
				'key' => $key,
				'title' => $title,
				'html' => wp_kses_post($html),
			);
		}

		return $out;
	}

	/**
	 * @param  array $tabs Tabs collected so far.
	 * @return array
	 */
	public function add_tabs($tabs)
	{
		$sections = $this->current_sections();
		if (empty($sections)) {
			return $tabs;
		}

		// 10 = Description, 20 = Additional information, 30 = Reviews.
		$priority = 11;
		$seen = array();

		foreach ($sections as $index => $section) {
			$title = trim((string)($section['title'] ?? ''));
			$html = trim((string)($section['html'] ?? ''));
			if ($title === '' || $html === '') {
				continue;
			}

			$key = !empty($section['key']) ? $section['key'] : 's' . $index;
			$id = self::TAB_PREFIX . $key;
			// Two sections sharing a kind would otherwise overwrite each other.
			if (isset($seen[$id]) || isset($tabs[$id])) {
				$id .= '_' . $index;
			}
			$seen[$id] = true;

			$tabs[$id] = array(
				'title' => $title,
				'priority' => $priority,
				'callback' => function () use ($html) {
					echo wp_kses_post($html);
				},
			);
			$priority++;
		}

		return $tabs;
	}
}
