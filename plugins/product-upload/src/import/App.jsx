/**
 * Product Upload for WooCommerce – main page.
 *
 * The plugin auto-provisions on first load (no manual connect step required).
 * This page shows:
 *   • A loading spinner while waiting for the status check.
 *   • An error notice if provisioning failed, with a Retry button.
 *   • The import form with queue once connected.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	Notice,
	Spinner,
	Modal,
	Icon,
	CheckboxControl,
	SelectControl,
	TextareaControl,
	FormTokenField,
	DropdownMenu,
} from '@wordpress/components';
import {
	moreVertical,
	title as titleIcon,
	postContent,
	tag as tagIcon,
	cog,
	chevronDown,
	plus,
	globe,
	seen,
	published,
	currencyDollar,
	box,
	tool,
} from '@wordpress/icons';
import { FieldCard, SourceSegment, CARD_CSS } from './SettingsCards';
import apiFetch from '@wordpress/api-fetch';
import ImportsSection from './ImportsSection';
import ExtensionPairModal from './ExtensionPairModal';
import BulkImportEmpty from './BulkImportEmpty';
import ImportUrlForm from './ImportUrlForm';
import CategoryTriageModal from './CategoryTriageModal';
import SentimentBanner from './SentimentBanner';
import ImportsControl from './ImportsControl';
import SupplierModal from './SupplierModal';
import BulkImportModal from './BulkImportModal';
import { SupplierRowsSkeleton } from './Skeletons';
import DescriptionLayoutField from './DescriptionLayoutBuilder';
import { doneForYouUrl } from './DoneForYouLink';

const CONCURRENCY_LIMIT = 4;
const CRISP_WEBSITE_ID = '98b066cd-d8f4-4f19-9062-16d7debd7437';

/**
 * Load Crisp live-chat widget and send site context when the chat opens.
 */
const useCrispChat = () => {
	useEffect(() => {
		let script;
		try {
			window.$crisp = [];
			window.CRISP_WEBSITE_ID = CRISP_WEBSITE_ID;

			script = document.createElement('script');
			script.src = 'https://client.crisp.chat/l.js';
			script.async = true;
			document.head.appendChild(script);

			window.CRISP_RUNTIME_CONFIG = {
				locale: 'en',
			};
			window.$crisp.push(['config', 'position:reverse', [false]]);

			window.$crisp.push(['on', 'chat:opened', () => {
				const siteUrl = window.puWCAdmin?.apiUrl?.replace(/\/wp-json\/.*/, '') || window.location.origin;
				window.$crisp.push(['set', 'user:company', [siteUrl]]);
				window.$crisp.push(['set', 'session:data', ['shop', siteUrl]]);
				window.$crisp.push(['set', 'session:data', ['platform', 'woocommerce']]);
			}]);
		} catch (e) {
			// Silently ignore - chat is non-critical.
		}

		return () => {
			try {
				if (script && script.parentNode) {
					script.parentNode.removeChild(script);
				}
				window.$crisp = [];
				delete window.CRISP_WEBSITE_ID;
			} catch (e) {
				// ignore
			}
		};
	}, []);
};

// ---------------------------------------------------------------------------
// Static data for settings dropdowns
// ---------------------------------------------------------------------------

const TONES = [
	{ label: 'Expert', value: 'expert' },
	{ label: 'Persuasive', value: 'persuasive' },
	{ label: 'Playful', value: 'playful' },
	{ label: 'Formal', value: 'formal' },
	{ label: 'Friendly', value: 'friendly' },
	{ label: 'Casual', value: 'casual' },
	{ label: 'Technical', value: 'technical' },
	{ label: 'Use Original (beta)', value: 'original' },
];

const LANGUAGES = [
	'Arabic', 'Bengali', 'Chinese', 'Czech', 'Danish', 'Dutch', 'English',
	'Finnish', 'French', 'German', 'Greek', 'Hebrew', 'Hindi', 'Hungarian',
	'Indonesian', 'Italian', 'Japanese', 'Korean', 'Malay', 'Norwegian',
	'Persian', 'Polish', 'Portuguese', 'Romanian', 'Russian', 'Slovak',
	'Spanish', 'Swedish', 'Thai', 'Turkish', 'Ukrainian', 'Vietnamese',
];

const COUNTRIES = [
	{ code: 'AU', name: 'Australia' },
	{ code: 'AT', name: 'Austria' },
	{ code: 'BE', name: 'Belgium' },
	{ code: 'BR', name: 'Brazil' },
	{ code: 'CA', name: 'Canada' },
	{ code: 'CN', name: 'China' },
	{ code: 'CZ', name: 'Czech Republic' },
	{ code: 'DK', name: 'Denmark' },
	{ code: 'FI', name: 'Finland' },
	{ code: 'FR', name: 'France' },
	{ code: 'DE', name: 'Germany' },
	{ code: 'GR', name: 'Greece' },
	{ code: 'HK', name: 'Hong Kong' },
	{ code: 'HU', name: 'Hungary' },
	{ code: 'IN', name: 'India' },
	{ code: 'ID', name: 'Indonesia' },
	{ code: 'IE', name: 'Ireland' },
	{ code: 'IL', name: 'Israel' },
	{ code: 'IT', name: 'Italy' },
	{ code: 'JP', name: 'Japan' },
	{ code: 'KR', name: 'South Korea' },
	{ code: 'MY', name: 'Malaysia' },
	{ code: 'MX', name: 'Mexico' },
	{ code: 'NL', name: 'Netherlands' },
	{ code: 'NZ', name: 'New Zealand' },
	{ code: 'NO', name: 'Norway' },
	{ code: 'PH', name: 'Philippines' },
	{ code: 'PL', name: 'Poland' },
	{ code: 'PT', name: 'Portugal' },
	{ code: 'RO', name: 'Romania' },
	{ code: 'RU', name: 'Russia' },
	{ code: 'SA', name: 'Saudi Arabia' },
	{ code: 'SG', name: 'Singapore' },
	{ code: 'ZA', name: 'South Africa' },
	{ code: 'ES', name: 'Spain' },
	{ code: 'SE', name: 'Sweden' },
	{ code: 'CH', name: 'Switzerland' },
	{ code: 'TW', name: 'Taiwan' },
	{ code: 'TH', name: 'Thailand' },
	{ code: 'TR', name: 'Turkey' },
	{ code: 'AE', name: 'United Arab Emirates' },
	{ code: 'GB', name: 'United Kingdom' },
	{ code: 'US', name: 'United States' },
	{ code: 'VN', name: 'Vietnam' },
];

const CURRENCIES = [
	{ code: 'AUD', symbol: 'A$' },
	{ code: 'BRL', symbol: 'R$' },
	{ code: 'CAD', symbol: 'CA$' },
	{ code: 'CHF', symbol: 'CHF' },
	{ code: 'CNY', symbol: '\u00a5' },
	{ code: 'CZK', symbol: 'K\u010d' },
	{ code: 'DKK', symbol: 'kr' },
	{ code: 'EUR', symbol: '\u20ac' },
	{ code: 'GBP', symbol: '\u00a3' },
	{ code: 'HKD', symbol: 'HK$' },
	{ code: 'HUF', symbol: 'Ft' },
	{ code: 'IDR', symbol: 'Rp' },
	{ code: 'ILS', symbol: '\u20aa' },
	{ code: 'INR', symbol: '\u20b9' },
	{ code: 'JPY', symbol: '\u00a5' },
	{ code: 'KRW', symbol: '\u20a9' },
	{ code: 'MXN', symbol: 'MX$' },
	{ code: 'MYR', symbol: 'RM' },
	{ code: 'NOK', symbol: 'kr' },
	{ code: 'NZD', symbol: 'NZ$' },
	{ code: 'PHP', symbol: '\u20b1' },
	{ code: 'PLN', symbol: 'z\u0142' },
	{ code: 'RON', symbol: 'lei' },
	{ code: 'RUB', symbol: '\u20bd' },
	{ code: 'SAR', symbol: '\ufdfc' },
	{ code: 'SEK', symbol: 'kr' },
	{ code: 'SGD', symbol: 'S$' },
	{ code: 'THB', symbol: '\u0e3f' },
	{ code: 'TRY', symbol: '\u20ba' },
	{ code: 'TWD', symbol: 'NT$' },
	{ code: 'USD', symbol: '$' },
	{ code: 'VND', symbol: '\u20ab' },
	{ code: 'ZAR', symbol: 'R' },
];

const {
	connected: initialConnected,
	connectionData: initialConnectionData,
	needsConnect: initialNeedsConnect,
	connectUrl: oauthConnectUrl,
	connectError: oauthConnectError,
	justConnected: oauthJustConnected,
	currency: storeCurrency,
} = window.puWCAdmin || {};

const CONNECT_ERROR_MESSAGES = {
	missing_params: __(
		'The connection request was missing required information. Please try again.',
		'product-upload'
	),
	state_mismatch: __(
		'The connection security check failed. Please start the connection again.',
		'product-upload'
	),
	state_expired: __(
		'The connection link expired. Please try connecting again.',
		'product-upload'
	),
	exchange_unreachable: __(
		'Could not reach ProductUpload.co to complete the connection. Check your connection and try again.',
		'product-upload'
	),
	exchange_rejected: __(
		'ProductUpload.co rejected the connection. Please try again.',
		'product-upload'
	),
};

/**
 * Parse raw text into an array of distinct, valid URLs.
 */
