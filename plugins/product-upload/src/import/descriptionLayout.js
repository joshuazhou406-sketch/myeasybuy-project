/**
 * Blueprint description-layout model for the Woo settings UI.
 *
 * Mirrors shopify-app/web/utils/descriptionLayout.js + the block model in
 * DescriptionLayoutBuilder.jsx (keep in sync). Woo renders the "clean" look
 * only and specs default to bullets, because the WP-cron import path runs
 * post_content through wp_kses_post, which strips inline table styling.
 */
import { __, sprintf } from '@wordpress/i18n';

// add-block factories
export const BLOCK_TYPES = ['title', 'ai_overview', 'specs', 'source_sections', 'description_images', 'faq', 'custom_text'];

export const NEW_BLOCK = {
	title: () => ({ type: 'title' }),
	ai_overview: () => ({ type: 'ai_overview', paragraphs: 2, tone: 'expert' }),
	specs: () => ({ type: 'specs', format: 'bullets_bold', mode: 'extensive' }),
	source_sections: () => ({ type: 'source_sections', mode: 'all', aiFallback: true }),
	description_images: () => ({ type: 'description_images', count: 8 }),
	faq: () => ({ type: 'faq', count: 4, display: 'expandable' }),
	custom_text: () => ({ type: 'custom_text', html: '' }),
};

export const BLOCK_LABEL = {
	title: __('Product title', 'product-upload'),
	ai_overview: __('AI overview', 'product-upload'),
	specs: __('Specifications', 'product-upload'),
	source_sections: __('Source sections', 'product-upload'),
	description_images: __('Description images', 'product-upload'),
	faq: __('FAQ', 'product-upload'),
	custom_text: __('Text', 'product-upload'),
};

// content source per block
export const BLOCK_SOURCE = {
	title: { label: __('From product data', 'product-upload'), color: '#2c6ecb' },
	ai_overview: { label: __('AI-written', 'product-upload'), color: '#7b50ff' },
	specs: { label: __('From product data', 'product-upload'), color: '#2c6ecb' },
	source_sections: { label: __('From supplier page', 'product-upload'), color: '#15803d' },
	description_images: { label: __('From supplier page', 'product-upload'), color: '#15803d' },
	faq: { label: __('AI-written', 'product-upload'), color: '#b45309' },
	custom_text: { label: __('Your text', 'product-upload'), color: '#0d9488' },
};

export const TONE_OPTIONS = [
	{ label: __('Expert', 'product-upload'), value: 'expert' },
	{ label: __('Professional', 'product-upload'), value: 'professional' },
	{ label: __('Persuasive', 'product-upload'), value: 'persuasive' },
	{ label: __('Playful', 'product-upload'), value: 'playful' },
	{ label: __('Formal', 'product-upload'), value: 'formal' },
	{ label: __('Friendly', 'product-upload'), value: 'friendly' },
	{ label: __('Casual', 'product-upload'), value: 'casual' },
	{ label: __('Technical', 'product-upload'), value: 'technical' },
];

export const SECTION_KINDS = [
	{ kind: 'highlights', label: __('Highlights', 'product-upload') },
	{ kind: 'materials_care', label: __('Materials & care', 'product-upload') },
	{ kind: 'sizing_fit', label: __('Sizing & fit', 'product-upload') },
	{ kind: 'size_chart', label: __('Size chart', 'product-upload') },
	{ kind: 'compatibility', label: __('Compatibility', 'product-upload') },
	{ kind: 'how_to_use', label: __('How to use', 'product-upload') },
	{ kind: 'ingredients', label: __('Ingredients', 'product-upload') },
	{ kind: 'warranty', label: __('Warranty', 'product-upload') },
	{ kind: 'whats_in_the_box', label: __("What's included", 'product-upload') },
	{ kind: 'file_downloads', label: __('Downloads', 'product-upload') },
	{ kind: 'videos', label: __('Videos', 'product-upload') },
];
export const ALL_SECTION_KINDS = SECTION_KINDS.map((s) => s.kind);
export const SECTION_LABEL = Object.fromEntries(SECTION_KINDS.map((s) => [s.kind, s.label]));

// no mode = all-if-full
export const sourceSectionMode = (block) => {
	if (block.mode === 'all' || block.mode === 'specific') {
		return block.mode;
	}
	if (block.kinds == null || block.kinds.length >= ALL_SECTION_KINDS.length) {
		return 'all';
	}
	return 'specific';
};
export const sourceSectionKinds = (block) =>
	sourceSectionMode(block) === 'all'
		? ALL_SECTION_KINDS
		: ALL_SECTION_KINDS.filter((k) => (block.kinds ?? []).includes(k));

// new-shop seed default
export const DEFAULT_LAYOUT = [
	{ type: 'ai_overview', paragraphs: 2 },
	{ type: 'specs', format: 'bullets_bold', mode: 'extensive', heading: __('Specifications', 'product-upload') },
	{ type: 'source_sections', mode: 'all', aiFallback: true },
];

let uid = 0;
export const withBlockIds = (layout) =>
	(Array.isArray(layout) ? layout : []).map((b) =>
		b._id ? b : { ...b, _id: `blk-${++uid}` }
	);

// strip local-only fields
export const serializeLayout = (layout) =>
	Array.isArray(layout)
		? layout
				.filter((b) => b && b.enabled !== false)
				.map((b) => {
					const clean = { ...b };
					delete clean._id;
					delete clean.enabled;
					return clean;
				})
		: null;

// summary chips
export const describeLayout = (layout) => {
	const blocks = Array.isArray(layout) && layout.length ? layout : DEFAULT_LAYOUT;
	return blocks
		.filter((b) => b && b.enabled !== false)
		.map((b) => BLOCK_LABEL[b.type])
		.filter(Boolean);
};

// collapsed-row recap
const OVERVIEW_LENGTH_LABEL = { 1: __('Short', 'product-upload'), 2: __('Medium', 'product-upload'), 3: __('Long', 'product-upload') };
const SPEC_FORMAT_LABEL = { bullets_bold: __('Bullets', 'product-upload'), table: __('Table', 'product-upload'), list: __('Plain list', 'product-upload') };
const TONE_LABEL = Object.fromEntries(TONE_OPTIONS.map((t) => [t.value, t.label]));
export const blockSummary = (block) => {
	switch (block.type) {
		case 'title':
			return __('Product name, on its own line', 'product-upload');
		case 'ai_overview':
			return `${OVERVIEW_LENGTH_LABEL[block.paragraphs || 2]} · ${TONE_LABEL[block.tone] ?? __('Expert', 'product-upload')}`;
		case 'specs':
			return `${SPEC_FORMAT_LABEL[block.format] ?? __('Bullets', 'product-upload')} · ${block.mode === 'brief' ? __('Brief', 'product-upload') : __('Extensive', 'product-upload')}`;
		case 'faq': {
			const d = block.display === 'plain' ? __('Plain', 'product-upload') : __('Expandable', 'product-upload');
			return `${block.count || 4} · ${d}`;
		}
		case 'source_sections':
			return sourceSectionMode(block) === 'all'
				? __('All supplier sections', 'product-upload')
				: __('Selected sections', 'product-upload');
		case 'description_images':
			return block.count
				? sprintf(__('Up to %d images', 'product-upload'), block.count)
				: __('Every image in the description', 'product-upload');
		case 'custom_text':
			return block.html ? __('Custom text', 'product-upload') : __('Empty', 'product-upload');
		default:
			return '';
	}
};
