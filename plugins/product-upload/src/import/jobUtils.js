/**
 * Shared helpers for the import-job UI (cards, rows, detail modal).
 *
 * @package ProductUploadForWoocommerce
 */
import { __ } from '@wordpress/i18n';

export const TERMINAL = ['completed', 'completed_with_errors', 'cancelled', 'failed'];

// woo: not yet created
export const QUEUED_STORE = ['awaiting_store_create', 'store_creating'];

export const adminPostUrl = (window.puWCAdmin && window.puWCAdmin.adminPostUrl) || '';

export const hostOf = (u) => {
	try {
		return new URL(u).hostname.replace(/^www\./, '');
	} catch {
		return u || '';
	}
};

export const urlTail = (u) => {
	try {
		const p = new URL(u).pathname.replace(/\/+$/, '');
		return p.split('/').pop() || hostOf(u);
	} catch {
		return u || '';
	}
};

export const jobTitle = (total, host) => {
	if (total <= 1) {
		return host
			? `${__('1 product from', 'product-upload')} ${host}`
			: __('1 product', 'product-upload');
	}
	return host
		? `${total} ${__('products from', 'product-upload')} ${host}`
		: `${total} ${__('products', 'product-upload')}`;
};

export const STATUS_LABEL = {
	running: __('Importing', 'product-upload'),
	paused: __('Paused', 'product-upload'),
	completed: __('Completed', 'product-upload'),
	completed_with_errors: __('Some failed', 'product-upload'),
	cancelled: __('Cancelled', 'product-upload'),
	failed: __('Failed', 'product-upload'),
};

// pill tone by status
export const STATUS_TONE = {
	completed: 'ok',
	completed_with_errors: 'fail',
	cancelled: 'muted',
	failed: 'fail',
	running: 'wait',
	paused: 'wait',
};

export const timeAgo = (iso) => {
	if (!iso) {
		return '';
	}
	const then = new Date(iso).getTime();
	if (!then) {
		return '';
	}
	const s = Math.max(0, Math.floor((Date.now() - then) / 1000));
	if (s < 60) {
		return __('just now', 'product-upload');
	}
	const m = Math.floor(s / 60);
	if (m < 60) {
		return `${m}m ${__('ago', 'product-upload')}`;
	}
	const h = Math.floor(m / 60);
	if (h < 24) {
		return `${h}h ${__('ago', 'product-upload')}`;
	}
	const d = Math.floor(h / 24);
	return `${d}d ${__('ago', 'product-upload')}`;
};
