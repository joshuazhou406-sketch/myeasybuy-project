/**
 * Supplier overview: an AI/probe-derived scorecard for one saved supplier
 * (platform, currency, catalog size, price range, trust, categories, site
 * facts, detection method, bulk-import features) plus a per-supplier locale
 * pin. Mirrors the Shopify app's SupplierOverviewTab: skeleton -> animated
 * "researching" overlay on a cold probe, error/retry, AI-accuracy footer.
 *
 * @package ProductUploadForWoocommerce
 */
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, SelectControl, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { clientSearchSuppliers } from './searchSuppliersClient';
import { Skel } from './Skeletons';
import { LOCALE_CURRENCIES, LOCALE_COUNTRIES, LOCALE_LANGUAGES, displayName, localeOptions } from './localeData';

const RESEARCH_PHASES = [
	'Searching the web for {host}…',
	'Reading {host}’s pages…',
	'Detecting platform, currency, and country…',
	'Checking what other stores have imported…',
	'Asking AI to summarise the site…',
	'Wrapping up the report…',
];
const PHASE_DURATION_MS = 4500;
const SKELETON_GRACE_MS = 3000;

const EXTRACTED_FIELDS_INLINE =
	'title, full description, photo gallery, price, variants, brand, SKU, specifications, category, and shipping dimensions.';

// inline glyph set
const GLYPHS = {
	product: 'M3 7.5l9-4.5 9 4.5v9l-9 4.5-9-4.5z M3 7.5l9 4.5 9-4.5 M12 12v9',
	cash: 'M3 6h18v12H3z M12 9.2a2.8 2.8 0 100 5.6 2.8 2.8 0 000-5.6',
	star: 'M12 3.2l2.5 5.3 5.8.8-4.2 4 1 5.7L12 16.4 6.9 19l1-5.7-4.2-4 5.8-.8z',
	store: 'M4 9h16l-1 11H5z M4 9l1-5h14l1 5 M9.5 13.5h5',
	order: 'M8 4h8v3H8z M16 5.2h2v14.8H6V5.2h2 M8.8 12l1.6 1.6L13.5 10.4',
	connect: 'M10 12.5a3 3 0 013-3h2.2a3 3 0 010 6H14 M14 11.5a3 3 0 01-3 3H8.8a3 3 0 010-6H10',
	delivery: 'M3 6.5h11v9H3z M14 9.5h3.5L21 13v2.5h-7 M7 18a2 2 0 100-4 2 2 0 000 4 M17.5 18a2 2 0 100-4 2 2 0 000 4',
	location: 'M12 21s6.5-6 6.5-10.5a6.5 6.5 0 10-13 0C5.5 15 12 21 12 21z M12 12.2a2.3 2.3 0 100-4.6 2.3 2.3 0 000 4.6',
	language: 'M12 3a9 9 0 100 18 9 9 0 000-18 M3.5 12h17 M12 3c2.4 2.6 2.4 15.4 0 18 M12 3c-2.4 2.6-2.4 15.4 0 18',
	markets: 'M6 8.5h12l-1 11.5H7z M9 8.5a3 3 0 016 0',
	magic: 'M5 4.5l1.1 2.9L9 8.5l-2.9 1.1L5 12.5 3.9 9.6 1 8.5l2.9-1.1z M16.5 12l.9 2.4 2.4.9-2.4.9-.9 2.4-.9-2.4-2.4-.9 2.4-.9z',
	clock: 'M12 3a9 9 0 100 18 9 9 0 000-18 M12 7.5V12l3 2',
};

function Glyph({ name, size = 18 }) {
	const d = GLYPHS[name];
	if (!d) {
		return null;
	}
	return (
		<svg width={size} height={size} viewBox="0 0 24 24" fill="none"
			stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"
			strokeLinejoin="round" aria-hidden="true">
			<path d={d} />
		</svg>
	);
}

