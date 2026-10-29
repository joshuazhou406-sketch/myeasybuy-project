<?php
// phpcs:ignoreFile
/**
 * Cache Manager
 * Caches server-side translation results when an API is used; otherwise no cache is needed.
 *
 * @package WTranslator
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName,Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTranslator_Cache_Manager {

	/**
	 * Return whether a cache entry exists.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has_cache( $key ) {
		return false;
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key.
	 * @return false
	 */
	public function get_cache( $key ) {
		return false;
	}

	/**
	 * Persist a cache value.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $translated_text Cached value.
	 * @return bool
	 */
	public function set_cache( $key, $translated_text ) {
		return true;
	}

	/**
	 * Delete a cache entry.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete_cache( $key ) {
		return true;
	}

	/**
	 * Clear all cached entries.
	 *
	 * @return bool
	 */
	public function clear_all_cache() {
		return true;
	}

	/**
	 * Get cache stats.
	 *
	 * @return array
	 */
	public function get_cache_stats() {
		return array(
			'total_files'          => 0,
			'total_size'           => 0,
			'total_size_formatted' => size_format( 0 ),
		);
	}
}
