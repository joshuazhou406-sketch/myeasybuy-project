<?php
/**
 * Reuse the store's own spelling of a brand.
 *
 * @package ProductUploadForWoocommerce
 */

namespace ProductUploadForWoocommerce\Import;

defined('ABSPATH') || exit;

/**
 * The AI writes a brand the way the supplier spells it ("OfficeSource"), and
 * term_exists() then misses the store's "Office Source" term, so the import
 * creates a second brand and the product drops out of the first one's
 * archive and filters. Same rules as shopify-app/api/utils/vendorCanonical.js:
 * same words, with case, accents, ®/™, separators and a trailing suffix free to
 * differ; words glued together match only where capitals mark the join.
 */
class BrandCanonical
{
	/**
	 * Trailing words that never change the brand: legal suffixes, Co,
	 * .com and regions. "Products" and "Collection" are not among them.
	 */
	const TRAILING = array(
		'inc', 'incorporated', 'llc', 'ltd', 'limited', 'corp', 'corporation', 'gmbh',
		'co', 'company', 'com',
		'us', 'uk', 'eu', 'germany', 'france', 'canada', 'australia', 'london',
	);

	/**
	 * The words of a brand name, joined by spaces. With $camel the name is also
	 * split at its capitals, so "OfficeSource" reads as "office source".
	 *
	 * @param string $name  Brand name.
	 * @param bool   $camel         Split at capitals.
	 * @param bool   $keep_trailing Keep trailing suffix words.
	 * @return string
	 */
	private static function words($name, $camel, $keep_trailing = false)
	{
		// Marks and apostrophes go first: NFKC turns ™ into "TM" and ´ into a loose accent.
		$apos = "/['’‘`´ʼʹ]/u";
		$s = str_replace(array('®', '™', '©', '℠'), '', (string)$name);
		$s = (string)preg_replace($apos, '', $s);
		if (class_exists('\Normalizer')) {
			$s = (string)\Normalizer::normalize($s, \Normalizer::FORM_KC);
			$s = (string)preg_replace($apos, '', $s);
			// Accents off Latin letters only; in kana, Thai or Devanagari the mark is part of the letter.
			$s = (string)preg_replace_callback('/\p{Latin}/u', function ($m) {
				return (string)preg_replace('/\p{M}+/u', '', (string)\Normalizer::normalize($m[0], \Normalizer::FORM_D));
			}, $s);
		} elseif (function_exists('remove_accents')) {
			$s = remove_accents($s);
		}
		if ($camel) {
			$s = (string)preg_replace('/([\p{Ll}\p{N}])(\p{Lu})/u', '$1 $2', $s);
			$s = (string)preg_replace('/(\p{Lu})(\p{Lu}\p{Ll})/u', '$1 $2', $s);
		}
		$s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
		$s = (string)preg_replace('/[&+]/u', ' $0 ', $s);
		// Every script's letters stay, so two Cyrillic brands never collapse to shared digits.
		$out = preg_split('/[^\p{L}\p{M}\p{N}&+]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($out)) {
			return '';
		}
		foreach ($out as $i => $w) {
			if ($w === 'and') {
				$out[$i] = '&';
			}
		}
		while (!$keep_trailing && count($out) > 1 && in_array(end($out), self::TRAILING, true)) {
			array_pop($out);
		}
		return implode(' ', $out);
	}

	/**
	 * The word sequences a name can match on: as written, and split at its capitals.
	 *
	 * @param string $name Brand name.
	 * @return string[] Empty when the name has no words.
	 */
	public static function keys($name)
	{
		$plain = self::words($name, false);
		return $plain === '' ? array() : array($plain, self::words($name, true));
	}

	/**
	 * Same brand: the same words as written, or the same words once split at capitals.
	 *
	 * @param string $a Brand name.
	 * @param string $b Brand name.
	 * @return bool
	 */
	public static function same($a, $b)
	{
		$x = self::keys($a);
		$y = self::keys($b);
		return $x && $y && ($x[0] === $y[0] || $x[1] === $y[1]);
	}

	/**
	 * Cleanest spelling first: trimmed, then fewer trailing suffix words
	 * (Biodance over Biodance US), then more words, then more capitals.
	 *
	 * @param string $a Candidate.
	 * @param string $b Current best.
	 * @return bool True when $a should replace $b.
	 */
	private static function better($a, $b)
	{
		$score = function ($s) {
			return array(
				$s === trim($s) ? 1 : 0,
				count(explode(' ', self::words($s, false))) - count(explode(' ', self::words($s, false, true))),
				count(preg_split('/\s+/', trim($s))),
				preg_match_all('/[A-Z]/', $s),
			);
		};
		$x = $score($a);
		$y = $score($b);
		foreach ($x as $i => $v) {
			if ($v !== $y[$i]) {
				return $v > $y[$i];
			}
		}
		return strcmp($a, $b) < 0;
	}

	/**
	 * The store's spelling of $brand if it already has that brand, else $brand trimmed.
	 *
	 * @param string   $brand    Imported brand.
	 * @param string[] $existing Brand names already in the store.
	 * @return string
	 */
	public static function pick($brand, array $existing)
	{
		$trimmed = trim((string)$brand);
		if (!self::keys($trimmed)) {
			return $trimmed;
		}
		$best = null;
		foreach ($existing as $name) {
			if (!is_string($name) || !self::same($trimmed, $name)) {
				continue;
			}
			if ($best === null || self::better($name, $best)) {
				$best = $name;
			}
		}
		return $best === null ? $trimmed : trim($best);
	}
}