export default function SupplierOverview({ supplier, onBulkImport, onConnectExtension }) {
	const [intel, setIntel] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const aliveRef = useRef(true);

	const hostname = useMemo(() => normaliseHostname(supplier?.url), [supplier?.url]);

	const load = useCallback(
		async ({ revalidate = false } = {}) => {
			if (!hostname) {
				return;
			}
			if (!revalidate) {
				setLoading(true);
				setError(null);
			}
			try {
				const body = await apiFetch({
					path: `/product-upload/v1/supplier-intel?hostname=${encodeURIComponent(hostname)}${revalidate ? '&refresh=1' : ''}`,
				});
				if (!aliveRef.current) {
					return;
				}
				if (body?.intel) {
					setIntel(body.intel);
				}
				if (!revalidate && body?.stale) {
					void load({ revalidate: true });
				}
			} catch (err) {
				if (aliveRef.current && !revalidate) {
					setError(err?.message || __('Failed to load', 'product-upload'));
				}
			} finally {
				if (aliveRef.current && !revalidate) {
					setLoading(false);
				}
			}
		},
		[hostname]
	);

	useEffect(() => {
		aliveRef.current = true;
		setIntel(null);
		void load();
		return () => { aliveRef.current = false; };
	}, [load]);

	const facts = intel?.ai_facts ?? {};
	const compat = intel
		? composeScrapeCompatibility({
			aiFacts: facts.scrape_compatibility,
			signals: intel?.scrape_signals ?? {},
			siteName: prettySiteName(supplier),
		})
		: null;
	const bulkBadge = intel ? bulkImportBadge({ hostname: supplier?.url, platform: facts.platform }) : null;
	const badges = [compat?.badge, bulkBadge].filter(Boolean);

	const name = capitalize(supplier?.name || hostname);
	const metaLine = loading && !intel
		? __('Researching supplier…', 'product-upload')
		: [prettyPlatform(facts.platform), facts.primary_currency, facts.country]
			.filter(Boolean).join(' · ') || __('Site details unavailable', 'product-upload');

	return (
		<div className="pu-wc-ov">
			<div className="pu-wc-ov__header">
				<div className="pu-wc-ov__id">
					<div className="pu-wc-ov__favicon">
						<img
							src={`https://icons.duckduckgo.com/ip2/${hostname}.ico`}
							alt=""
							loading="lazy"
							onError={(e) => { e.currentTarget.style.display = 'none'; }}
						/>
					</div>
					<div className="pu-wc-ov__id-text">
						<h3 className="pu-wc-ov__name">{name || __('Unknown supplier', 'product-upload')}</h3>
						{hostname && (
							<a className="pu-wc-ov__host" href={`https://${hostname}`} target="_blank" rel="noreferrer">
								{hostname}
							</a>
						)}
						<div className="pu-wc-ov__meta">{metaLine}</div>
						{badges.length > 0 && (
							<div className="pu-wc-ov__badges">
								{badges.map((b) => (
									<span key={b.label} className={`pu-wc-ov-badge pu-wc-ov-badge--${b.tone || 'muted'}`}>{b.label}</span>
								))}
							</div>
						)}
					</div>
				</div>
				<Button variant="primary" onClick={() => onBulkImport(supplier?.url)}>
					{__('Bulk import', 'product-upload')}
				</Button>
			</div>

			<LocaleSection hostname={hostname} facts={facts} />

			{loading && !intel ? (
				<LoadingBody hostname={hostname} />
			) : error && !intel ? (
				<ErrorState error={error} onRetry={() => load()} />
			) : (
				<>
					{compat && !compat.isClean && (
						<Notice status="warning" isDismissible={false} className="pu-wc-ov__blocker">
							<p>{compat.message}</p>
							{compat.detectedLine && <p className="pu-wc-ov__blocker-sub">{compat.detectedLine}</p>}
							{compat.recommendExtension && onConnectExtension && (
								<p className="pu-wc-ov__blocker-cta">
									<Button variant="secondary" size="small" onClick={onConnectExtension}>
										{__('Connect the browser extension', 'product-upload')}
									</Button>
								</p>
							)}
						</Notice>
					)}
					<SupplierDetails supplier={supplier} facts={facts} />
					{intel && <FooterSection intel={intel} />}
				</>
			)}
		</div>
	);
}

// Scorecard body