const parseUrls = (raw) => {
	const urlRegex = /(https?:\/\/[^\s,]+)/g;
	const matches = raw.match(urlRegex) || [];
	return matches
		.map((u) => u.replace(/^["']|["']$|,$/g, '').trim())
		.filter((u) => {
			try {
				new URL(u);
				return true;
			} catch {
				return false;
			}
		});
};

/**
 * Strip protocol and www from a URL for display.
 */
const displayDomain = (raw) =>
	raw.replace(/^https?:\/\//, '').replace(/^www\./, '');

// apiFetch throws "The response is not a valid JSON response" when the WP REST
// request came back as non-JSON - almost always a gateway/PHP timeout on a slow
// import (the server didn't finish before the host killed the request, so there
// is no error body to read). Map that raw string to a clear, actionable message;
// otherwise surface the real server error message as-is.
const friendlyImportError = (err) => {
	const msg = err?.message || '';
	if (/valid JSON|Unexpected token|JSON\.parse|<!DOCTYPE/i.test(msg)) {
		return __(
			'The import timed out before the server finished. Please try again - if it keeps happening your host may be limiting long requests (raise PHP max_execution_time).',
			'product-upload'
		);
	}
	return msg || __('Import failed.', 'product-upload');
};

// ---------------------------------------------------------------------------
// Root App component
// ---------------------------------------------------------------------------

const App = () => {
	useCrispChat();

	const [status, setStatus] = useState(
		initialNeedsConnect
			? 'needs-connect'
			: initialConnected
				? 'connected'
				: 'loading'
	);
	const [connectionData, setConnectionData] = useState(
		initialConnectionData || {}
	);
	const [provisionError, setProvisionError] = useState(null);

	const fetchStatus = () => {
		setStatus('loading');
		setProvisionError(null);

		apiFetch({ path: '/product-upload/v1/status' })
			.then((data) => {
				if (data.connected) {
					setConnectionData(data);
					setStatus('connected');
				} else {
					setProvisionError(
						data.error ||
						__(
							'Could not connect to ProductUpload.co.',
							'product-upload'
						)
					);
					setStatus('error');
				}
			})
			.catch((err) => {
				setProvisionError(
					err?.message ||
					__(
						'Could not connect to ProductUpload.co.',
						'product-upload'
					)
				);
				setStatus('error');
			});
	};

	useEffect(() => {
		if (initialNeedsConnect) {
			return;
		}
		if (!initialConnected) {
			fetchStatus();
		}
	}, []); // eslint-disable-line react-hooks/exhaustive-deps

	if (status === 'needs-connect') {
		const errorMessage = oauthConnectError
			? CONNECT_ERROR_MESSAGES[oauthConnectError] ||
				__(
					'Could not complete the connection. Please try again.',
					'product-upload'
				)
			: null;

		return (
			<div className="pu-wc-page pu-wc-connect">
				<div style={{ maxWidth: 560, margin: '60px auto', textAlign: 'center' }}>
					<h1 style={{ marginBottom: 12 }}>
						{__(
							'Connect to Product Upload',
							'product-upload'
						)}
					</h1>
					<p style={{ marginBottom: 20, color: '#646970' }}>
						{__(
							'Authorize this WordPress site to import products through Product Upload. You will be redirected to productupload.co to complete the connection.',
							'product-upload'
						)}
					</p>
					<div
						style={{
							marginBottom: 24,
							padding: 16,
							background: '#f6f7f7',
							border: '1px solid #dcdcde',
							borderRadius: 6,
							textAlign: 'left',
							fontSize: 13,
							color: '#3c434a',
							lineHeight: 1.5,
						}}
					>
						<strong style={{ display: 'block', marginBottom: 6 }}>
							{__(
								'What is shared when you connect',
								'product-upload'
							)}
						</strong>
						<ul style={{ margin: '0 0 8px 18px', padding: 0 }}>
							<li>
								{__(
									'Your site URL and an opaque per-shop token (for authentication and billing).',
									'product-upload'
								)}
							</li>
							<li>
								{__(
									'On each import: the supplier URL you paste and your shop locale.',
									'product-upload'
								)}
							</li>
						</ul>
						{__(
							'No customer, order, or payment data is ever sent. You can disconnect at any time. See the ',
							'product-upload'
						)}
						<a href="https://productupload.co/privacy" target="_blank" rel="noopener noreferrer">
							{__('Privacy Policy', 'product-upload')}
						</a>
						{__(' and ', 'product-upload')}
						<a href="https://productupload.co/terms" target="_blank" rel="noopener noreferrer">
							{__('Terms of Service', 'product-upload')}
						</a>
						{'.'}
					</div>
					{errorMessage && (
						<div style={{ marginBottom: 16 }}>
							<Notice status="error" isDismissible={false}>
								{errorMessage}
							</Notice>
						</div>
					)}
					<a
						href={oauthConnectUrl}
						className="components-button is-primary"
						style={{ padding: '8px 24px', fontSize: 14 }}
					>
						{__(
							'Connect Product Upload',
							'product-upload'
						)}
					</a>
				</div>
			</div>
		);
	}

	if (status === 'loading') {
		return (
			<div className="pu-wc-page pu-wc-loading">
				<Spinner />
				<p>
					{__(
						'Connecting to ProductUpload.co\u2026',
						'product-upload'
					)}
				</p>
			</div>
		);
	}

	if (status === 'error') {
		return (
			<div className="pu-wc-page pu-wc-error">
				<Notice status="error" isDismissible={false}>
					{provisionError}
				</Notice>
				<Button
					variant="secondary"
					onClick={fetchStatus}
					style={{ marginTop: '16px' }}
				>
					{__('Retry', 'product-upload')}
				</Button>
			</div>
		);
	}

	return (
		<ImportPage
			connectionData={connectionData}
			onStatusUpdate={(data) => setConnectionData(data)}
		/>
	);
};

// ---------------------------------------------------------------------------
// Import queue item - self-contained component that manages its own lifecycle.
// ---------------------------------------------------------------------------

const ImportQueueItem = ({
	url,
	active,
	onSettled,
	onRetry,
	onRemove,
	onStatusUpdate,
	// Fires when the upstream /import response indicates the URL was a
	// category page rather than a product. The parent opens the triage
	// modal; this card auto-settles silently (no error, no success render —
	// no credit was burned and no product was created).
	onCategory,
}) => {
	const [step, setStep] = useState('pending');
	const [error, setError] = useState(null);
	const [result, setResult] = useState(null);
	const isMounted = useRef(true);
	const isProcessing = useRef(false);

	useEffect(() => {
		isMounted.current = true;
		return () => {
			isMounted.current = false;
		};
	}, []);

	const runImport = useCallback(async () => {
		if (!isMounted.current || isProcessing.current) {
			return;
		}
		isProcessing.current = true;
		setError(null);
		setStep('importing');

		try {
			const data = await apiFetch({
				path: '/product-upload/v1/import',
				method: 'POST',
				data: { url },
			});

			if (!isMounted.current) {
				return;
			}

			// Category response: backend detected the URL was a listing page.
			// No credit burned, no product created. Hand the payload up so
			// the parent opens a triage modal, then settle this card quietly.
			if (data && data.kind === 'category') {
				if (typeof onCategory === 'function') {
					onCategory({
						sourceUrl: data.source_url || url,
						products: Array.isArray(data.products) ? data.products : [],
						remainingBalance:
							typeof data.remaining_balance === 'number'
								? data.remaining_balance
								: null,
					});
				}
				onSettled();
				onRemove();
				return;
			}

			setResult(data);
			setStep('completed');
			onSettled();

			// Refresh balance in the background.
			apiFetch({ path: '/product-upload/v1/status' })
				.then((s) => {
					if (s.connected) {
						onStatusUpdate(s);
					}
				})
				.catch(() => { });
		} catch (err) {
			if (!isMounted.current) {
				return;
			}
			setError(friendlyImportError(err));
			setStep('error');
			isProcessing.current = false;
			onSettled();
		}
	}, [url, onSettled, onStatusUpdate]);

	useEffect(() => {
		if (active && step === 'pending') {
			runImport();
		}
	}, [active, step, runImport]);

	const handleRetry = () => {
		isProcessing.current = false;
		setError(null);
		setStep('pending');
		onRetry();
	};

	/* --- Pending --- */
	if (step === 'pending') {
		return (
			<div className="pu-wc-queue-item pu-wc-queue-item--pending">
				<div className="pu-wc-queue-item__icon">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						fill="none"
						viewBox="0 0 24 24"
						strokeWidth={1.5}
						stroke="currentColor"
						width="20"
						height="20"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
						/>
					</svg>
				</div>
				<div className="pu-wc-queue-item__info">
					<span className="pu-wc-queue-item__status">
						{__('Pending', 'product-upload')}
					</span>
					<span className="pu-wc-queue-item__url" title={url}>
						{url}
					</span>
				</div>
				<div className="pu-wc-queue-item__actions">
					<Button
						variant="tertiary"
						size="small"
						onClick={onRemove}
						label={__('Remove', 'product-upload')}
					>
						&times;
					</Button>
				</div>
			</div>
		);
	}

	/* --- Importing --- */
	if (step === 'importing') {
		return (
			<div className="pu-wc-queue-item pu-wc-queue-item--importing">
				<div className="pu-wc-queue-item__icon">
					<Spinner />
				</div>
				<div className="pu-wc-queue-item__info">
					<span className="pu-wc-queue-item__status">
						{__(
							'Importing\u2026',
							'product-upload'
						)}
					</span>
					<span className="pu-wc-queue-item__url" title={url}>
						{url}
					</span>
				</div>
			</div>
		);
	}

	/* --- Error --- */
	if (step === 'error') {
		return (
			<div className="pu-wc-queue-item pu-wc-queue-item--error">
				<div className="pu-wc-queue-item__icon">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						fill="none"
						viewBox="0 0 24 24"
						strokeWidth={2}
						stroke="currentColor"
						width="20"
						height="20"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
						/>
					</svg>
				</div>
				<div className="pu-wc-queue-item__info">
					<span className="pu-wc-queue-item__status">{error}</span>
					<span className="pu-wc-queue-item__url" title={url}>
						{url}
					</span>
				</div>
				<div className="pu-wc-queue-item__actions">
					<Button variant="secondary" size="small" onClick={handleRetry}>
						{__('Retry', 'product-upload')}
					</Button>
					<Button
						variant="tertiary"
						size="small"
						onClick={onRemove}
						label={__('Remove', 'product-upload')}
					>
						&times;
					</Button>
				</div>
			</div>
		);
	}

	/* --- Completed --- */
	return (
		<div className="pu-wc-queue-item pu-wc-queue-item--completed">
			{result?.thumbnail_url ? (
				<div className="pu-wc-queue-item__thumb">
					<img src={result.thumbnail_url} alt="" />
				</div>
			) : (
				<div className="pu-wc-queue-item__icon">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						fill="none"
						viewBox="0 0 24 24"
						strokeWidth={2}
						stroke="currentColor"
						width="20"
						height="20"
					>
						<path
							strokeLinecap="round"
							strokeLinejoin="round"
							d="m4.5 12.75 6 6 9-13.5"
						/>
					</svg>
				</div>
			)}
			<div className="pu-wc-queue-item__info">
				<span className="pu-wc-queue-item__status">
					{result?.product_name ||
						__('Product imported', 'product-upload')}
				</span>
				<span className="pu-wc-queue-item__url" title={url}>
					{url}
				</span>
			</div>
			{result?.edit_url && (
				<div className="pu-wc-queue-item__actions">
					<Button variant="secondary" size="small" href={result.edit_url} target="_blank">
						{__('View', 'product-upload')}
					</Button>
				</div>
			)}
		</div>
	);
};

// ---------------------------------------------------------------------------
// Settings modal (tabbed - AI Enhancements, Localization, Data Sourcing)
// ---------------------------------------------------------------------------

const SETTINGS_TABS = [
	{ id: 'ai', label: 'AI Enhancements' },
	{ id: 'locale', label: 'Localization' },
	{ id: 'sourcing', label: 'Data Sourcing' },
	{ id: 'import_defaults', label: 'Import defaults' },
];


const SettingsModal = ({ onClose }) => {
	const [tab, setTab] = useState('ai');
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState(null);
	const [success, setSuccess] = useState(false);
	const [advancedOpen, setAdvancedOpen] = useState(false);
	const [showTitleEx, setShowTitleEx] = useState(false);
	const [categories, setCategories] = useState([]);

	// Settings state.
	const [settings, setSettings] = useState({
		preferred_ai: 'balanced',
		title_ai_enabled: true,
		example_titles: '',
		description_ai_enabled: true,
		description_tone: 'expert',
		description_max_sentences: 3,
		generate_specifications_table: true,
		description_spec_mode: 'extensive',
		description_spec_format: 'table',
		example_specifications: '',
		generate_tags: true,
		generate_variants: true,
		variant_detection_mode: 'balanced',
		custom_ai_prompt: '',
		locale_language: '',
		locale_country: '',
		locale_unit: 'metric',
		additional_settings: {
			always_scrape_products: false,
			send_custom_cookies: false,
			custom_cookie_value: '',
			ignore_search_engine_results: false,
			product_status: 'draft',
			import_brand: false,
			use_global_attributes: false,
			track_stock: false,
			stock_quantity: 1,
			convert_images: false,
			rename_image_files: false,
			// Pricing
			price_multiplier: 1,
			auto_cost_price: false,
			auto_compare_at_price: false,
			compare_at_multiplier: 1.2,
			price_rounding: 'none',
			min_price: 0,
		},
	});

	// Load settings on mount.
	useEffect(() => {
		apiFetch({ path: '/product-upload/v1/settings' })
			.then((data) => {
				if (data && typeof data === 'object') {
					setSettings((prev) => ({
						...prev,
						...data,
						additional_settings: {
							...prev.additional_settings,
							...(data.additional_settings || {}),
						},
					}));
				}
			})
			.catch(() => { })
			.finally(() => setLoading(false));
	}, []);

	useEffect(() => {
		apiFetch({ path: '/product-upload/v1/categories' })
			.then((data) => {
				if (Array.isArray(data)) {
					setCategories(data);
				}
			})
			.catch(() => { });
	}, []);

	const updateField = (key, value) => {
		setSettings((prev) => ({ ...prev, [key]: value }));
	};

	const updateAdditional = (key, value) => {
		setSettings((prev) => ({
			...prev,
			additional_settings: {
				...prev.additional_settings,
				[key]: value,
			},
		}));
	};

	const handleSave = async () => {
		setSaving(true);
		setError(null);
		setSuccess(false);

		try {
			await apiFetch({
				path: '/product-upload/v1/settings',
				method: 'POST',
				data: settings,
			});
			onClose();
		} catch (err) {
			setError(
				err?.message ||
				__('Could not save settings.', 'product-upload')
			);
		} finally {
			setSaving(false);
		}
	};

	// --- Tab content renderers ---

	const renderAiTab = () => {
		const a = settings.additional_settings || {};
		const titleMode = settings.title_ai_enabled ? 'rewrite' : 'keep';
		const descMode = settings.description_ai_enabled ? 'rewrite' : 'keep';
		const keepLayout = a.description_keep_layout === true;
		// switching to Keep keeps the layout
		const setDescMode = (mode) => {
			updateField('description_ai_enabled', mode === 'rewrite');
			if (mode === 'keep') {
				updateAdditional('description_keep_layout', true);
				updateAdditional('description_keep_fill_thin', true);
			}
		};
		const tagsMode = settings.generate_tags ? 'generate' : 'off';
		const textRules = a.text_rules || {};
		const replacements = Array.isArray(textRules.replacements)
			? textRules.replacements
			: [];
		const banned = Array.isArray(textRules.banned) ? textRules.banned : [];
		const setTextRules = (patch) =>
			updateAdditional('text_rules', { replacements, banned, ...patch });
		const titleExVisible = showTitleEx || !!settings.example_titles;
		return (
			<div className="pu-wc-settings__section">
				<style>{CARD_CSS}</style>

				<FieldCard
					accent="#5c6ac4"
					icon={titleIcon}
					label={__('Title', 'product-upload')}
					onToggle={() =>
						updateField('title_ai_enabled', !settings.title_ai_enabled)
					}
					summary={
						titleMode === 'keep'
							? __("Uses the supplier's title", 'product-upload')
							: __('AI rewrites for a consistent format', 'product-upload')
					}
					control={
						<SourceSegment
							value={titleMode}
							onChange={(v) =>
								updateField('title_ai_enabled', v === 'rewrite')
							}
							options={[
								{ label: __('Keep', 'product-upload'), value: 'keep' },
								{ label: __('Rewrite', 'product-upload'), value: 'rewrite' },
							]}
						/>
					}
				>
					{titleMode === 'rewrite' && (
						<div className="pu-fcard__body">
							{titleExVisible ? (
								<>
									<div className="pu-fcard__reveal-head">
										<span>
											{__('Your title examples', 'product-upload')}
										</span>
										<Button
											variant="link"
											onClick={() => {
												setShowTitleEx(false);
												updateField('example_titles', '');
											}}
										>
											{__('Remove', 'product-upload')}
										</Button>
									</div>
									<TextareaControl
										value={settings.example_titles || ''}
										onChange={(val) =>
											updateField('example_titles', val)
										}
										rows={3}
										placeholder={
											'Apple MacBook Pro 16" Laptop, M1 Max, Silver\nSony WH-1000XM4 Wireless Headphones, Black'
										}
									/>
								</>
							) : (
								<Button
									variant="link"
									icon={plus}
									onClick={() => setShowTitleEx(true)}
								>
									{__('Add title examples to guide formatting', 'product-upload')}
								</Button>
							)}
						</div>
					)}
				</FieldCard>

				<FieldCard
					accent="#7b50ff"
					icon={postContent}
					label={__('Description', 'product-upload')}
					onToggle={() => setDescMode(descMode === 'keep' ? 'rewrite' : 'keep')}
					summary={
						descMode === 'keep'
							? __("Use the supplier's description as-is", 'product-upload')
							: __('AI writes a new one, pick a layout', 'product-upload')
					}
					control={
						<SourceSegment
							value={descMode}
							onChange={setDescMode}
							options={[
								{ label: __('Keep', 'product-upload'), value: 'keep' },
								{ label: __('Rewrite', 'product-upload'), value: 'rewrite' },
							]}
						/>
					}
				>
					{descMode === 'keep' && !keepLayout && (
						<div className="pu-fcard__body">
							<p className="pu-wc-dlb-hint">
								{__("New: keep the supplier's text and still use your layout, with specs, images and your own text around it.", 'product-upload')}
							</p>
							<Button
								variant="secondary"
								size="small"
								onClick={() => updateAdditional('description_keep_layout', true)}
							>
								{__('Use my layout', 'product-upload')}
							</Button>
						</div>
					)}
					{(descMode === 'rewrite' || keepLayout) && (
						<div className="pu-fcard__body">
							{<DescriptionLayoutField
									keep={descMode === 'keep'}
									value={a.description_layout}
									onChange={(layout) => {
										updateAdditional('description_layout', layout);
										updateAdditional(
											'description_layout_look',
											'clean'
										);
									}}
								/>}
							<CheckboxControl
								className="pu-fcard__body-check"
								label={__('Show sections as separate tabs', 'product-upload')}
								help={
									a.description_layout_expandable_sections
										? __('Each section gets its own tab on the product page, between Description and Additional information.', 'product-upload')
										: __('Every section is written into the product description one after another.', 'product-upload')
								}
								checked={
									a.description_layout_expandable_sections === true
								}
								onChange={(val) =>
									updateAdditional(
										'description_layout_expandable_sections',
										val
									)
								}
							/>
							{descMode === 'keep' && (
								<CheckboxControl
									className="pu-fcard__body-check"
									label={__("If the supplier's description is missing or very short, write one", 'product-upload')}
									help={__('Uses only facts from the product page. Nothing is invented.', 'product-upload')}
									checked={a.description_keep_fill_thin === true}
									onChange={(val) =>
										updateAdditional('description_keep_fill_thin', val)
									}
								/>
							)}
						</div>
					)}
				</FieldCard>

				<FieldCard
					accent="#0d9488"
					icon={tagIcon}
					label={__('Tags', 'product-upload')}
					onToggle={() =>
						updateField('generate_tags', !settings.generate_tags)
					}
					summary={
						tagsMode === 'off'
							? __('No AI-generated tags', 'product-upload')
							: __('Generated from product details', 'product-upload')
					}
					control={
						<SourceSegment
							value={tagsMode}
							onChange={(v) =>
								updateField('generate_tags', v === 'generate')
							}
							options={[
								{ label: __('Off', 'product-upload'), value: 'off' },
								{ label: __('Generate', 'product-upload'), value: 'generate' },
							]}
						/>
					}
				/>

				<div className="pu-fcard" style={{ '--accent': '#757575' }}>
					<button
						type="button"
						className="pu-fcard__head pu-adv"
						aria-expanded={advancedOpen}
						onClick={() => setAdvancedOpen((o) => !o)}
					>
						<span className="pu-fcard__chip pu-fcard__chip--muted">
							<Icon icon={cog} />
						</span>
						<span className="pu-fcard__meta">
							<span className="pu-fcard__label">
								{__('Advanced', 'product-upload')}
							</span>
							<span className="pu-fcard__sub">
								{__('Quality, brand, variants, custom instructions', 'product-upload')}
							</span>
						</span>
						<span className="pu-adv__edit">
							{advancedOpen
								? __('Hide', 'product-upload')
								: __('Show', 'product-upload')}
						</span>
						<span className="pu-adv__chev">
							<Icon icon={chevronDown} />
						</span>
					</button>
					{advancedOpen && (
						<div className="pu-adv-body">
							<div className="pu-adv-group">
								<span className="pu-adv-group__title">
									{__('AI quality', 'product-upload')}
								</span>
								<SourceSegment
									full
									value={settings.preferred_ai || 'balanced'}
									onChange={(v) => updateField('preferred_ai', v)}
									options={[
										{ label: __('Speed', 'product-upload'), value: 'speed' },
										{ label: __('Balanced', 'product-upload'), value: 'balanced' },
										{ label: __('Quality', 'product-upload'), value: 'quality' },
									]}
								/>
							</div>
							<CheckboxControl
								label={__('Hide supplier brand', 'product-upload')}
								help={__('Removes the original brand from titles, descriptions, specs and tags for a white-label listing.', 'product-upload')}
								checked={!!settings.hide_brand}
								onChange={(val) => updateField('hide_brand', val)}
							/>
								<CheckboxControl
									label={__('Only use facts from the supplier page', 'product-upload')}
									help={__("Strict mode: the AI won't add specs or claims it can't find on the page. Turn on if you've seen invented details.", 'product-upload')}
									checked={a.strict_fidelity === true}
									onChange={(val) =>
										updateAdditional('strict_fidelity', val)
									}
								/>
							
							<CheckboxControl
								label={__('Import variants (if present)', 'product-upload')}
								checked={settings.generate_variants}
								onChange={(val) =>
									updateField('generate_variants', val)
								}
							/>
							{settings.generate_variants && (
								<SelectControl
									label={__('Variant detection mode', 'product-upload')}
									value={
										settings.variant_detection_mode || 'balanced'
									}
									options={[
										{ label: __('Balanced', 'product-upload'), value: 'balanced' },
										{ label: __('Aggressive', 'product-upload'), value: 'aggressive' },
									]}
									onChange={(val) =>
										updateField('variant_detection_mode', val)
									}
									help={
										settings.variant_detection_mode ===
										'aggressive'
											? __('Finds the maximum number of variants; may include inferred options.', 'product-upload')
											: ''
									}
								/>
							)}
							<CheckboxControl
								label={__('Estimate weight when none is present', 'product-upload')}
								help={__('When the supplier page has no weight, estimate a realistic one. Turn off to leave it blank.', 'product-upload')}
								checked={a.estimate_weight !== false}
								onChange={(val) =>
									updateAdditional('estimate_weight', val)
								}
							/>
							<CheckboxControl
								label={__('Generate SEO meta title and description', 'product-upload')}
								help={__('Writes SEO meta for Yoast or Rank Math when active. Turn off to leave them blank.', 'product-upload')}
								checked={a.generate_seo !== false}
								onChange={(val) =>
									updateAdditional('generate_seo', val)
								}
							/>
								<>
									<div className="pu-adv-group">
										<span className="pu-adv-group__title">
											{__('Rename words', 'product-upload')}
										</span>
										{replacements.map((r, i) => (
											<div className="pu-rename-row" key={i}>
												<div className="pu-rename-row__field">
													<TextControl
														placeholder={__('OldBrand', 'product-upload')}
														value={r.from || ''}
														onChange={(v) =>
															setTextRules({
																replacements:
																	replacements.map(
																		(x, j) =>
																			j === i
																				? { ...x, from: v }
																				: x
																	),
															})
														}
													/>
												</div>
												<span className="pu-rename-row__arrow">
													→
												</span>
												<div className="pu-rename-row__field">
													<TextControl
														placeholder={__('MyBrand', 'product-upload')}
														value={r.to || ''}
														onChange={(v) =>
															setTextRules({
																replacements:
																	replacements.map(
																		(x, j) =>
																			j === i
																				? { ...x, to: v }
																				: x
																	),
															})
														}
													/>
												</div>
												<Button
													variant="tertiary"
													isDestructive
													label={__('Remove', 'product-upload')}
													onClick={() =>
														setTextRules({
															replacements:
																replacements.filter(
																	(_, j) => j !== i
																),
														})
													}
												>
													✕
												</Button>
											</div>
										))}
										<Button
											variant="link"
											icon={plus}
											onClick={() =>
												setTextRules({
													replacements: [
														...replacements,
														{ from: '', to: '' },
													],
												})
											}
										>
											{__('Add a word to rename', 'product-upload')}
										</Button>
										<p className="pu-adv-note">
											{__('Swaps every match in titles, descriptions, specs and SEO.', 'product-upload')}
										</p>
									</div>
									<div className="pu-adv-group">
										<FormTokenField
											label={__('Remove words', 'product-upload')}
											value={banned}
											onChange={(v) =>
												setTextRules({ banned: v })
											}
											placeholder={__('e.g. supplier name', 'product-upload')}
										/>
										<p className="pu-adv-note">
											{__('Deleted wherever it appears in the listing text.', 'product-upload')}
										</p>
									</div>
								</>
							
							<TextareaControl
								label={__('Extra instructions (tone, wording, rules)', 'product-upload')}
								help={__('Applies to how the AI writes. Description structure is set in the Layout builder.', 'product-upload')}
								value={settings.custom_ai_prompt || ''}
								onChange={(val) =>
									updateField('custom_ai_prompt', val)
								}
								rows={3}
								placeholder={__('e.g. British English, no medical claims, mention free shipping', 'product-upload')}
							/>
						</div>
					)}
				</div>
			</div>
		);
	};

	const renderLocaleTab = () => (
		<div className="pu-wc-settings__section">
			<style>{CARD_CSS}</style>
			<FieldCard
				accent="#2c6ecb"
				icon={globe}
				label={__('Language & region', 'product-upload')}
				summary={__('How AI content reads and localizes', 'product-upload')}
			>
				<div className="pu-fcard__body">
					<SelectControl
						label={__('Language', 'product-upload')}
						value={settings.locale_language || ''}
						options={[
							{ label: __('Select a language', 'product-upload'), value: '' },
							...LANGUAGES.map((lang) => ({ label: lang, value: lang })),
						]}
						onChange={(val) => updateField('locale_language', val)}
						help={__('Applies to titles, descriptions, tags, and specifications.', 'product-upload')}
					/>
					<SelectControl
						label={__('Country', 'product-upload')}
						value={settings.locale_country || ''}
						options={[
							{ label: __('Select a country', 'product-upload'), value: '' },
							...COUNTRIES.map((c) => ({ label: c.name, value: c.code })),
						]}
						onChange={(val) => updateField('locale_country', val)}
						help={__('Used to localize currency formatting and regional conventions.', 'product-upload')}
					/>
					<SelectControl
						label={__('Measurement unit', 'product-upload')}
						value={settings.locale_unit || 'metric'}
						options={[
							{ label: __('Imperial (lb, in, oz)', 'product-upload'), value: 'imperial' },
							{ label: __('Metric (kg, cm, g)', 'product-upload'), value: 'metric' },
						]}
						onChange={(val) => updateField('locale_unit', val)}
						help={__('Determines whether dimensions and weights use metric or imperial.', 'product-upload')}
					/>
				</div>
			</FieldCard>
		</div>
	);

	const renderSourcingTab = () => {
		const a = settings.additional_settings || {};
		const onOff = [
			{ label: __('Off', 'product-upload'), value: 'off' },
			{ label: __('On', 'product-upload'), value: 'on' },
		];
		return (
			<div className="pu-wc-settings__section">
				<style>{CARD_CSS}</style>
				<FieldCard
					accent="#2c6ecb"
					icon={globe}
					label={__('Always scrape products', 'product-upload')}
					onToggle={() =>
						updateAdditional('always_scrape_products', !a.always_scrape_products)
					}
					summary={
						a.always_scrape_products
							? __('Always fetches full details from the page', 'product-upload')
							: __('Only scrapes when data is missing', 'product-upload')
					}
					control={
						<SourceSegment
							value={a.always_scrape_products ? 'on' : 'off'}
							onChange={(v) =>
								updateAdditional('always_scrape_products', v === 'on')
							}
							options={onOff}
						/>
					}
				/>
				<FieldCard
					accent="#7b50ff"
					icon={cog}
					label={__('Send custom cookies', 'product-upload')}
					onToggle={() =>
						updateAdditional('send_custom_cookies', !a.send_custom_cookies)
					}
					summary={
						a.send_custom_cookies
							? __('Attached to scrape requests', 'product-upload')
							: __('For login-gated supplier pages', 'product-upload')
					}
					control={
						<SourceSegment
							value={a.send_custom_cookies ? 'on' : 'off'}
							onChange={(v) =>
								updateAdditional('send_custom_cookies', v === 'on')
							}
							options={onOff}
						/>
					}
				>
					{a.send_custom_cookies && (
						<div className="pu-fcard__body">
							<TextControl
								label={__('Cookie value', 'product-upload')}
								value={a.custom_cookie_value || ''}
								onChange={(val) =>
									updateAdditional('custom_cookie_value', val)
								}
								placeholder="session_id=abc123; token=xyz789"
							/>
						</div>
					)}
				</FieldCard>
				<FieldCard
					accent="#0d9488"
					icon={seen}
					label={__('Skip search-engine images', 'product-upload')}
					onToggle={() =>
						updateAdditional(
							'ignore_search_engine_results',
							!a.ignore_search_engine_results
						)
					}
					summary={
						a.ignore_search_engine_results
							? __('Only uses supplier-page images', 'product-upload')
							: __('May add related images from search', 'product-upload')
					}
					control={
						<SourceSegment
							value={a.ignore_search_engine_results ? 'on' : 'off'}
							onChange={(v) =>
								updateAdditional(
									'ignore_search_engine_results',
									v === 'on'
								)
							}
							options={onOff}
						/>
					}
				/>
			</div>
		);
	};

	const renderImportDefaultsTab = () => {
		const a = settings.additional_settings || {};
		const status = a.product_status === 'publish' ? 'publish' : 'draft';
		return (
			<div className="pu-wc-settings__section">
				<style>{CARD_CSS}</style>
				<FieldCard
					accent="#2c6ecb"
					icon={published}
					label={__('Product status', 'product-upload')}
					onToggle={() =>
						updateAdditional(
							'product_status',
							status === 'publish' ? 'draft' : 'publish'
						)
					}
					summary={
						status === 'publish'
							? __('Imported products go live immediately', 'product-upload')
							: __('Imported products are saved as drafts', 'product-upload')
					}
					control={
						<SourceSegment
							value={status}
							onChange={(v) => updateAdditional('product_status', v)}
							options={[
								{ label: __('Draft', 'product-upload'), value: 'draft' },
								{ label: __('Published', 'product-upload'), value: 'publish' },
							]}
						/>
					}
				/>

				<FieldCard
					accent="#0d9488"
					icon={currencyDollar}
					label={__('Pricing', 'product-upload')}
					summary={__('Multiplier, rounding, cost and sale prices', 'product-upload')}
				>
					<div className="pu-fcard__body">
						<CheckboxControl
							label={__('Convert supplier prices to your store currency', 'product-upload')}
							help={__('Converts the source price (e.g. CNY, EUR) to your WooCommerce currency using daily exchange rates, before the multiplier. Needs a detected source currency.', 'product-upload')}
							checked={!!a.convert_currency}
							onChange={(val) => updateAdditional('convert_currency', val)}
						/>
						{renderPricingSection()}
						<CheckboxControl
							label={__('Charge tax on these products', 'product-upload')}
							help={__('Sets the tax status to Taxable. Turn off to set None (no tax collected).', 'product-upload')}
							checked={a.tax_status !== 'none'}
							onChange={(val) =>
								updateAdditional('tax_status', val ? 'taxable' : 'none')
							}
						/>
					</div>
				</FieldCard>

				<FieldCard
					accent="#d97706"
					icon={box}
					label={__('Track stock quantity', 'product-upload')}
					onToggle={() =>
						updateAdditional('track_stock', !a.track_stock)
					}
					summary={
						a.track_stock
							? __('Stock management on, with a starting quantity', 'product-upload')
							: __('No stock management', 'product-upload')
					}
					control={
						<SourceSegment
							value={a.track_stock ? 'on' : 'off'}
							onChange={(v) =>
								updateAdditional('track_stock', v === 'on')
							}
							options={[
								{ label: __('Off', 'product-upload'), value: 'off' },
								{ label: __('On', 'product-upload'), value: 'on' },
							]}
						/>
					}
				>
					{a.track_stock && (
						<div className="pu-fcard__body">
							<TextControl
								label={__('Quantity of items', 'product-upload')}
								type="number"
								value={String(a.stock_quantity ?? 1)}
								onChange={(val) =>
									updateAdditional(
										'stock_quantity',
										Math.max(0, parseInt(val, 10) || 0)
									)
								}
								min={0}
							/>
							<SelectControl
								label={__('When out of stock', 'product-upload')}
								value={a.backorders || 'no'}
								options={[
									{ label: __('Do not allow', 'product-upload'), value: 'no' },
									{ label: __('Allow, but notify customer', 'product-upload'), value: 'notify' },
									{ label: __('Allow', 'product-upload'), value: 'yes' },
								]}
								onChange={(val) => updateAdditional('backorders', val)}
							/>
						</div>
					)}
				</FieldCard>

				<FieldCard
					accent="#757575"
					icon={tool}
					label={__('Product options', 'product-upload')}
					summary={__('Categories, brand, images and attributes', 'product-upload')}
				>
					<div className="pu-fcard__body">
						{categories.length > 0 && (
							<FormTokenField
								label={__('Assign to categories', 'product-upload')}
								value={(a.default_categories || [])
									.map((id) => {
										const c = categories.find(
											(x) => x.id === id
										);
										return c && (c.path || c.name);
									})
									.filter(Boolean)}
								suggestions={categories.map(
									(c) => c.path || c.name
								)}
								onChange={(labels) =>
									updateAdditional(
										'default_categories',
										labels
											.map(
												(label) =>
													categories.find(
														(c) =>
															(c.path || c.name) ===
															label
													)?.id
											)
											.filter(Boolean)
									)
								}
							/>
						)}
						<CheckboxControl
							label={__('Import brand automatically', 'product-upload')}
							help={__('Detect and assign the product brand from the supplier page.', 'product-upload')}
							checked={!!a.import_brand}
							onChange={(val) => updateAdditional('import_brand', val)}
						/>
						<CheckboxControl
							label={__('Convert images to JPG', 'product-upload')}
							help={__('Re-encode imported images to JPG instead of the original format (often WebP). Turn on if you sync to a platform that rejects WebP, such as Odoo.', 'product-upload')}
							checked={!!a.convert_images}
							onChange={(val) => updateAdditional('convert_images', val)}
						/>
						<CheckboxControl
							label={__('Rename image files to product name', 'product-upload')}
							help={__('Saves images to the Media Library as product-name-1.jpg instead of the supplier\'s filename.', 'product-upload')}
							checked={!!a.rename_image_files}
							onChange={(val) => updateAdditional('rename_image_files', val)}
						/>
						<CheckboxControl
							label={__('Use SKU as barcode (GTIN)', 'product-upload')}
							help={__('When the supplier has no barcode, store the SKU in the product GTIN field.', 'product-upload')}
							checked={!!a.barcode_from_sku}
							onChange={(val) => updateAdditional('barcode_from_sku', val)}
						/>
						<CheckboxControl
							label={__('Use global attributes for variations (advanced)', 'product-upload')}
							help={__('Register variation axes (Color, Size, …) as global WooCommerce attributes so they can power layered-nav filters. Creates entries in Products → Attributes; these cannot be easily undone.', 'product-upload')}
							checked={!!a.use_global_attributes}
							onChange={(val) =>
								updateAdditional('use_global_attributes', val)
							}
						/>
					</div>
				</FieldCard>
			</div>
		);
	};

	// Pricing controls — multiplier, cost mapping, on-sale (compare-at),
	// rounding, min floor. Live preview helpers next to each input so the
	// merchant sees what their settings produce for a $10 reference price.
	const pricingValues = settings.additional_settings ?? {};
	const priceMultiplier = parseFloat(pricingValues.price_multiplier ?? 1) || 0;
	const compareAtMultiplier = parseFloat(pricingValues.compare_at_multiplier ?? 1.2) || 0;
	const minPriceVal = parseFloat(pricingValues.min_price ?? 0) || 0;
	const roundingMode = pricingValues.price_rounding ?? 'none';

	const previewRound = (value) => {
		if (!isFinite(value) || value <= 0) return value;
		switch (roundingMode) {
			case 'whole': return Math.ceil(value);
			case '99': return Math.floor(value) + 0.99;
			case '95': return Math.floor(value) + 0.95;
			case '90': return Math.floor(value) + 0.9;
			case '49': {
				const f = Math.floor(value);
				for (const c of [f + 0.49, f + 0.99]) if (c >= value) return c;
				return f + 1.49;
			}
			default: return Math.round(value * 100) / 100;
		}
	};

	const formatMoney = (v) => `$${Number(v).toFixed(2)}`;

	const renderPricingSection = () => {
		const ref = 10; // reference price for previews
		let selling = ref * priceMultiplier;
		if (minPriceVal > 0 && selling < minPriceVal) selling = minPriceVal;
		selling = previewRound(selling);
		const compareAt = previewRound(selling * compareAtMultiplier);
		const onSalePercentOff = compareAt > selling
			? Math.round((1 - selling / compareAt) * 100)
			: 0;

		return (
			<>
				<TextControl
					label={__('Price multiplier', 'product-upload')}
					help={`${formatMoney(ref)} × ${priceMultiplier || 1} = ${formatMoney(selling)}`}
					type="number"
					value={String(pricingValues.price_multiplier ?? 1)}
					onChange={(val) => updateAdditional(
						'price_multiplier',
						Math.max(0, parseFloat(val) || 0)
					)}
					min={0}
					step={0.1}
				/>

				<SelectControl
					label={__('Price rounding', 'product-upload')}
					help={__('Round each computed price to a retail-friendly endpoint.', 'product-upload')}
					value={roundingMode}
					options={[
						{ label: __('No rounding (2 decimals)', 'product-upload'), value: 'none' },
						{ label: __('Whole dollar (ceil)', 'product-upload'), value: 'whole' },
						{ label: __('Nearest .99', 'product-upload'), value: '99' },
						{ label: __('Nearest .95', 'product-upload'), value: '95' },
						{ label: __('Nearest .90', 'product-upload'), value: '90' },
						{ label: __('Nearest .49 / .99', 'product-upload'), value: '49' },
					]}
					onChange={(val) => updateAdditional('price_rounding', val)}
				/>

				<TextControl
					label={__('Minimum price', 'product-upload')}
					help={__('Floor for the computed price. 0 disables. Useful when low-cost upstream products would otherwise become unsellable after the multiplier.', 'product-upload')}
					type="number"
					value={String(pricingValues.min_price ?? 0)}
					onChange={(val) => updateAdditional(
						'min_price',
						Math.max(0, parseFloat(val) || 0)
					)}
					min={0}
					step={0.5}
				/>

				<CheckboxControl
					label={__('Map source price to cost price', 'product-upload')}
					help={__('Stores the original scraped price as the WooCommerce cost meta (_cost_price). Used by cost-of-goods plugins to compute margin.', 'product-upload')}
					checked={!!pricingValues.auto_cost_price}
					onChange={(val) => updateAdditional('auto_cost_price', val)}
				/>

				<CheckboxControl
					label={__('Automatically mark imported products as on sale', 'product-upload')}
					help={__('Sets a higher Regular price so the Sale price appears discounted.', 'product-upload')}
					checked={!!pricingValues.auto_compare_at_price}
					onChange={(val) => updateAdditional('auto_compare_at_price', val)}
				/>
				{!!pricingValues.auto_compare_at_price && (
					<div className="pu-wc-settings__sub">
						<TextControl
							label={__('Compare-at multiplier', 'product-upload')}
							help={`${__('Sale', 'product-upload')} ${formatMoney(selling)} → ${__('Regular', 'product-upload')} ${formatMoney(compareAt)} (${__('appears', 'product-upload')} ${onSalePercentOff}% ${__('off', 'product-upload')})`}
							type="number"
							value={String(pricingValues.compare_at_multiplier ?? 1.2)}
							onChange={(val) => updateAdditional(
								'compare_at_multiplier',
								Math.max(1, parseFloat(val) || 1)
							)}
							min={1.01}
							step={0.1}
						/>
					</div>
				)}
			</>
		);
	};

	return (
		<Modal
			title={__('Import Settings', 'product-upload')}
			onRequestClose={onClose}
			className="pu-wc-settings-modal"
			isFullScreen={false}
		>
			{loading ? (
				<div className="pu-wc-settings__loading">
					<Spinner />
				</div>
			) : (
				<div className="pu-wc-settings__body">
					{ /* Tab navigation */}
					<div className="pu-wc-settings__tabs">
						{SETTINGS_TABS.map((t) => (
							<button
								key={t.id}
								type="button"
								className={
									'pu-wc-settings__tab' +
									(tab === t.id
										? ' pu-wc-settings__tab--active'
										: '')
								}
								onClick={() => setTab(t.id)}
							>
								{t.label}
							</button>
						))}
					</div>

					{ /* Scrollable tab content */}
					<div className="pu-wc-settings__content">
						{tab === 'ai' && renderAiTab()}
						{tab === 'locale' && renderLocaleTab()}
						{tab === 'sourcing' && renderSourcingTab()}
						{tab === 'import_defaults' && renderImportDefaultsTab()}
					</div>

					{ /* Sticky footer */}
					<div className="pu-wc-settings__footer">
						{error && (
							<Notice
								status="error"
								isDismissible
								onRemove={() => setError(null)}
							>
								{error}
							</Notice>
						)}
						{success && (
							<Notice status="success" isDismissible={false}>
								{__(
									'Settings saved.',
									'product-upload'
								)}
							</Notice>
						)}
						<div className="pu-wc-settings__footer-actions">
							<Button variant="tertiary" onClick={onClose}>
								{__('Cancel', 'product-upload')}
							</Button>
							<Button
								variant="primary"
								onClick={handleSave}
								isBusy={saving}
								disabled={saving}
							>
								{saving
									? __(
										'Saving\u2026',
										'product-upload'
									)
									: __(
										'Save settings',
										'product-upload'
									)}
							</Button>
						</div>
					</div>
				</div>
			)}
		</Modal>
	);
};

// ---------------------------------------------------------------------------
// Pricing modal
// ---------------------------------------------------------------------------

const PRICING_PLANS = [
	{
		name: 'Shop',
		credits: '100 credits / month',
		price: '$9.99',
		perCredit: '~$0.10 per product',
		highlighted: false,
	},
	{
		name: 'Warehouse',
		credits: '300 credits / month',
		price: '$14.99',
		perCredit: '~$0.05 per product',
		highlighted: true,
	},
	{
		name: 'Factory',
		credits: '1,000 credits / month',
		price: '$29.99',
		perCredit: '~$0.03 per product',
		highlighted: false,
	},
	{
		name: 'Franchise',
		credits: '5,000 credits / month',
		price: '$69.99',
		perCredit: '~$0.014 per product',
		highlighted: false,
	},
];

const PricingModal = ({ onClose, onUpgrade, upgrading, currentPlan, isActivePlan, outOfCredits }) => {
	const currentLower = (currentPlan || '').toLowerCase();
	const title = outOfCredits
		? __("You're out of imports", 'product-upload')
		: isActivePlan
			? __('Change your plan', 'product-upload')
			: __('Choose your plan', 'product-upload');

	return (
		<Modal
			title={title}
			onRequestClose={onClose}
			className="pu-wc-pricing-modal"
		>
			<div className="pu-wc-pricing">
				{outOfCredits && (
					<p className="pu-wc-pricing__ooc">
						{__(
							'Upgrade to keep importing - your settings and suppliers stay exactly as you left them.',
							'product-upload'
						)}
					</p>
				)}
				<p className="pu-wc-pricing__subtitle">
					{__(
						'Every plan includes AI product details and all WooCommerce integrations. No contracts, cancel anytime.',
						'product-upload'
					)}
				</p>

				<div className="pu-wc-pricing__grid">
					{PRICING_PLANS.map((plan) => {
						const isCurrent =
							isActivePlan &&
							!!currentLower &&
							plan.name.toLowerCase() === currentLower;

						// price-ascending order
						let ctaLabel;
						if (!isActivePlan) {
							ctaLabel = __('Get started', 'product-upload');
						} else if (isCurrent) {
							ctaLabel = __('Current plan', 'product-upload');
						} else {
							const currentIdx = PRICING_PLANS.findIndex(
								(p) => p.name.toLowerCase() === currentLower
							);
							const targetIdx = PRICING_PLANS.findIndex(
								(p) => p.name === plan.name
							);
							ctaLabel =
								currentIdx === -1 || targetIdx < currentIdx
									? __('Downgrade', 'product-upload')
									: __('Upgrade', 'product-upload');
						}

						return (
							<div
								key={plan.name}
								className={`pu-wc-pricing__card${plan.highlighted ? ' pu-wc-pricing__card--featured' : ''}${isCurrent ? ' pu-wc-pricing__card--current' : ''}`}
							>
								{isCurrent ? (
									<div className="pu-wc-pricing__most-popular">
										{__('CURRENT PLAN', 'product-upload')}
									</div>
								) : plan.highlighted ? (
									<div className="pu-wc-pricing__most-popular">
										{__('MOST POPULAR', 'product-upload')}
									</div>
								) : null}

								<div className="pu-wc-pricing__card-header">
									<span className="pu-wc-pricing__plan-name">{plan.name}</span>
								</div>

								<div className="pu-wc-pricing__credits">
									<p>{plan.credits}</p>
									<p>{__('Renews monthly', 'product-upload')}</p>
								</div>

								<div className="pu-wc-pricing__price-block">
									<div className="pu-wc-pricing__price-row">
										<span className="pu-wc-pricing__price">{plan.price}</span>
										<span className="pu-wc-pricing__period">
											{__('/ mo', 'product-upload')}
										</span>
									</div>
									<p className="pu-wc-pricing__per-credit">{plan.perCredit}</p>
								</div>

								<button
									className={`pu-wc-pricing__cta${isCurrent ? ' pu-wc-pricing__cta--current' : ''}`}
									onClick={isCurrent ? undefined : () => onUpgrade(plan.name)}
									disabled={upgrading || isCurrent}
								>
									{upgrading && !isCurrent
										? __('Loading\u2026', 'product-upload')
										: ctaLabel}
								</button>
							</div>
						);
					})}
				</div>

				<div className="pu-wc-pricing__dfy">
					<div>
						<strong>{__('Rather we set it up for you?', 'product-upload')}</strong>
						<p>
							{__(
								'Our team can import your catalogue or set up your whole store. Catalogue setup from $750.',
								'product-upload'
							)}
						</p>
					</div>
					<a
						className="components-button is-secondary"
						href={doneForYouUrl('pricing-modal')}
						target="_blank"
						rel="noopener noreferrer"
					>
						{__('See done-for-you setup', 'product-upload')}
					</a>
				</div>
			</div>
		</Modal>
	);
};

// ---------------------------------------------------------------------------
// Supplier sidebar
// ---------------------------------------------------------------------------

const SupplierSidebar = ({ onScanSupplier, onScanNew, onOpenOverview, onOpenHistory, refreshKey }) => {
	const [suppliers, setSuppliers] = useState(null); // null = loading

	useEffect(() => {
		apiFetch({ path: '/product-upload/v1/suppliers' })
			.then((res) => setSuppliers(res.data ?? []))
			.catch(() => setSuppliers([]));
	}, [refreshKey]);

	return (
		<div className="pu-wc-suppliers">
			<div className="pu-wc-suppliers__header">
				<h2 className="pu-wc-suppliers__title">
					{__('Suppliers', 'product-upload')}
				</h2>
				<Button variant="link" onClick={onScanNew} size="small">
					{__('Add new supplier', 'product-upload')}
				</Button>
			</div>
			{suppliers === null ? (
				<SupplierRowsSkeleton />
			) : suppliers.length === 0 ? (
				<BulkImportEmpty onAdd={onScanNew} onScan={onScanSupplier} />
			) : (
				<ul className="pu-wc-suppliers__list">
					{suppliers.map((supplier) => {
						const domain = displayDomain(supplier.url || '');
						const open = () => onOpenOverview(supplier);
						return (
							<li
								key={supplier.id}
								className="pu-wc-suppliers__item"
								onClick={open}
								role="button"
								tabIndex={0}
								onKeyDown={(e) => e.key === 'Enter' && open()}
							>
								<div className="pu-wc-suppliers__favicon">
									<img
										src={`https://icons.duckduckgo.com/ip2/${domain}.ico`}
										alt=""
										onError={(e) => { e.currentTarget.style.display = 'none'; }}
									/>
								</div>
								<div className="pu-wc-suppliers__info">
									<div className="pu-wc-suppliers__domain">{domain}</div>
									{supplier.product_count != null && (
										<div className="pu-wc-suppliers__count">
											{supplier.product_count} {supplier.product_count === 1 ? 'product' : 'products'}
										</div>
									)}
								</div>
								<span
									className="pu-wc-suppliers__menu"
									onClick={(e) => e.stopPropagation()}
									onKeyDown={(e) => e.stopPropagation()}
									role="presentation"
								>
									<DropdownMenu
										icon={moreVertical}
										label={__('Supplier actions', 'product-upload')}
										popoverProps={{ placement: 'bottom-end' }}
										controls={[
											{ title: __('Overview', 'product-upload'), onClick: () => onOpenOverview(supplier) },
											{ title: __('Bulk import', 'product-upload'), onClick: () => onScanSupplier(supplier.url) },
											{ title: __('Import history', 'product-upload'), onClick: () => onOpenHistory(supplier) },
										]}
									/>
								</span>
							</li>
						);
					})}
				</ul>
			)}
		</div>
	);
};

// ---------------------------------------------------------------------------
// Import page (shown once auto-provisioned)
// ---------------------------------------------------------------------------

const ImportPage = ({ connectionData, onStatusUpdate }) => {
	const [multiUrlMode, setMultiUrlMode] = useState(false);
	const [multiUrlText, setMultiUrlText] = useState('');
	const [queue, setQueue] = useState([]);
	const [settledIds, setSettledIds] = useState(new Set());
	// job-backed imports
	const [importsRefresh, setImportsRefresh] = useState(0);
	const [importsView, setImportsView] = useState('active');
	const [importsStarting, setImportsStarting] = useState(false);
	// just-created job, shown before the list has it
	const [importsNewJob, setImportsNewJob] = useState(null);
	const [showPairModal, setShowPairModal] = useState(false);
	const [error, setError] = useState(null);
	const [upgrading, setUpgrading] = useState(false);
	const [showSettings, setShowSettings] = useState(false);
	const [showPricingModal, setShowPricingModal] = useState(false);
	const [showScanModal, setShowScanModal] = useState(false);
	const [scanInitialUrl, setScanInitialUrl] = useState('');
	// '' opens on the supplier list
	const openBulk = (target) => {
		setScanInitialUrl(target || '');
		setShowScanModal(true);
	};
	const [showAccessControl, setShowAccessControl] = useState(false);
	// Category triage state - opened when an upstream /import call resolves
	// to a category-page response instead of a single product. The backend
	// burns no credit; we refund our optimistic local decrement, show the
	// modal, and re-queue whatever the user picks via the normal handleBulkImport.
	const [categoryProducts, setCategoryProducts] = useState([]);
	const [categorySourceUrl, setCategorySourceUrl] = useState('');
	const [categoryJobId, setCategoryJobId] = useState(null);
	const [categoryModalOpen, setCategoryModalOpen] = useState(false);

	// Local balance - decremented optimistically, synced when the server responds.
	const [balance, setBalance] = useState(connectionData.balance ?? 0);

	const [supplierRefreshKey, setSupplierRefreshKey] = useState(0);

	// supplier modal (null = closed)
	const [overviewSupplier, setOverviewSupplier] = useState(null);
	const [overviewTab, setOverviewTab] = useState('overview');

	const handleStatusUpdate = useCallback(
		(data) => {
			setBalance(data.balance ?? 0);
			setSupplierRefreshKey((k) => k + 1);
			onStatusUpdate(data);
		},
		[onStatusUpdate]
	);

	// Fetch the latest balance from the server on mount so the count is
	// always up-to-date (e.g. after upgrading or purchasing credits).
	useEffect(() => {
		apiFetch({ path: '/product-upload/v1/status' })
			.then((data) => {
				if (data.connected) {
					handleStatusUpdate(data);
				}
			})
			.catch(() => { });
	}, []); // eslint-disable-line react-hooks/exhaustive-deps

	// Warn on page unload while imports are running.
	useEffect(() => {
		const hasActive = queue.some((q) => !settledIds.has(q.id));
		if (!hasActive) {
			return;
		}
		const handler = (e) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener('beforeunload', handler);
		return () => window.removeEventListener('beforeunload', handler);
	}, [queue, settledIds]);

	// "Active plan" means a paid plan that's active — not the free tier.
	// The server returns billing_status='active' for free shops too (the
	// shop_plan row is created with plan='free' status='ACTIVE' at signup),
	// so we have to gate on plan_name as well or every free-tier merchant
	// sees "Manage plan" + "Change plan" buttons for a plan they don't have.
	const planName = (connectionData.plan_name || '').toLowerCase();
	const isActivePlan =
		(connectionData.billing_status || '').toLowerCase() === 'active' &&
		planName !== '' &&
		planName !== 'free';

	// same activity_log events as the Shopify app
	const trackActivity = (event, metadata) => {
		apiFetch({
			path: '/product-upload/v1/track',
			method: 'POST',
			data: { event, metadata },
		}).catch(() => { });
	};

	const openPricing = (source) => {
		setShowPricingModal(true);
		if (!isActivePlan) {
			trackActivity('ooc_shown', { source, balance, plan: planName || null });
		}
	};

	// free: pop once on >0 to 0, not load-at-0
	const prevBalanceRef = useRef(balance);
	const oocAutoShownRef = useRef(false);
	useEffect(() => {
		const prev = prevBalanceRef.current;
		prevBalanceRef.current = balance;
		if (isActivePlan || oocAutoShownRef.current) return;
		if (prev > 0 && balance <= 0) {
			oocAutoShownRef.current = true;
			openPricing('auto_depleted');
		}
	}, [balance]); // eslint-disable-line react-hooks/exhaustive-deps

	const handleUpgrade = async (plan) => {
		setUpgrading(true);
		try {
			// string plan = modal pick
			const planQuery =
				typeof plan === 'string' && plan
					? '?plan=' + encodeURIComponent(plan)
					: '';
			// Already subscribed? Send them through the Stripe portal (which
			// supports plan changes) so we don't stack a second subscription.
			// plan => portal deep-link
			const path = isActivePlan
				? '/product-upload/v1/portal-url' + planQuery
				: '/product-upload/v1/upgrade-url' + planQuery;
			const data = await apiFetch({ path });
			const url = data.url || data.confirmation_url;
			if (url) {
				window.location.href = url;
			}
		} catch (err) {
			setError(
				err?.message ||
				__(
					'Could not start upgrade. Please try again.',
					'product-upload'
				)
			);
		} finally {
			setUpgrading(false);
		}
	};

	const handleManagePlan = async () => {
		setUpgrading(true);
		try {
			const data = await apiFetch({
				path: '/product-upload/v1/portal-url',
			});
			if (data.url) {
				window.location.href = data.url;
			}
		} catch (err) {
			setError(
				err?.message ||
				__(
					'Could not open billing portal. Please try again.',
					'product-upload'
				)
			);
		} finally {
			setUpgrading(false);
		}
	};

	/**
	 * An item is "active" (allowed to start importing) when fewer than
	 * CONCURRENCY_LIMIT non-settled items precede it in the queue.
	 */
	const isItemActive = (id, index) => {
		if (settledIds.has(id)) {
			return false;
		}
		let preceding = 0;
		for (let i = 0; i < index; i++) {
			if (!settledIds.has(queue[i].id)) {
				preceding++;
			}
		}
		return preceding < CONCURRENCY_LIMIT;
	};

	const markSettled = useCallback((id) => {
		setSettledIds((prev) => {
			const next = new Set(prev);
			next.add(id);
			return next;
		});
	}, []);

	const markUnsettled = useCallback((id) => {
		setSettledIds((prev) => {
			const next = new Set(prev);
			next.delete(id);
			return next;
		});
	}, []);

	const removeFromQueue = useCallback((id) => {
		setQueue((prev) => prev.filter((q) => q.id !== id));
		setSettledIds((prev) => {
			const next = new Set(prev);
			next.delete(id);
			return next;
		});
	}, []);

	// job-backed import from URLs
	const enqueueJob = useCallback(async (urls, source = 'manual') => {
		const list = (Array.isArray(urls) ? urls : [urls])
			.map((u) => String(u).trim())
			.filter(Boolean);
		if (list.length === 0) {
			return;
		}
		setShowScanModal(false);
		setCategoryModalOpen(false);
		setImportsView('active');
		setImportsStarting(true);
		try {
			const res = await apiFetch({
				path: '/product-upload/v1/import-jobs',
				method: 'POST',
				data: { urls: list, source },
			});
			setError(null);
			if (res?.job_id) {
				setImportsNewJob(res.job_id);
			}
			setImportsRefresh((k) => k + 1);
		} catch (err) {
			setError(
				err?.message ||
				__('Could not start import. Please try again.', 'product-upload')
			);
		} finally {
			setImportsStarting(false);
		}
	}, []);

	// --- Multi URL import ---
	const handleMultiImport = () => {
		const urls = parseUrls(multiUrlText);
		if (urls.length === 0) {
			setError(
				__(
					'No valid URLs found. Please check your input.',
					'product-upload'
				)
			);
			return;
		}
		if (balance < urls.length) {
			setError(
				`${__(
					'You only have',
					'product-upload'
				)} ${balance} ${__(
					'credits remaining but provided',
					'product-upload'
				)} ${urls.length} ${__('URLs.', 'product-upload')}`
			);
			return;
		}
		enqueueJob(urls, 'manual');
		setMultiUrlText('');
	};

	const handleCategoryDetected = useCallback((payload) => {
		// No refund needed: we no longer decrement optimistically on queue.
		// If the server volunteered an updated balance, mirror it down.
		if (typeof payload.remainingBalance === 'number') {
			setBalance(payload.remainingBalance);
		}
		setCategoryProducts(payload.products || []);
		setCategorySourceUrl(payload.sourceUrl || '');
		// card path has the source job
		setCategoryJobId(payload.jobId || null);
		setCategoryModalOpen(true);
	}, []);

	const cancelCategoryJob = (jobId) => {
		if (!jobId) {
			return;
		}
		apiFetch({
			path: `/product-upload/v1/import-jobs/${encodeURIComponent(jobId)}/cancel`,
			method: 'POST',
		}).catch(() => { });
	};

	const handleCategoryConfirm = (urls) => {
		setCategoryModalOpen(false);
		// Mirror the bulk import flow exactly so quota gating and dedup stay
		// consistent. handleBulkImport already handles the balance decrement
		// and queue insertion.
		handleBulkImport(urls);
		// retire source category job
		cancelCategoryJob(categoryJobId);
		setCategoryJobId(null);
	};

	const handleBulkImport = (urls) => {
		if (!urls || urls.length === 0) {
			return;
		}
		if (balance < urls.length) {
			setError(
				`${__(
					'You only have',
					'product-upload'
				)} ${balance} ${__(
					'credits remaining but selected',
					'product-upload'
				)} ${urls.length} ${__('products.', 'product-upload')}`
			);
			return;
		}
		enqueueJob(urls, 'manual');
	};

	return (
		<div className="pu-wc-page pu-wc-import">
			{ /* Account bar */}
			<div className="pu-wc-import__account-bar">
				<div className="pu-wc-import__account-info">
					<span className="pu-wc-import__status-dot" />
					{connectionData.plan_name && (
						<span className="pu-wc-import__plan">
							{connectionData.plan_name.charAt(0).toUpperCase() + connectionData.plan_name.slice(1)}
						</span>
					)}
					<ImportsControl
						balance={balance}
						isActivePlan={isActivePlan}
						onUpgrade={() => openPricing('imports_control')}
					/>
				</div>
				<div className="pu-wc-import__account-actions">
					{isActivePlan && (
						<Button
							variant="tertiary"
							onClick={handleManagePlan}
							size="small"
							isBusy={upgrading}
							disabled={upgrading}
						>
							{__('Manage plan', 'product-upload')}
						</Button>
					)}
					<Button
						variant="tertiary"
						onClick={() => setShowPairModal(true)}
						size="small"
					>
						{__('Integrations', 'product-upload')}
					</Button>
					<Button
						variant="tertiary"
						onClick={() => openPricing('upgrade_button')}
						size="small"
					>
						{isActivePlan
							? __('Change plan \u2197', 'product-upload')
							: __('Upgrade plan \u2197', 'product-upload')}
					</Button>
					<DropdownMenu
						icon={moreVertical}
						label={__('More options', 'product-upload')}
						controls={[
							{
								title: __('Access control', 'product-upload'),
								onClick: () => setShowAccessControl(true),
							},
							{
								title: __('Get support', 'product-upload'),
								onClick: () => { window.location.href = 'mailto:hello@productupload.co'; },
							},
						]}
					/>
				</div>
			</div>

			<SentimentBanner />

			<div className="pu-wc-layout">
				<div className="pu-wc-layout__main">
					{ /* Title + description */}
					<h1 className="pu-wc-import__title">
						{__('Import Product', 'product-upload')}
					</h1>
					<p className="pu-wc-import__description">
						{__(
							"Paste a product URL or search a supported marketplace, and AI builds a full WooCommerce product: title, description, images, variants, and SEO.",
							'product-upload'
						)}
					</p>

					{ /* Import form */}
					<ImportUrlForm
						balance={balance}
						onCustomize={() => setShowSettings(true)}
						onEnqueue={enqueueJob}
						onBrowse={openBulk}
						onError={setError}
						onOutOfCredits={
							isActivePlan ? undefined : () => openPricing('import_attempt')
						}
					/>

					{error && (
						<Notice
							status="error"
							isDismissible
							onRemove={() => setError(null)}
						>
							{error}
						</Notice>
					)}

					{ /* Imports */}
					<ImportsSection
						refreshKey={importsRefresh}
						view={importsView}
						setView={setImportsView}
						starting={importsStarting}
						newJobId={importsNewJob}
						onCategory={handleCategoryDetected}
						onBalance={setBalance}
						onUpgrade={handleUpgrade}
						onCustomize={() => setShowSettings(true)}
						onRetry={(url) => enqueueJob([url], 'manual')}
					/>
				</div>

				<div className="pu-wc-layout__sidebar">
					<SupplierSidebar
						refreshKey={supplierRefreshKey}
						onScanSupplier={openBulk}
						onScanNew={() => openBulk('')}
						onOpenOverview={(supplier) => {
							setOverviewTab('overview');
							setOverviewSupplier(supplier);
						}}
						onOpenHistory={(supplier) => {
							setOverviewTab('history');
							setOverviewSupplier(supplier);
						}}
					/>
				</div>
			</div>

			{ /* Pricing modal */}
			{showPricingModal && (
				<PricingModal
					onClose={() => setShowPricingModal(false)}
					onUpgrade={(plan) => {
						setShowPricingModal(false);
						if (!isActivePlan) {
							trackActivity('ooc_upgrade_clicked', {
								to_plan: String(plan).toLowerCase(),
								interval: 'monthly',
								balance,
							});
						}
						handleUpgrade(plan);
					}}
					upgrading={upgrading}
					currentPlan={connectionData.plan_name}
					isActivePlan={isActivePlan}
					outOfCredits={!isActivePlan && balance <= 0}
				/>
			)}

			{ /* Settings modal */}
			{showSettings && (
				<SettingsModal onClose={() => setShowSettings(false)} />
			)}

			{ /* Pair Chrome extension modal */}
			{showPairModal && (
				<ExtensionPairModal
					onClose={() => setShowPairModal(false)}
					onSetUpAssistant={() => { setShowPairModal(false); setShowAccessControl(true); }}
				/>
			)}

			{ /* Supplier modal */}
			{overviewSupplier && (
				<SupplierModal
					supplier={overviewSupplier}
					initialTab={overviewTab}
					onClose={() => setOverviewSupplier(null)}
					onConnectExtension={() => { setOverviewSupplier(null); setShowPairModal(true); }}
					onBulkImport={(supplierUrl) => {
						setOverviewSupplier(null);
						openBulk(supplierUrl);
					}}
				/>
			)}

			{showScanModal && (
				<BulkImportModal
					onClose={() => setShowScanModal(false)}
					balance={balance}
					onImport={handleBulkImport}
					onCategoryFallback={(pageUrl) => {
						setShowScanModal(false);
						enqueueJob([pageUrl], 'category');
					}}
					onUpgrade={() => openPricing('bulk_browser')}
					initialUrl={scanInitialUrl}
					planName={planName}
					currency={storeCurrency}
				/>
			)}

			{ /* Category triage modal — surfaced when the upstream LLM
				 decides a pasted URL was a listing page rather than a
				 product. User picks which extracted products to import. */}
			{categoryModalOpen && (
				<CategoryTriageModal
					sourceUrl={categorySourceUrl}
					products={categoryProducts}
					balance={balance}
					onClose={() => setCategoryModalOpen(false)}
					onImport={handleCategoryConfirm}
					onUpgrade={handleUpgrade}
					upgrading={upgrading}
					planName={planName}
				/>
			)}

			{ /* Access Control modal */}
			{showAccessControl && (
				<Modal
					title={__('Access Control', 'product-upload')}
					onRequestClose={() => setShowAccessControl(false)}
					isFullScreen
				>
					<AccessControlModal />
				</Modal>
			)}
		</div>
	);
};

// ---------------------------------------------------------------------------
// Access Control modal content
// ---------------------------------------------------------------------------

const ACTIVITY_LIMIT = 25;

const acFormatDate = (dateStr) => {
	const date = new Date(dateStr);
	return date.toLocaleDateString('en-US', {
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	});
};

const acFormatDateShort = (dateStr) => {
	const date = new Date(dateStr);
	return date.toLocaleDateString('en-US', {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	});
};

const acEventLabel = (event) => {
	const labels = {
		product_imported: __('Product imported', 'product-upload'),
		plan_changed: __('Plan changed', 'product-upload'),
		user_login: __('User login', 'product-upload'),
		user_invited: __('User invited', 'product-upload'),
		api_key_used: __('API key used', 'product-upload'),
	};
	return labels[event] || event?.replace(/_/g, ' ') || '-';
};

const acEventBadgeClass = (event) => {
	switch (event) {
		case 'product_imported': return 'pu-ac-badge--info';
		case 'plan_changed': return 'pu-ac-badge--success';
		case 'user_invited': return 'pu-ac-badge--attention';
		default: return 'pu-ac-badge--default';
	}
};

const acEventDetails = (event, meta) => {
	if (!meta) return '-';
	switch (event) {
		case 'product_imported': {
			const parts = [];
			if (meta.url) parts.push(meta.url);
			if (meta.balance_before != null && meta.balance_after != null) {
				parts.push(`${meta.balance_before} → ${meta.balance_after} credits`);
			}
			return parts.join(' | ') || '-';
		}
		case 'plan_changed': {
			const plan = meta.from_plan
				? `${meta.from_plan} → ${meta.to_plan}`
				: meta.to_plan ?? '-';
			const credits =
				meta.credits_added != null ? ` (+${meta.credits_added} credits)` : '';
			return `${plan}${credits}`;
		}
		case 'user_login':
			return meta.method ? `Method: ${meta.method}` : '-';
		case 'user_invited':
			return meta.email ? `${meta.email} as ${meta.role ?? 'admin'}` : '-';
		case 'api_key_used':
			return meta.endpoint || '-';
		default:
			return '-';
	}
};

const AI_SETTINGS_URL = 'https://productupload.co/settings/ai-assistants';
const AI_GUIDE_URL = 'https://productupload.co/ai-assistants';
const isRealEmail = (email) => !!email && !/@wc\.productupload\.co$/i.test(email);

// verify a real email to sign in on the web
const AiAssistantsBlock = ({ storeEmail, onVerified }) => {
	const [email, setEmail] = useState(isRealEmail(storeEmail) ? storeEmail : '');
	const [code, setCode] = useState('');
	const [step, setStep] = useState('email');
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState(null);

	const post = async (path, data, next) => {
		setBusy(true);
		setError(null);
		try {
			await apiFetch({ path: `/product-upload/v1/users/${path}`, method: 'POST', data });
			setStep(next);
			if (next === 'done') onVerified();
		} catch (err) {
			setError(err?.message || __('Something went wrong. Please try again.', 'product-upload'));
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="pu-ac-users__block">
			<h3 className="pu-ac-users__heading">
				{__('AI assistants', 'product-upload')}
			</h3>
			<p className="pu-ac-users__hint">
				{__('Let Claude or ChatGPT import products and edit your catalogue for you. First confirm an email you can sign in to Product Upload with.', 'product-upload')}
			</p>
			{error && (
				<Notice status="error" isDismissible onRemove={() => setError(null)}>
					{error}
				</Notice>
			)}
			<p className="pu-ac-users__hint">
				<a href={AI_GUIDE_URL} target="_blank" rel="noreferrer">
					{__('How to connect an assistant', 'product-upload')}
				</a>
			</p>
			{step === 'email' && (
				<div className="pu-ac-users__inline-form">
					<TextControl
						value={email}
						onChange={setEmail}
						placeholder="you@example.com"
						type="email"
						hideLabelFromVision
						label={__('Your email', 'product-upload')}
					/>
					<Button
						variant="primary"
						isBusy={busy}
						disabled={busy || !email.trim()}
						onClick={() => post('verify-email', { email: email.trim() }, 'code')}
					>
						{__('Send code', 'product-upload')}
					</Button>
				</div>
			)}
			{step === 'code' && (
				<>
					<p className="pu-ac-users__hint">
						{sprintf(
							__('We sent a code to %s. Type it here; do not use the button in the email.', 'product-upload'),
							email.trim()
						)}
					</p>
					<div className="pu-ac-users__inline-form">
						<TextControl
							value={code}
							onChange={setCode}
							placeholder="123456"
							hideLabelFromVision
							label={__('Code', 'product-upload')}
						/>
						<Button
							variant="primary"
							isBusy={busy}
							disabled={busy || !code.trim()}
							onClick={() => post('verify-email/confirm', { email: email.trim(), code: code.trim() }, 'done')}
						>
							{__('Confirm', 'product-upload')}
						</Button>
						<Button variant="tertiary" onClick={() => { setCode(''); setStep('email'); }}>
							{__('Back', 'product-upload')}
						</Button>
					</div>
				</>
			)}
			{step === 'done' && (
				<>
					<Notice status="success" isDismissible={false}>
						{sprintf(
							__('Confirmed. Add Product Upload as a connector in Claude or ChatGPT, then sign in with %s when asked.', 'product-upload'),
							email.trim()
						)}
					</Notice>
					<Button variant="link" href={AI_SETTINGS_URL} target="_blank" rel="noreferrer">
						{__('Manage connected assistants', 'product-upload')}
					</Button>
				</>
			)}
		</div>
	);
};

/** Users & sharing card inside the Access Control modal. */
const UsersSection = () => {
	const [users, setUsers] = useState([]);
	const [installerEmail, setInstallerEmail] = useState(null);
	const [aiAssistants, setAiAssistants] = useState(false);
	const [currentProfileId, setCurrentProfileId] = useState(null);
	const [isLoading, setIsLoading] = useState(true);
	const [inviteEmail, setInviteEmail] = useState('');
	const [isInviting, setIsInviting] = useState(false);
	const [editingEmail, setEditingEmail] = useState(false);
	const [newOwnerEmail, setNewOwnerEmail] = useState('');
	const [isUpdatingEmail, setIsUpdatingEmail] = useState(false);
	const [error, setError] = useState(null);
	const [success, setSuccess] = useState(null);
	const [removeTarget, setRemoveTarget] = useState(null);
	const [isRemoving, setIsRemoving] = useState(false);

	const fetchUsers = useCallback(async () => {
		setIsLoading(true);
		try {
			const data = await apiFetch({ path: '/product-upload/v1/users' });
			setUsers(data.users || []);
			setInstallerEmail(data.installerEmail || null);
			setAiAssistants(!!data.aiAssistants);
			setCurrentProfileId(data.currentProfileId || null);
		} catch {
			setError(__('Failed to load users', 'product-upload'));
		} finally {
			setIsLoading(false);
		}
	}, []);

	useEffect(() => { fetchUsers(); }, [fetchUsers]);

	const handleInvite = async () => {
		if (!inviteEmail.trim()) return;
		setIsInviting(true);
		setError(null);
		setSuccess(null);
		try {
			await apiFetch({
				path: '/product-upload/v1/users/invite',
				method: 'POST',
				data: { email: inviteEmail.trim(), role: 'admin' },
			});
			setSuccess(`Invitation sent to ${inviteEmail.trim()}`);
			setInviteEmail('');
			fetchUsers();
		} catch (err) {
			setError(err?.message || __('Failed to invite user', 'product-upload'));
		} finally {
			setIsInviting(false);
		}
	};

	const handleRemove = async () => {
		if (!removeTarget) return;
		setIsRemoving(true);
		setError(null);
		setSuccess(null);
		try {
			await apiFetch({
				path: '/product-upload/v1/users/remove',
				method: 'POST',
				data: { profile_id: removeTarget.profile_id },
			});
			setSuccess(`Removed ${removeTarget.email}`);
			setRemoveTarget(null);
			fetchUsers();
		} catch (err) {
			setError(err?.message || __('Failed to remove user', 'product-upload'));
		} finally {
			setIsRemoving(false);
		}
	};

	const handleUpdateEmail = async () => {
		if (!newOwnerEmail.trim()) return;
		setIsUpdatingEmail(true);
		setError(null);
		setSuccess(null);
		try {
			await apiFetch({
				path: '/product-upload/v1/users/update-email',
				method: 'POST',
				data: { email: newOwnerEmail.trim() },
			});
			setSuccess(__('Owner email updated successfully', 'product-upload'));
			setEditingEmail(false);
			setNewOwnerEmail('');
			fetchUsers();
		} catch (err) {
			setError(err?.message || __('Failed to update email', 'product-upload'));
		} finally {
			setIsUpdatingEmail(false);
		}
	};

	if (isLoading && users.length === 0) return <Spinner />;

	const owner = users.find((u) => u.role === 'owner');

	return (
		<div className="pu-ac-users">
			{error && (
				<Notice status="error" isDismissible onRemove={() => setError(null)}>
					{error}
				</Notice>
			)}
			{success && (
				<Notice status="success" isDismissible onRemove={() => setSuccess(null)}>
					{success}
				</Notice>
			)}

			{/* Store email */}
			<div className="pu-ac-users__block">
				<h3 className="pu-ac-users__heading">
					{__('Store email', 'product-upload')}
				</h3>
				<p className="pu-ac-users__hint">
					{__('This is the primary email associated with your store. Login emails and notifications are sent here.', 'product-upload')}
				</p>
				{editingEmail ? (
					<div className="pu-ac-users__inline-form">
						<TextControl
							value={newOwnerEmail}
							onChange={setNewOwnerEmail}
							placeholder={installerEmail || owner?.email || ''}
							type="email"
							hideLabelFromVision
							label={__('New email', 'product-upload')}
						/>
						<Button variant="primary" onClick={handleUpdateEmail} isBusy={isUpdatingEmail} disabled={isUpdatingEmail}>
							{__('Save', 'product-upload')}
						</Button>
						<Button variant="tertiary" onClick={() => setEditingEmail(false)}>
							{__('Cancel', 'product-upload')}
						</Button>
					</div>
				) : (
					<div className="pu-ac-users__email-display">
						<strong>{installerEmail || owner?.email || __('Not set', 'product-upload')}</strong>
						<Button
							variant="link"
							onClick={() => {
								setNewOwnerEmail(installerEmail || owner?.email || '');
								setEditingEmail(true);
							}}
						>
							{__('Change', 'product-upload')}
						</Button>
					</div>
				)}
			</div>

			{aiAssistants && (
				<AiAssistantsBlock storeEmail={installerEmail || owner?.email || ''} onVerified={fetchUsers} />
			)}

			{/* Team members */}
			<div className="pu-ac-users__block">
				<h3 className="pu-ac-users__heading">
					{__('Team members', 'product-upload')}
				</h3>
				<div className="pu-ac-users__inline-form">
					<TextControl
						value={inviteEmail}
						onChange={setInviteEmail}
						placeholder="colleague@example.com"
						type="email"
						hideLabelFromVision
						label={__('Invite email', 'product-upload')}
					/>
					<Button variant="primary" onClick={handleInvite} isBusy={isInviting} disabled={isInviting || !inviteEmail.trim()}>
						{__('Invite', 'product-upload')}
					</Button>
				</div>

				{users.length > 0 && (
					<table className="pu-ac-users__table widefat striped">
						<thead>
							<tr>
								<th>{__('Email', 'product-upload')}</th>
								<th>{__('Role', 'product-upload')}</th>
								<th>{__('Added', 'product-upload')}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{users.map((user) => {
								const isCurrentUser = user.profile_id === currentProfileId;
								const canRemove = user.role !== 'owner' && !isCurrentUser;
								return (
									<tr key={user.membership_id}>
										<td>
											{user.email}
											{isCurrentUser && (
												<span className="pu-ac-users__you"> (you)</span>
											)}
										</td>
										<td>
											<span className={`pu-ac-badge ${user.role === 'owner' ? 'pu-ac-badge--info' : 'pu-ac-badge--success'}`}>
												{user.role}
											</span>
										</td>
										<td className="pu-ac-users__date">
											{acFormatDateShort(user.created_at)}
										</td>
										<td>
											{canRemove && (
												<Button variant="link" isDestructive onClick={() => setRemoveTarget(user)}>
													{__('Remove', 'product-upload')}
												</Button>
											)}
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				)}
			</div>

			{/* Remove confirmation */}
			{removeTarget && (
				<Modal
					title={__('Remove user', 'product-upload')}
					onRequestClose={() => setRemoveTarget(null)}
					size="small"
				>
					<p>
						{__('Are you sure you want to remove', 'product-upload')}{' '}
						<strong>{removeTarget.email}</strong>{' '}
						{__('from this store? They will lose access immediately.', 'product-upload')}
					</p>
					<div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
						<Button variant="primary" isDestructive onClick={handleRemove} isBusy={isRemoving} disabled={isRemoving}>
							{__('Remove', 'product-upload')}
						</Button>
						<Button variant="tertiary" onClick={() => setRemoveTarget(null)}>
							{__('Cancel', 'product-upload')}
						</Button>
					</div>
				</Modal>
			)}
		</div>
	);
};

/** Activity log section inside the Access Control modal. */
const ActivitySection = () => {
	const [logs, setLogs] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [hasMore, setHasMore] = useState(false);
	const [offset, setOffset] = useState(0);
	const [loadingMore, setLoadingMore] = useState(false);

	const fetchLogs = useCallback(async (currentOffset = 0, append = false) => {
		const setLoadState = currentOffset === 0 ? setIsLoading : setLoadingMore;
		setLoadState(true);
		try {
			const response = await apiFetch({
				path: `/product-upload/v1/activity?limit=${ACTIVITY_LIMIT}&offset=${currentOffset}`,
			});
			const data = response?.data ?? [];
			setLogs((prev) => (append ? [...prev, ...data] : data));
			setHasMore(data.length === ACTIVITY_LIMIT);
		} catch {
			if (!append) setLogs([]);
			setHasMore(false);
		} finally {
			setLoadState(false);
		}
	}, []);

	useEffect(() => { fetchLogs(0); }, [fetchLogs]);

	const loadMore = () => {
		const newOffset = offset + ACTIVITY_LIMIT;
		setOffset(newOffset);
		fetchLogs(newOffset, true);
	};

	if (isLoading) return <Spinner />;

	if (logs.length === 0) {
		return (
			<div className="pu-ac-activity__empty">
				<h3>{__('No activity yet', 'product-upload')}</h3>
				<p>{__('Credit usage from imports and other actions will appear here.', 'product-upload')}</p>
			</div>
		);
	}

	return (
		<div className="pu-ac-activity">
			<table className="pu-ac-activity__table widefat striped">
				<thead>
					<tr>
						<th>{__('Date', 'product-upload')}</th>
						<th>{__('Event', 'product-upload')}</th>
						<th>{__('Details', 'product-upload')}</th>
						<th>{__('Client', 'product-upload')}</th>
					</tr>
				</thead>
				<tbody>
					{logs.map((log) => {
						const meta = log.metadata ?? {};
						return (
							<tr key={log.id}>
								<td className="pu-ac-activity__date">{acFormatDate(log.created_at)}</td>
								<td>
									<span className={`pu-ac-badge ${acEventBadgeClass(log.event)}`}>
										{acEventLabel(log.event)}
									</span>
								</td>
								<td className="pu-ac-activity__details">{acEventDetails(log.event, meta)}</td>
								<td className="pu-ac-activity__client">{meta.client ?? '-'}</td>
							</tr>
						);
					})}
				</tbody>
			</table>

			{hasMore && (
				<div className="pu-ac-activity__footer">
					<Button variant="secondary" onClick={loadMore} isBusy={loadingMore} disabled={loadingMore}>
						{loadingMore
							? __('Loading…', 'product-upload')
							: __('Load more', 'product-upload')}
					</Button>
				</div>
			)}
			{!hasMore && logs.length > 0 && (
				<div className="pu-ac-activity__footer">
					<p className="pu-ac-activity__count">
						{`Showing all ${logs.length} entries`}
					</p>
				</div>
			)}
		</div>
	);
};

/** Full Access Control modal - Users & sharing + Activity log. */
const AccessControlModal = () => (
	<div className="pu-ac">
		{/* Users & sharing */}
		<div className="pu-ac__section">
			<div className="pu-ac__grid">
				<div className="pu-ac__sidebar">
					<h3>{__('Users & sharing', 'product-upload')}</h3>
					<p>
						{__('Manage who can use your credits and import using', 'product-upload')}{' '}
						<a href="https://productupload.co" target="_blank" rel="noreferrer">ProductUpload.co</a>
						{'. '}
						{__('Invite team members by email.', 'product-upload')}
					</p>
				</div>
				<div className="pu-ac__content">
					<UsersSection />
				</div>
			</div>
		</div>

		{/* Activity */}
		<div className="pu-ac__section">
			<div className="pu-ac__grid">
				<div className="pu-ac__sidebar">
					<h3>{__('Activity', 'product-upload')}</h3>
					<p>
						{__('Credit usage from imports, subscriptions, and other actions.', 'product-upload')}
					</p>
				</div>
				<div className="pu-ac__content">
					<ActivitySection />
				</div>
			</div>
		</div>
	</div>
);

export default App;
