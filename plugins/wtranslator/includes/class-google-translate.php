<?php
// phpcs:ignoreFile
/**
 * Google Translate Integration
 * Translation is performed in the browser via Google Translate Element; no server-side API is used.
 *
 * @package WTranslator
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName,Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTranslator_Google_Translate {

	/** Whether a server-side API is configured (none by default). */
	public function is_configured() {
		return false;
	}

	/**
	 * Translate text. When no server-side API is used, returns original; used by AJAX when client provides translation.
	 *
	 * @param string $text Text to translate.
	 * @param string $source_lang Source language.
	 * @param string $target_lang Target language.
	 * @param string $page_url Optional page URL.
	 * @return string
	 */
	public function translate( $text, $source_lang, $target_lang, $page_url = '' ) {
		if ( empty( $text ) || $source_lang === $target_lang ) {
			return $text;
		}
		return $text;
	}

	/**
	 * Translate multiple texts. When no server-side API is used, returns originals.
	 *
	 * @param array  $texts Texts.
	 * @param string $source_lang Source language.
	 * @param string $target_lang Target language.
	 * @param string $page_url Optional page URL.
	 * @return array
	 */
	public function translate_batch( $texts, $source_lang, $target_lang, $page_url = '' ) {
		if ( empty( $texts ) || ! is_array( $texts ) ) {
			return array();
		}
		return $texts;
	}

	/** Detect language from text; not used when translation is client-side only. */
	public function detect_language( $text ) {
		return false;
	}
}