function SupplierDetails({ supplier, facts }) {
	const hostname = supplier?.url;
	const platform = facts.platform;

	const stats = [];
	const size = facts.catalog_size_estimate;
	const price = facts.price_range_estimate;
	const trust = facts.trust_signals;
	if (size?.value) {
		stats.push({ value: `~${formatNumber(size.value)}`, label: __('products', 'product-upload'), icon: 'product', tone: 'magic' });
	}
	if (price?.min != null && price?.max != null) {
		const sym = currencySymbol(price.currency);
		stats.push({ value: `${sym}${formatNumber(price.min)}–${sym}${formatNumber(price.max)}`, label: __('price range', 'product-upload'), icon: 'cash', tone: 'success' });
	}
	if (trust?.trustpilot_score != null) {
		stats.push({ value: trust.trustpilot_score.toFixed(1), label: trust.trustpilot_count ? `${formatNumber(trust.trustpilot_count)} ${__('reviews', 'product-upload')}` : 'Trustpilot', icon: 'star', tone: 'warning' });
	}

	const categories = (facts.top_categories ?? []).filter(Boolean);

	const moq = facts.moq_required === true
		? (facts.moq_details || __('Minimum order required', 'product-upload'))
		: facts.moq_required === false ? __('No minimum order', 'product-upload') : null;
	const founded = facts.operational?.founded_year;
	const company = [facts.operational?.hq, founded ? `est. ${founded}` : null].filter(Boolean).join(' · ') || null;
	const siteFacts = [
		{ icon: 'store', label: __('Type', 'product-upload'), value: prettyBusinessModel(facts.business_model) },
		{ icon: 'order', label: __('Order requirements', 'product-upload'), value: moq },
		{ icon: 'connect', label: __('Bulk feed', 'product-upload'), value: describeBulkFeed({ hostname, platform }) },
		{ icon: 'delivery', label: __('Ships to', 'product-upload'), value: joinList(facts.operational?.ships_to) },
		{ icon: 'location', label: __('Company', 'product-upload'), value: company },
		{ icon: 'language', label: __('Languages', 'product-upload'), value: joinList(facts.site_languages) },
		{ icon: 'cash', label: __('Currencies', 'product-upload'), value: joinList(facts.currencies_offered) },
	].filter((f) => f.value);

	const method = describeDetectionMethod({ hostname, platform, siteName: prettySiteName(supplier) });
	const visual = getMethodVisual({ hostname, platform });
	const features = listBulkImportFeatures({ hostname, platform });

	if (!facts.summary && stats.length === 0 && categories.length === 0 && siteFacts.length === 0) {
		return null;
	}

	return (
		<div className="pu-wc-ov__body">
			{facts.summary && <p className="pu-wc-ov__summary">{facts.summary}</p>}

			{stats.length > 0 && (
				<div className="pu-wc-ov-stats">
					{stats.map((s) => (
						<div key={s.label} className="pu-wc-ov-stat">
							<span className={`pu-wc-ov-stat__icon pu-wc-ov-stat__icon--${s.tone}`}><Glyph name={s.icon} /></span>
							<span className="pu-wc-ov-stat__text">
								<span className="pu-wc-ov-stat__value">{s.value}</span>
								<span className="pu-wc-ov-stat__label">{s.label}</span>
							</span>
						</div>
					))}
				</div>
			)}

			{categories.length > 0 && (
				<div className="pu-wc-ov-group">
					<div className="pu-wc-ov-group__label">{__('Top categories', 'product-upload')}</div>
					<div className="pu-wc-ov-tags">
						{categories.map((c, i) => <span key={`${c}-${i}`} className="pu-wc-ov-tag">{c}</span>)}
					</div>
				</div>
			)}

			{siteFacts.length > 0 && (
				<div className="pu-wc-ov-group">
					<div className="pu-wc-ov-group__label">{__('Site', 'product-upload')}</div>
					<div className="pu-wc-ov-facts">
						{siteFacts.map((f) => (
							<div key={f.label} className="pu-wc-ov-fact">
								<span className="pu-wc-ov-fact__icon"><Glyph name={f.icon} size={16} /></span>
								<span className="pu-wc-ov-fact__text">
									<span className="pu-wc-ov-fact__label">{f.label}</span>
									<span className="pu-wc-ov-fact__value">{f.value}</span>
								</span>
							</div>
						))}
					</div>
				</div>
			)}

			{facts.notes_for_merchant && (
				<div className="pu-wc-ov-note">{facts.notes_for_merchant}</div>
			)}

			<div className="pu-wc-ov-group">
				<div className="pu-wc-ov-group__label">{__('Detection compatibility', 'product-upload')}</div>
				<div className="pu-wc-ov-method">
					<span className={`pu-wc-ov-method__badge pu-wc-ov-method__badge--${visual.tone}`}><Glyph name={visual.icon} size={20} /></span>
					<div className="pu-wc-ov-method__text">
						<h4>{method.title}</h4>
						<p>{method.description}</p>
						<p className="pu-wc-ov-method__fields">
							<strong>{__('Imports:', 'product-upload')}</strong> {EXTRACTED_FIELDS_INLINE}
						</p>
						{method.showIssueCta && (
							<p className="pu-wc-ov-method__issue">
								{__('Spotted bad extraction?', 'product-upload')}{' '}
								<button type="button" className="pu-wc-ov-linkbtn" onClick={() => openExtractionIssueChat(prettySiteName(supplier))}>
									{__('Let us know', 'product-upload')}
								</button>
							</p>
						)}
					</div>
				</div>
			</div>

			{features.length > 0 && (
				<div className="pu-wc-ov-group">
					<div className="pu-wc-ov-group__label">{__('Bulk import features', 'product-upload')}</div>
					<div className="pu-wc-ov-feats">
						{features.map((f) => (
							<span key={f} className="pu-wc-ov-feat"><span className="pu-wc-ov-feat__check">✓</span>{f}</span>
						))}
					</div>
				</div>
			)}
		</div>
	);
}

function FooterSection({ intel }) {
	const history = intel.scrape_signals?.import_history;
	const importLine = [recencyLabel(history?.last_success_bucket), communityLabel(history?.community_label)].filter(Boolean).join(' · ');
	return (
		<div className="pu-wc-ov-footer">
			{importLine && <p>{importLine}</p>}
			<p>{__('Last scanned', 'product-upload')} {relativeTime(intel.last_refreshed_at)} · {__('Generated with AI, accuracy may vary', 'product-upload')}</p>
		</div>
	);
}

// Locale pin

