/**
 * ISO pools + readable-name helpers for the supplier locale pin. Ported from
 * the Shopify app's localeOptions.js (pure data, no framework deps).
 *
 * @package ProductUploadForWoocommerce
 */

export const LOCALE_CURRENCIES = [
	'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'NZD', 'JPY', 'CNY', 'HKD', 'TWD',
	'KRW', 'SGD', 'INR', 'IDR', 'MYR', 'PHP', 'THB', 'VND', 'BRL', 'MXN',
	'CLP', 'COP', 'PEN', 'ARS', 'ZAR', 'TRY', 'AED', 'SAR', 'ILS', 'EGP',
	'RUB', 'PLN', 'SEK', 'NOK', 'DKK', 'CZK', 'HUF', 'RON', 'BGN', 'CHF',
	'UAH', 'PKR', 'BDT', 'NGN', 'KES',
];

export const LOCALE_COUNTRIES = [
	'US', 'CA', 'MX', 'BR', 'CL', 'CO', 'PE', 'EC', 'AR', 'GB', 'IE', 'FR',
	'DE', 'IT', 'ES', 'PT', 'NL', 'BE', 'AT', 'CH', 'PL', 'SE', 'NO', 'DK',
	'FI', 'CZ', 'HU', 'RO', 'BG', 'GR', 'TR', 'RU', 'UA', 'AU', 'NZ', 'JP',
	'KR', 'CN', 'HK', 'TW', 'SG', 'MY', 'TH', 'VN', 'PH', 'ID', 'IN', 'PK',
	'BD', 'AE', 'SA', 'IL', 'EG', 'ZA', 'NG', 'KE', 'MA',
];

export const LOCALE_LANGUAGES = [
	'en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'sv', 'no', 'da', 'fi',
	'cs', 'el', 'ru', 'uk', 'tr', 'ar', 'he', 'ja', 'ko', 'zh', 'th', 'vi',
	'id', 'ms', 'hi', 'bn',
];

// ISO code -> readable name
export function displayName(type, code) {
	if (!code) {
		return '';
	}
	try {
		const n = new Intl.DisplayNames(['en'], { type })?.of(code);
		return n && n !== code ? n : code;
	} catch {
		return code;
	}
}

// options + auto-detect row
export function localeOptions(type, codes, detected, autoLabel) {
	let names;
	try {
		names = new Intl.DisplayNames(['en'], { type });
	} catch {
		names = null;
	}
	const label = (code) => {
		const n = names?.of(code);
		return n && n !== code ? `${n} (${code})` : code;
	};
	const opts = codes.map((code) => ({ label: label(code), value: code }));
	// keep detected code selectable
	if (detected && !codes.includes(detected)) {
		opts.push({ label: label(detected), value: detected });
	}
	opts.sort((a, b) => a.label.localeCompare(b.label));
	return [{ label: autoLabel || 'Auto-detect', value: '' }, ...opts];
}
