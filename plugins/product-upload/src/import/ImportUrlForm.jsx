/**
 * Import URL form with a mode-selector suffix (Single URL / Multiple URLs /
 * Category page / Entire website), mirroring the Shopify app. The chosen mode
 * swaps the input label, placeholder, help and submit behaviour. Action
 * buttons sit below the field.
 *
 * @package ProductUploadForWoocommerce
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Dropdown } from '@wordpress/components';
import { isSupplierModalUrl } from './utils/classifyBulkUrl.mjs';

const Ico = ({ d }) => (
	<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		{d}
	</svg>
);
const IconSingle = <Ico d={<><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" /></>} />;
const IconMulti = <Ico d={<><line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" /></>} />;
const IconCategory = <Ico d={<><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></>} />;
const IconWebsite = <Ico d={<><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" /></>} />;
const Chevron = <Ico d={<polyline points="6 9 12 15 18 9" />} />;

const MODES = [
	{
		id: 'single',
		label: __('Single URL', 'product-upload'),
		help: __('Import one product', 'product-upload'),
		icon: IconSingle,
		inputLabel: __('Product URL', 'product-upload'),
		placeholder: 'https://supplier.com/products/example',
		multiline: false,
		fieldHelp: '',
		cta: __('Import Product', 'product-upload'),
	},
	{
		id: 'multi',
		label: __('Multiple URLs', 'product-upload'),
		help: __('Paste several product links at once', 'product-upload'),
		icon: IconMulti,
		inputLabel: __('Product URLs', 'product-upload'),
		placeholder:
			'https://supplier.com/products/a\nhttps://supplier.com/products/b\nhttps://supplier.com/products/c',
		multiline: true,
		fieldHelp: __('Paste one URL per line to import multiple products at once.', 'product-upload'),
		cta: __('Import All', 'product-upload'),
	},
	{
		id: 'category',
		label: __('Category page', 'product-upload'),
		help: __('Pick products from a listing page', 'product-upload'),
		icon: IconCategory,
		inputLabel: __('Category URL', 'product-upload'),
		placeholder: 'https://supplier.com/collections/winter-jackets',
		multiline: false,
		fieldHelp: __("Paste a collection or category page; we'll pull the product list so you can pick which ones to import.", 'product-upload'),
		cta: __('Find products', 'product-upload'),
	},
	{
		id: 'website',
		label: __('Entire website', 'product-upload'),
		help: __("Import a supplier's full catalog", 'product-upload'),
		icon: IconWebsite,
		inputLabel: __('Website URL', 'product-upload'),
		placeholder: 'supplier.com',
		multiline: false,
		fieldHelp: __("Paste the website root; we'll add it as a supplier and scan their catalog.", 'product-upload'),
		cta: __('Scan website', 'product-upload'),
	},
];

const parseUrls = (raw) =>
	String(raw || '')
		.split(/[\s,]+/)
		.map((u) => u.trim())
		.filter(Boolean);

const ImportUrlForm = ({ balance, onCustomize, onEnqueue, onBrowse, onError, onOutOfCredits }) => {
	const modes = MODES;
	const [modeId, setModeId] = useState('single');
	const [value, setValue] = useState('');
	const [submitting, setSubmitting] = useState(false);
	const mode = modes.find((m) => m.id === modeId) || modes[0];

	const enqueue = async (urls, source) => {
		setSubmitting(true);
		try {
			await onEnqueue(urls, source);
			setValue('');
		} finally {
			setSubmitting(false);
		}
	};

	const submit = async () => {
		if (submitting) {
			return;
		}
		onError(null);
		if (mode.id === 'multi') {
			const urls = parseUrls(value);
			if (urls.length === 0) {
				onError(__('No valid URLs found. Please check your input.', 'product-upload'));
				return;
			}
			// stores and listings open the browser
			const browse = urls.filter(isSupplierModalUrl);
			const direct = urls.filter((u) => !browse.includes(u));
			if (direct.length && balance <= 0 && onOutOfCredits) {
				onOutOfCredits();
				return;
			}
			if (balance < direct.length) {
				onError(
					`${__('You have', 'product-upload')} ${balance} ${__('credits but provided', 'product-upload')} ${direct.length} ${__('URLs.', 'product-upload')}`
				);
				return;
			}
			if (direct.length) {
				await enqueue(direct, 'manual');
			}
			if (browse.length) {
				setValue('');
				onBrowse(browse[0]);
			}
			return;
		}

		let url = value.trim();
		if (!url) {
			onError(__('Please enter a URL.', 'product-upload'));
			return;
		}
		// tolerate missing scheme
		const hasScheme = /^https?:\/\//i.test(url);
		if (!hasScheme && (mode.id === 'website' || !url.includes('/'))) {
			url = 'https://' + url;
		}
		try {
			new URL(url);
		} catch {
			onError(__('Please enter a valid URL.', 'product-upload'));
			return;
		}
		// store, seller, search or collection: browse it
		if (mode.id === 'website' || isSupplierModalUrl(url)) {
			onBrowse(url);
			setValue('');
			return;
		}
		if (balance <= 0) {
			if (onOutOfCredits) {
				onOutOfCredits();
				return;
			}
			onError(__('No credits remaining. Please upgrade your plan.', 'product-upload'));
			return;
		}
		await enqueue([url], mode.id === 'category' ? 'category' : 'manual');
	};

	const suffix = (
		<Dropdown
			className="pu-wc-mode-suffix-wrap"
			popoverProps={{ placement: 'bottom-end' }}
			renderToggle={({ isOpen, onToggle }) => (
				<button
					type="button"
					className="pu-wc-mode-suffix"
					onClick={onToggle}
					aria-haspopup="menu"
					aria-expanded={isOpen}
				>
					<span className="pu-wc-mode-suffix__icon">{mode.icon}</span>
					<span className="pu-wc-mode-suffix__label">{mode.label}</span>
					<span className="pu-wc-mode-suffix__chev">{Chevron}</span>
				</button>
			)}
			renderContent={({ onClose }) => (
				<div className="pu-wc-mode-menu" role="menu">
					{modes.map((m) => (
						<button
							type="button"
							role="menuitemradio"
							aria-checked={m.id === mode.id}
							key={m.id}
							className={`pu-wc-mode-menu__item${m.id === mode.id ? ' is-selected' : ''}`}
							onClick={() => {
								setModeId(m.id);
								onError(null);
								onClose();
							}}
						>
							<span className="pu-wc-mode-menu__icon">{m.icon}</span>
							<span className="pu-wc-mode-menu__body">
								<span className="pu-wc-mode-menu__label">{m.label}</span>
								<span className="pu-wc-mode-menu__help">{m.help}</span>
							</span>
							{m.id === mode.id && (
								<span className="pu-wc-mode-menu__check" aria-hidden="true">
									<Ico d={<polyline points="20 6 9 17 4 12" />} />
								</span>
							)}
						</button>
					))}
				</div>
			)}
		/>
	);

	return (
		<div className="pu-wc-importer">
			<label className="pu-wc-importer__label">{mode.inputLabel}</label>
			<div className={`pu-wc-importer__field${mode.multiline ? ' is-multiline' : ''}`}>
				{mode.multiline ? (
					<textarea
						className="pu-wc-importer__input pu-wc-importer__input--multi"
						value={value}
						onChange={(e) => setValue(e.target.value)}
						placeholder={mode.placeholder}
						rows={4}
					/>
				) : (
					<input
						type="url"
						className="pu-wc-importer__input"
						value={value}
						onChange={(e) => setValue(e.target.value)}
						onKeyDown={(e) => e.key === 'Enter' && submit()}
						placeholder={mode.placeholder}
					/>
				)}
				<div className="pu-wc-importer__suffix">{suffix}</div>
			</div>

			{mode.fieldHelp && <p className="pu-wc-importer__help">{mode.fieldHelp}</p>}

			<div className="pu-wc-importer__actions">
				<Button variant="secondary" onClick={onCustomize} disabled={submitting}>
					{__('Customize', 'product-upload')}
				</Button>
				<Button
					variant="primary"
					onClick={submit}
					isBusy={submitting}
					aria-disabled={submitting}
				>
					{submitting ? __('Starting import…', 'product-upload') : mode.cta}
				</Button>
			</div>

			<p className="pu-wc-importer__ai">
				{__('AI-generated, review imports before publishing.', 'product-upload')}
			</p>
		</div>
	);
};

export default ImportUrlForm;