function LocaleSection({ hostname, facts }) {
	const [saved, setSaved] = useState({ currency: '', country: '', language: '' });
	const [draft, setDraft] = useState({ currency: '', country: '', language: '' });
	const [editing, setEditing] = useState(false);
	const [saving, setSaving] = useState(false);
	const [saveError, setSaveError] = useState(null);

	useEffect(() => {
		let cancelled = false;
		setSaved({ currency: '', country: '', language: '' });
		setDraft({ currency: '', country: '', language: '' });
		setEditing(false);
		if (!hostname) {
			return undefined;
		}
		apiFetch({ path: `/product-upload/v1/supplier-settings?hostname=${encodeURIComponent(hostname)}` })
			.then((body) => {
				if (cancelled) {
					return;
				}
				const loaded = {
					currency: body?.settings?.currency ?? '',
					country: body?.settings?.country ?? '',
					language: body?.settings?.language ?? '',
				};
				setSaved(loaded);
				setDraft(loaded);
			})
			.catch(() => { /* leave auto-detect */ });
		return () => { cancelled = true; };
	}, [hostname]);

	const detectedCurrency = facts?.primary_currency || null;
	const detectedCountry = facts?.country || null;
	const detectedLanguage = facts?.site_languages?.[0] || null;

	const currencyOptions = useMemo(() => localeOptions('currency', LOCALE_CURRENCIES, detectedCurrency, detectedCurrency ? `Auto: ${detectedCurrency} detected` : 'Auto-detect'), [detectedCurrency]);
	const countryOptions = useMemo(() => localeOptions('region', LOCALE_COUNTRIES, detectedCountry, detectedCountry ? `Auto: ${detectedCountry} detected` : 'Auto-detect'), [detectedCountry]);
	const languageOptions = useMemo(() => localeOptions('language', LOCALE_LANGUAGES, detectedLanguage, detectedLanguage ? `Auto: ${detectedLanguage} detected` : 'Auto-detect'), [detectedLanguage]);

	const isDirty = draft.currency !== saved.currency || draft.country !== saved.country || draft.language !== saved.language;

	const save = async () => {
		if (!hostname) {
			return;
		}
		setSaving(true);
		setSaveError(null);
		try {
			const body = await apiFetch({
				path: '/product-upload/v1/supplier-settings',
				method: 'POST',
				data: { hostname, ...draft },
			});
			const next = {
				currency: body?.settings?.currency ?? '',
				country: body?.settings?.country ?? '',
				language: body?.settings?.language ?? '',
			};
			setSaved(next);
			setDraft(next);
			setEditing(false);
		} catch (err) {
			setSaveError(err?.message || __('Failed to save', 'product-upload'));
		} finally {
			setSaving(false);
		}
	};

	const effCurrency = saved.currency || detectedCurrency || '';
	const effCountry = saved.country || detectedCountry || '';
	const effLanguage = saved.language || detectedLanguage || '';

	if (!editing) {
		const region = displayName('region', effCountry);
		const lang = displayName('language', effLanguage);
		const lines = [
			effCurrency ? `${__('Currency', 'product-upload')} ${effCurrency}` : null,
			lang ? `${__('Language', 'product-upload')} ${lang}` : null,
			region ? `${__('Region', 'product-upload')} ${region}` : null,
		].filter(Boolean);
		return (
			<div className="pu-wc-ov-locale">
				<div className="pu-wc-ov-locale__summary">
					<div className="pu-wc-ov-group__label">{__('Import locale', 'product-upload')}</div>
					<div className="pu-wc-ov-locale__line">
						{lines.length ? lines.join(' · ') : __('Auto-detected per product', 'product-upload')}
					</div>
				</div>
				<Button variant="link" onClick={() => setEditing(true)}>{__('Edit', 'product-upload')}</Button>
			</div>
		);
	}

	return (
		<div className="pu-wc-ov-locale pu-wc-ov-locale--edit">
			<div className="pu-wc-ov-group__label">{__('Import locale', 'product-upload')}</div>
			<p className="pu-wc-ov-locale__hint">
				{__('Pin the country and language we read this supplier in, and the currency its prices are in. Prices then convert to your store currency.', 'product-upload')}
			</p>
			<div className="pu-wc-ov-locale__grid">
				<SelectControl label={__('Country', 'product-upload')} value={draft.country} options={countryOptions} disabled={saving} onChange={(v) => setDraft((d) => ({ ...d, country: v }))} __nextHasNoMarginBottom />
				<SelectControl label={__('Language', 'product-upload')} value={draft.language} options={languageOptions} disabled={saving} onChange={(v) => setDraft((d) => ({ ...d, language: v }))} __nextHasNoMarginBottom />
				<SelectControl label={__('Currency', 'product-upload')} value={draft.currency} options={currencyOptions} disabled={saving} onChange={(v) => setDraft((d) => ({ ...d, currency: v }))} __nextHasNoMarginBottom />
			</div>
			{saveError && <Notice status="error" onRemove={() => setSaveError(null)}>{saveError}</Notice>}
			<div className="pu-wc-ov-locale__actions">
				<Button variant="primary" size="small" isBusy={saving} disabled={!isDirty || saving} onClick={save}>
					{__('Save locale', 'product-upload')}
				</Button>
				<Button size="small" disabled={saving} onClick={() => { setDraft(saved); setSaveError(null); setEditing(false); }}>
					{__('Cancel', 'product-upload')}
				</Button>
			</div>
		</div>
	);
}

// Loading / error

function LoadingBody({ hostname }) {
	const [researching, setResearching] = useState(false);
	useEffect(() => {
		const id = setTimeout(() => setResearching(true), SKELETON_GRACE_MS);
		return () => clearTimeout(id);
	}, []);
	return (
		<div className="pu-wc-ov__loading">
			<OverviewSkeleton />
			{researching && (
				<div className="pu-wc-ov-overlay">
					<div className="pu-wc-ov-overlay__scrim" />
					<div className="pu-wc-ov-overlay__inner"><ResearchingBody hostname={hostname} /></div>
				</div>
			)}
		</div>
	);
}

function OverviewSkeleton() {
	return (
		<div className="pu-wc-ov-skel" role="status" aria-busy="true" aria-label="Loading supplier details">
			<Skel w="100%" h={11} style={{ marginBottom: 7 }} />
			<Skel w="92%" h={11} style={{ marginBottom: 7 }} />
			<Skel w="64%" h={11} />
			<div className="pu-wc-ov-skel__stats">
				{[0, 1, 2].map((i) => (
					<div className="pu-wc-ov-skel__stat" key={i}>
						<Skel w={30} h={30} r={8} />
						<span style={{ flex: 1 }}>
							<Skel w="50%" h={12} style={{ display: 'block', marginBottom: 6 }} />
							<Skel w="34%" h={9} style={{ display: 'block' }} />
						</span>
					</div>
				))}
			</div>
			<div className="pu-wc-ov-skel__chips">
				{[96, 128, 104, 88].map((w, i) => <Skel key={i} w={w} h={22} r={11} />)}
			</div>
			<div className="pu-wc-ov-skel__facts">
				{[0, 1, 2, 3].map((i) => (
					<div className="pu-wc-ov-skel__fact" key={i}>
						<Skel w={18} h={18} r={4} />
						<span style={{ flex: 1 }}>
							<Skel w="40%" h={9} style={{ display: 'block', marginBottom: 5 }} />
							<Skel w="62%" h={11} style={{ display: 'block' }} />
						</span>
					</div>
				))}
			</div>
		</div>
	);
}

function ResearchingBody({ hostname }) {
	const [phaseIndex, setPhaseIndex] = useState(0);
	useEffect(() => {
		const id = setInterval(() => {
			setPhaseIndex((i) => Math.min(i + 1, RESEARCH_PHASES.length - 1));
		}, PHASE_DURATION_MS);
		return () => clearInterval(id);
	}, []);
	const message = RESEARCH_PHASES[phaseIndex].replace('{host}', hostname || __('the supplier', 'product-upload'));
	return (
		<div className="pu-wc-ov-research">
			<ResearchingGraphic />
			<p className="pu-wc-ov-research__phase" aria-live="polite">{message}</p>
			<p className="pu-wc-ov-research__sub">
				{__('This usually takes 15 to 30 seconds. Feel free to keep this open.', 'product-upload')}
			</p>
		</div>
	);
}

function ResearchingGraphic() {
	return (
		<div aria-hidden="true">
			<svg width="220" height="140" viewBox="0 0 220 140" xmlns="http://www.w3.org/2000/svg">
				<style>{`
					@keyframes pu-wc-research-scan {
						0%{transform:translate(40px,38px)} 22%{transform:translate(170px,38px)}
						28%{transform:translate(170px,38px)} 50%{transform:translate(170px,100px)}
						56%{transform:translate(170px,100px)} 78%{transform:translate(40px,100px)}
						84%{transform:translate(40px,100px)} 100%{transform:translate(40px,38px)}
					}
					@keyframes pu-wc-research-row { 0%,15%{opacity:0;transform:translateX(-4px)} 40%,100%{opacity:1;transform:translateX(0)} }
					@keyframes pu-wc-research-pulse { 0%,100%{opacity:.45} 50%{opacity:.85} }
					.pu-wc-research-glass { animation: pu-wc-research-scan 5s cubic-bezier(.6,0,.4,1) infinite; }
					.pu-wc-research-ring { animation: pu-wc-research-pulse 1.6s ease-in-out infinite; transform-origin:center; }
					.pu-wc-research-row { animation: pu-wc-research-row 5s ease-in-out infinite; }
					.pu-wc-research-row-1{animation-delay:0s}.pu-wc-research-row-2{animation-delay:.4s}
					.pu-wc-research-row-3{animation-delay:.8s}.pu-wc-research-row-4{animation-delay:1.2s}
					.pu-wc-research-row-5{animation-delay:1.6s}.pu-wc-research-row-6{animation-delay:2s}
					@media (prefers-reduced-motion: reduce){
						.pu-wc-research-glass,.pu-wc-research-ring,.pu-wc-research-row{animation:none;opacity:1}
					}
				`}</style>
				<rect x="20" y="14" width="180" height="112" rx="10" fill="#FAFBFB" stroke="#E1E3E5" strokeWidth="1.5" />
				<circle cx="32" cy="26" r="2.5" fill="#FF6A6A" />
				<circle cx="42" cy="26" r="2.5" fill="#FFC93D" />
				<circle cx="52" cy="26" r="2.5" fill="#5BBD7E" />
				<rect x="64" y="22" width="124" height="8" rx="4" fill="#EDEEEF" />
				<rect className="pu-wc-research-row pu-wc-research-row-1" x="32" y="44" width="76" height="8" rx="3" fill="#7480ff" />
				<rect className="pu-wc-research-row pu-wc-research-row-2" x="32" y="58" width="156" height="4" rx="2" fill="#C9CCCF" />
				<rect className="pu-wc-research-row pu-wc-research-row-3" x="32" y="66" width="138" height="4" rx="2" fill="#C9CCCF" />
				<rect className="pu-wc-research-row pu-wc-research-row-4" x="32" y="74" width="118" height="4" rx="2" fill="#C9CCCF" />
				<rect className="pu-wc-research-row pu-wc-research-row-5" x="32" y="92" width="36" height="14" rx="3" fill="#E4E5E7" />
				<rect className="pu-wc-research-row pu-wc-research-row-5" x="74" y="92" width="48" height="14" rx="3" fill="#E4E5E7" />
				<rect className="pu-wc-research-row pu-wc-research-row-6" x="128" y="92" width="40" height="14" rx="3" fill="#E4E5E7" />
				<g className="pu-wc-research-glass">
					<circle className="pu-wc-research-ring" cx="0" cy="0" r="14" fill="rgba(116,128,255,0.16)" stroke="#7480ff" strokeWidth="2.5" />
					<line x1="9.5" y1="9.5" x2="17" y2="17" stroke="#7480ff" strokeWidth="3" strokeLinecap="round" />
				</g>
			</svg>
		</div>
	);
}

function ErrorState({ error, onRetry }) {
	return (
		<div className="pu-wc-ov-error">
			<p className="pu-wc-ov-error__title">{__('Could not load supplier details.', 'product-upload')}</p>
			{error && <p className="pu-wc-ov-error__msg">{error}</p>}
			<Button onClick={onRetry}>{__('Retry', 'product-upload')}</Button>
		</div>
	);
}

// Helpers

function normaliseHostname(input) {
	if (!input) {
		return '';
	}
	let s = String(input).trim().toLowerCase();
	if (!s.includes('://')) {
		s = `https://${s}`;
	}
	try {
		return new URL(s).hostname.replace(/^www\./, '');
	} catch {
		return String(input).trim().toLowerCase().replace(/^www\./, '').split('/')[0];
	}
}

function capitalize(s) {
	return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}

function joinList(arr) {
	if (!Array.isArray(arr) || arr.length === 0) {
		return null;
	}
	return arr.filter(Boolean).join(', ');
}

function formatNumber(n) {
	if (typeof n !== 'number' || !isFinite(n)) {
		return String(n);
	}
	if (n >= 1000000) {
		return `${(n / 1000000).toFixed(n >= 10000000 ? 0 : 1)}M`;
	}
	if (n >= 1000) {
		return `${(n / 1000).toFixed(n >= 10000 ? 0 : 1)}K`;
	}
	return n.toLocaleString();
}

function currencySymbol(code) {
	switch ((code || 'USD').toUpperCase()) {
		case 'USD': case 'AUD': case 'CAD': case 'NZD': case 'HKD': case 'SGD': case 'MXN': return '$';
		case 'EUR': return '€';
		case 'GBP': return '£';
		case 'JPY': case 'CNY': case 'RMB': return '¥';
		case 'INR': return '₹';
		case 'KRW': return '₩';
		case 'BRL': return 'R$';
		case 'TRY': return '₺';
		case 'ZAR': return 'R';
		default: return `${code} `;
	}
}

function prettyPlatform(p) {
	if (!p || p === 'unknown') {
		return null;
	}
	const map = { shopify: 'Shopify', woocommerce: 'WooCommerce', bigcommerce: 'BigCommerce', squarespace: 'Squarespace', wix: 'Wix', magento: 'Magento', custom: 'Custom platform' };
	return map[p] ?? p;
}

function prettyBusinessModel(m) {
	if (!m || m === 'unknown') {
		return null;
	}
	const map = { retail_brand: 'Retail brand site', retail_multibrand: 'Multi-brand retailer', marketplace: 'Marketplace', wholesale: 'Wholesale supplier', manufacturer: 'Manufacturer', dropship_supplier: 'Dropshipping supplier' };
	return map[m] ?? m;
}

const MARKETPLACE_HOSTNAMES = (() => {
	const map = new Map();
	for (const s of clientSearchSuppliers) {
		try {
			const h = new URL(s.homepageUrl).hostname.replace(/^www\./, '');
			map.set(h, { id: s.id, name: s.displayName });
		} catch {
			/* skip */
		}
	}
	return map;
})();

const BROWSE_CAPABLE_MARKETPLACES = new Set(['ebay']);

function getMarketplaceInfo(hostname) {
	if (!hostname) {
		return null;
	}
	const cleanHost = hostname.replace(/^www\./, '').toLowerCase();
	const cleanRoot = cleanHost.split('.').slice(-2).join('.');
	for (const [marketHost, info] of MARKETPLACE_HOSTNAMES) {
		const marketRoot = marketHost.split('.').slice(-2).join('.');
		if (cleanHost === marketHost || cleanRoot === marketRoot) {
			return info;
		}
	}
	return null;
}

function bulkImportBadge({ hostname, platform }) {
	if (getMarketplaceInfo(hostname)) {
		return { label: __('Marketplace importer', 'product-upload'), tone: 'info' };
	}
	if (['shopify', 'woocommerce', 'bigcommerce', 'squarespace'].includes(platform)) {
		return { label: __('Direct API importer', 'product-upload'), tone: 'success' };
	}
	return { label: __('AI product importer', 'product-upload'), tone: 'magic' };
}

function getMethodVisual({ hostname, platform }) {
	if (getMarketplaceInfo(hostname)) {
		return { icon: 'markets', tone: 'info' };
	}
	if (['shopify', 'woocommerce', 'bigcommerce', 'squarespace'].includes(platform)) {
		return { icon: 'connect', tone: 'success' };
	}
	return { icon: 'magic', tone: 'magic' };
}

function describeBulkFeed({ hostname, platform }) {
	if (!hostname) {
		return null;
	}
	const marketplace = getMarketplaceInfo(hostname);
	if (marketplace) {
		return `Marketplace importer (${marketplace.name})`;
	}
	switch (platform) {
		case 'shopify': return 'Direct integration (Shopify products API)';
		case 'woocommerce': return 'Direct integration (WooCommerce store API)';
		case 'bigcommerce': return 'Direct integration (BigCommerce API)';
		case 'squarespace': return 'Direct integration (Squarespace JSON)';
		default: return 'Sitemap bulk feed (experimental)';
	}
}

function listBulkImportFeatures({ hostname, platform }) {
	const marketplace = getMarketplaceInfo(hostname);
	if (marketplace) {
		if (BROWSE_CAPABLE_MARKETPLACES.has(marketplace.id)) {
			return ['Bulk import the full catalog', 'Browse and pick before importing', 'Filter by collection', 'Search by keyword', 'Filter and sort results'];
		}
		return ['Search by keyword', 'Filter and sort results', 'Browse and pick from search results', 'Bulk import search results'];
	}
	switch (platform) {
		case 'shopify':
		case 'woocommerce':
			return ['Bulk import the full catalog', 'Browse and pick before importing', 'Filter by collection'];
		default:
			return ['Bulk import the full catalog', 'Browse and pick before importing'];
	}
}

function describeDetectionMethod({ hostname, platform, siteName }) {
	const marketplace = getMarketplaceInfo(hostname);
	const subject = siteName || 'This site';
	if (marketplace) {
		return {
			title: `Dedicated ${marketplace.name} importer`,
			description: `Product data is pulled from ${marketplace.name}'s listing pages using a marketplace-specific parser, so titles, prices, photos, and variants come through with high accuracy. Descriptions are then rewritten with AI in your store's tone.`,
		};
	}
	switch (platform) {
		case 'shopify':
			return { title: 'Direct Shopify integration', description: `${subject} runs on Shopify, which exposes a public products API. Product details are fetched directly from the API for maximum accuracy. Descriptions are then rewritten with AI in your store's tone.` };
		case 'woocommerce':
			return { title: 'Direct WooCommerce integration', description: `${subject} runs on WooCommerce, which exposes a public store API. Product details are fetched directly for maximum accuracy. Descriptions are then rewritten with AI in your store's tone.` };
		case 'bigcommerce':
			return { title: 'Direct BigCommerce integration', description: `${subject} runs on BigCommerce, which exposes a public API. Product details are fetched directly for maximum accuracy. Descriptions are then rewritten with AI in your store's tone.` };
		case 'squarespace':
			return { title: 'Direct Squarespace integration', description: `${subject} runs on Squarespace, which serves product data as structured JSON. Product details are fetched directly for high accuracy. Descriptions are then rewritten with AI in your store's tone.` };
		default:
			return { title: 'AI extraction from product pages', description: `${subject} uses a custom platform, so each product page is loaded and parsed with AI. Accuracy depends on how structured the site's HTML is. Variants, specs, and pricing are extracted on a best-effort basis, then descriptions are rewritten in your store's tone.`, showIssueCta: true };
	}
}

function openExtractionIssueChat(siteName) {
	if (typeof window === 'undefined' || !window.$crisp) {
		return;
	}
	window.$crisp.push(['do', 'chat:open']);
	window.$crisp.push([
		'do',
		'message:show',
		['text', `Hi! What's going wrong with imports from ${siteName || 'this supplier'}? Send a product URL that didn't import cleanly and we'll improve the parser for the site.`],
	]);
}

function composeScrapeCompatibility({ aiFacts, signals, siteName }) {
	const clean = { isClean: true, badge: null, message: null, detectedLine: null, recommendExtension: false };
	const history = signals?.import_history;
	if (history?.successful_imports_30d > 0 || history?.successful_imports_90d > 0) {
		return clean;
	}
	const ai = aiFacts ?? {};
	const blockers = new Set();
	if (ai.requires_login === true || signals?.redirected_to_login) {
		blockers.add('login');
	}
	const aiStack = ai.anti_bot_stack;
	const stack = aiStack && aiStack !== 'none' && aiStack !== 'unknown' ? aiStack : signals?.anti_bot_hint;
	const probeFailed = !!signals?.fetch_error || (typeof signals?.homepage_status === 'number' && signals.homepage_status >= 400);
	if (stack && probeFailed) {
		blockers.add('anti_bot');
	}
	if (ai.geo_restricted === true) {
		blockers.add('geo');
	}
	if (blockers.size === 0) {
		return clean;
	}
	const phrases = {
		login: ai.login_details || 'requires login to view products',
		anti_bot: `uses ${prettyAntiBot(stack)} bot protection`,
		geo: ai.geo_details ? `is geo-restricted (${ai.geo_details})` : 'is geo-restricted',
	};
	const ordered = ['login', 'anti_bot', 'geo'].filter((b) => blockers.has(b));
	const fragments = joinSentenceFragments(ordered.map((b) => stripTrailingPunctuation(phrases[b])));
	const subject = siteName || 'This site';
	const handles = ordered.length === 1 ? 'this' : ordered.length === 2 ? 'both' : 'all of these';
	return {
		isClean: false,
		badge: badgeFor(ordered),
		message: `${subject} ${fragments}. Our browser extension imports using your signed-in browser session, which can handle ${handles} if you see poor import results.`,
		detectedLine: stack && stack !== 'none' && stack !== 'unknown' ? `Detected: ${prettyAntiBot(stack)}` : null,
		recommendExtension: true,
	};
}

function badgeFor(ordered) {
	if (ordered.length > 1) {
		return { label: 'Manual import recommended' };
	}
	if (ordered[0] === 'login') {
		return { label: 'Login required' };
	}
	if (ordered[0] === 'anti_bot') {
		return { label: 'Bot protection' };
	}
	if (ordered[0] === 'geo') {
		return { label: 'Geo restricted' };
	}
	return null;
}

function stripTrailingPunctuation(s) {
	return String(s ?? '').replace(/[.!?;,\s]+$/u, '');
}

function joinSentenceFragments(items) {
	if (items.length === 0) {
		return '';
	}
	if (items.length === 1) {
		return items[0];
	}
	if (items.length === 2) {
		return `${items[0]} and ${items[1]}`;
	}
	return `${items.slice(0, -1).join(', ')}, and ${items[items.length - 1]}`;
}

function prettyAntiBot(s) {
	const map = { cloudflare: 'Cloudflare', datadome: 'DataDome', akamai: 'Akamai', perimeterx: 'PerimeterX', none: 'None' };
	return map[s] ?? s;
}

function prettySiteName(supplier) {
	const raw = supplier?.name || supplier?.url;
	return raw ? raw.charAt(0).toUpperCase() + raw.slice(1) : null;
}

function recencyLabel(bucket) {
	switch (bucket) {
		case 'this_week': return 'Imported by Product Upload users this week';
		case 'this_month': return 'Imported by Product Upload users this month';
		case 'last_90_days': return 'Imported by Product Upload users in the last 90 days';
		default: return null;
	}
}

function communityLabel(label) {
	switch (label) {
		case 'used_by_other_stores': return 'used by multiple stores';
		case 'popular': return 'popular with Product Upload stores';
		default: return null;
	}
}

function relativeTime(iso) {
	if (!iso) {
		return 'just now';
	}
	const diffSec = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
	if (diffSec < 60) {
		return 'just now';
	}
	if (diffSec < 3600) {
		return `${Math.floor(diffSec / 60)} minutes ago`;
	}
	if (diffSec < 86400) {
		return `${Math.floor(diffSec / 3600)} hours ago`;
	}
	const d = Math.floor(diffSec / 86400);
	return d === 1 ? '1 day ago' : `${d} days ago`;
}
