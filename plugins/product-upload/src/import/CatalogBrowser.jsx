import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import {
	category,
	check,
	chevronDown,
	chevronLeft,
	close,
	closeSmall,
	external,
	grid,
	lineSolid,
	link,
	page,
	people,
	plus,
	formatListBullets,
	Icon,
	scheduled,
	shield,
	starFilled,
} from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { DiscoveryLoader, SitemapCrawlGraphic } from './DiscoveryLoader';
import { Skel } from './Skeletons';
import { FULL_TILE_LOGOS, SUPPLIER_LOGO_SRC, proxyImage, thumbUrl } from './searchSuppliersClient';
import DoneForYouLink, { BIG_JOB_PRODUCTS, doneForYouUrl, isTopPlan } from './DoneForYouLink';
import { CWS_URL } from './ExtensionPairModal';
import { adminPostUrl } from './jobUtils';
import './CatalogBrowser.scss';

const API = '/product-upload/v1/site';
// client-side filters need the whole list
const AUTO_LOAD_CAP = 3000;
const RENDER_STEP = 60;
const THUMB = 400;
// "select everything" goes 500 at a time
const SELECT_BATCH = 500;
// marketplaces that can list one seller
const SELLER_MARKETS = new Set(['aliexpress', 'amazon', 'alibaba', 'etsy', 'ebay']);
// server filters shown before "More filters"
const FACETS_SHOWN = 5;
const ENRICH_BATCH = 40;
const ALL = '__all';
const STATUSES = ['new', 'in', 'all'];
const NO_PRODUCTS = [];
const LOCALE = (typeof document !== 'undefined' && document.documentElement.lang) || undefined;

const num = (x) => Number(x).toLocaleString(LOCALE);
const has = (v) => v !== null && v !== undefined;

const parseTag = (raw) => {
	try {
		return raw ? JSON.parse(raw).tag : null;
	} catch {
		return null;
	}
};

const isHttpUrl = (s) => {
	try {
		const u = new URL(s);
		return u.protocol === 'http:' || u.protocol === 'https:';
	} catch {
		return false;
	}
};

const fmtMoney = (amount, currency) => {
	if (!has(amount)) {
		return null;
	}
	try {
		return new Intl.NumberFormat(LOCALE, {
			style: 'currency',
			currency: currency || 'USD',
			maximumFractionDigits: 2,
		}).format(amount);
	} catch {
		return String(amount);
	}
};

const getJson = (path) => apiFetch({ path: `${API}/${path}` });
const postJson = (path, data) => apiFetch({ path: `${API}/${path}`, method: 'POST', data });

const RECENT_KEY = 'pu-wc-mp-recent';
const readRecent = () => {
	try {
		return JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]');
	} catch {
		return [];
	}
};
const saveRecent = (q) => {
	const next = [q, ...readRecent().filter((x) => x !== q)].slice(0, 5);
	try {
		window.localStorage.setItem(RECENT_KEY, JSON.stringify(next));
	} catch {
		// private mode: no history
	}
	return next;
};

const languageName = (code) => {
	try {
		return new Intl.DisplayNames([LOCALE || 'en'], { type: 'language' }).of(code);
	} catch {
		return code;
	}
};

const listKey = (collection, query, filters) =>
	`${collection ?? ALL}|${query ?? ''}|${JSON.stringify(filters ?? {})}`;

const facetLabel = (key) =>
	({
		type: __('Type', 'product-upload'),
		vendor: __('Vendor', 'product-upload'),
	})[key] ?? key;

const hostOf = (u) => {
	try {
		return new URL(/^https?:/i.test(u) ? u : `https://${u}`).hostname.replace(/^www\./, '');
	} catch {
		return '';
	}
};

// pasted URL is on this site (or a subdomain)
const ownSource = (sourceUrl, siteUrl) => {
	const a = hostOf(sourceUrl ?? '');
	const b = hostOf(siteUrl);
	return !!a && (a.endsWith(b) || b.endsWith(a));
};

const productEditUrl = (id) =>
	`${adminPostUrl || '/wp-admin/post.php'}?post=${encodeURIComponent(id)}&action=edit`;

const productsText = (n) => sprintf(_n('%s product', '%s products', n, 'product-upload'), num(n));
const selectedText = (n) => sprintf(_n('%s selected', '%s selected', n, 'product-upload'), num(n));
const importText = (n) => sprintf(_n('Import %s product', 'Import %s products', n, 'product-upload'), num(n));
const variantsText = (n) => sprintf(_n('%s variant', '%s variants', n, 'product-upload'), num(n));
const loadedOf = (loaded, total) =>
	has(total)
		? sprintf(__('%1$s of %2$s loaded', 'product-upload'), num(loaded), num(total))
		: sprintf(__('%s loaded', 'product-upload'), num(loaded));

// modal header: back, title, subtitle, close
export function ModalHeader({ subtitle, onBack, onClose }) {
	return (
		<div className="pu-wc-mh">
			{onBack && (
				<Button
					className="pu-wc-mh-back"
					icon={chevronLeft}
					label={__('Back', 'product-upload')}
					onClick={onBack}
				/>
			)}
			<div className="pu-wc-mh-tx">
				<h1 className="pu-wc-mh-title">{__('Bulk import', 'product-upload')}</h1>
				{subtitle && <div className="pu-wc-mh-sub">{subtitle}</div>}
			</div>
			<Button
				className="pu-wc-mh-close"
				icon={close}
				label={__('Close', 'product-upload')}
				onClick={onClose}
			/>
		</div>
	);
}

function useSiteScan(siteUrl, sourceUrl, currency) {
	const [scan, setScan] = useState({ loading: true });
	const latest = useRef(siteUrl);
	latest.current = siteUrl;
	const run = useCallback(async () => {
		// a rescan keeps the last result on screen
		setScan((s) => ({ ...s, loading: true, error: false }));
		try {
			// pasted URL only scans its own site
			const src = ownSource(sourceUrl, siteUrl) ? sourceUrl : siteUrl;
			const cur = currency ? `&currency=${encodeURIComponent(currency)}` : '';
			const data = await getJson(`scan?url=${encodeURIComponent(src)}${cur}`);
			if (latest.current === siteUrl) {
				setScan({ loading: false, ...data });
			}
		} catch {
			if (latest.current === siteUrl) {
				setScan({ loading: false, error: true });
			}
		}
	}, [siteUrl, sourceUrl, currency]);
	useEffect(() => {
		run();
	}, [run]);
	return [scan, run];
}

export default function CatalogBrowser({
	site,
	bulkOptions,
	marketplaces,
	initialQuery,
	onSwitchSite,
	onBack,
	onImport,
	onClose,
	onCategoryFallback,
	onUpgrade,
	balance,
	currency,
	planName,
}) {
	const siteUrl = site.url;
	const [scan, rescan] = useSiteScan(siteUrl, bulkOptions?.sourceUrl, currency);
	const [capsOverride, setCapsOverride] = useState(null);
	const baseCaps = capsOverride ?? scan.capabilities;
	// mid-switch: name the new marketplace
	const switchingTo = scan.loading
		? marketplaces?.find((m) => m.hostMatcher(hostOf(siteUrl).toLowerCase()))
		: null;
	const caps = useMemo(() => {
		if (!baseCaps) {
			return baseCaps;
		}
		// store-currency rates ride on the scan
		const withFx = scan.fx ? { ...baseCaps, fx: scan.fx } : baseCaps;
		return switchingTo ? { ...withFx, source: { label: switchingTo.displayName } } : withFx;
	}, [baseCaps, scan.fx, switchingTo]);
	const collections = scan.collections ?? NO_PRODUCTS;
	// saved marketplace sellers, rail scopes too
	const [sellers, setSellers] = useState([]);
	useEffect(() => setSellers(scan.sellers ?? []), [scan.sellers]);
	const scopes = useMemo(() => [...sellers, ...collections], [sellers, collections]);

	const [collection, setCollection] = useState(null);
	const [railQuery, setRailQuery] = useState('');
	const [query, setQuery] = useState(initialQuery ?? '');
	const [debounced, setDebounced] = useState(initialQuery ?? '');
	const [status, setStatus] = useState('new');
	const [sort, setSort] = useState('default');
	const [view, setView] = useState(null);
	const [clientFacets, setClientFacets] = useState({});
	const [serverFilters, setServerFilters] = useState({});
	const [inStock, setInStock] = useState(false);
	const [moreFilters, setMoreFilters] = useState(false);
	const [onSale, setOnSale] = useState(false);
	const [recent, setRecent] = useState(readRecent);
	const [lists, setLists] = useState({});
	const [basket, setBasket] = useState(() => new Map());
	const [rendered, setRendered] = useState(RENDER_STEP);
	const [enriched, setEnriched] = useState(() => new Map());
	const inflight = useRef(new Set());
	const lastIndex = useRef(null);
	const sentinel = useRef(null);
	const enrichAsked = useRef(new Set());
	const siteNow = useRef(siteUrl);
	siteNow.current = siteUrl;

	// new site: drop the last one's lists and picks
	const [shownSite, setShownSite] = useState(siteUrl);
	if (shownSite !== siteUrl) {
		setShownSite(siteUrl);
		setCollection(null);
		setLists({});
		setCapsOverride(null);
		setSellers([]);
		setBasket(new Map());
		setEnriched(new Map());
		inflight.current.clear();
		enrichAsked.current.clear();
	}

	const serverSearch = caps?.search === 'server';
	const serverFacets = caps?.facetSource === 'server';
	const activeKey = listKey(
		collection,
		serverSearch ? debounced.trim() : '',
		serverFacets ? serverFilters : {}
	);
	const entry = lists[activeKey];

	useEffect(() => {
		const id = setTimeout(() => setDebounced(query), 350);
		return () => clearTimeout(id);
	}, [query]);

	// marketplaces: a search spans every category
	useEffect(() => {
		if (caps?.searchFirst && debounced.trim()) {
			setCollection(null);
		}
	}, [caps?.searchFirst, debounced]);

	useEffect(() => {
		if (bulkOptions?.mode !== 'collection' || !scopes.length) {
			return;
		}
		const want = (scan.initialCollectionId ?? bulkOptions.collectionId)?.toLowerCase();
		const hit = scopes.find((c) => c.id?.toLowerCase() === want);
		if (hit) {
			setCollection(hit.id);
		}
	}, [bulkOptions, scopes, scan.initialCollectionId]);

	// unlistable or unmatched: hand the page on
	useEffect(() => {
		if (scan.loading || scan.error || !onCategoryFallback) {
			return;
		}
		const opts = bulkOptions;
		if (opts?.mode !== 'collection' || !opts.sourceUrl || !ownSource(opts.sourceUrl, siteUrl)) {
			return;
		}
		const want = (scan.initialCollectionId ?? opts.collectionId)?.toLowerCase();
		const found = [...(scan.sellers ?? []), ...collections].some((c) => c.id?.toLowerCase() === want);
		if (caps?.tier === 'D' || !found) {
			onCategoryFallback(opts.sourceUrl);
		}
	}, [scan.loading, caps?.tier]);

	const loadPage = useCallback(
		async (key, cursor) => {
			const flight = `${siteUrl}|${key}#${cursor ?? ''}`;
			if (inflight.current.has(flight)) {
				return;
			}
			inflight.current.add(flight);
			const [col, q, filters] = key.split('|');
			setLists((s) => ({
				...s,
				[key]: { ...(s[key] ?? { products: [] }), loading: true, partial: false },
			}));
			try {
				const params = new URLSearchParams({ url: siteUrl });
				if (col !== ALL) {
					params.set('collection', col);
				}
				if (q) {
					params.set('q', q);
				}
				if (filters && filters !== '{}') {
					params.set('filters', filters);
				}
				if (cursor) {
					params.set('cursor', cursor);
				}
				const data = await getJson(`listing?${params}`);
				// switched away mid-request
				if (siteNow.current !== siteUrl) {
					return;
				}
				if (data.error && cursor) {
					throw new Error(`listing ${data.error}`);
				}
				if (data.capabilities) {
					setCapsOverride(data.capabilities);
					setCollection(null);
					setLists({});
					rescan();
					return;
				}
				setLists((s) => {
					const prev = s[key] ?? { products: [] };
					const seen = new Set(prev.products.map((p) => p.url));
					const fresh = (data.products ?? []).filter((p) => !seen.has(p.url));
					return {
						...s,
						[key]: {
							products: cursor ? [...prev.products, ...fresh] : data.products ?? [],
							nextCursor: data.nextCursor ?? null,
							total: data.total ?? prev.total ?? null,
							facets: data.facets ?? prev.facets,
							discover: data.discover ?? null,
							queryNote: data.queryNote ?? null,
							error: data.error ?? null,
							partial: !!data.partial,
							limited: !!data.limited,
							loading: false,
						},
					};
				});
			} catch {
				if (siteNow.current !== siteUrl) {
					return;
				}
				setLists((s) => ({
					...s,
					[key]: {
						...(s[key] ?? { products: [] }),
						loading: false,
						partial: true,
						nextCursor: cursor ?? s[key]?.nextCursor ?? null,
					},
				}));
			} finally {
				inflight.current.delete(flight);
			}
		},
		[siteUrl, rescan]
	);

	useEffect(() => {
		// marketplace switch: list while the scan runs
		if ((scan.loading && !switchingTo) || !caps || caps.tier === 'D') {
			return;
		}
		if (!lists[activeKey]) {
			loadPage(activeKey, null);
		}
	}, [scan.loading, caps, activeKey, switchingTo]);

	const hasRows = !!entry?.products?.length;
	useEffect(() => {
		const q = debounced.trim();
		if (caps?.searchFirst && q && hasRows) {
			setRecent(saveRecent(q));
		}
	}, [caps?.searchFirst, debounced, hasRows]);

	useEffect(() => {
		setRendered(RENDER_STEP);
		lastIndex.current = null;
	}, [activeKey, status, debounced, sort]);

	// shown once the scan's rates are in
	const products = (!scan.loading && entry?.products) || NO_PRODUCTS;
	const merged = (p) => {
		const extra = enriched.get(p.url);
		if (!extra) {
			return p;
		}
		const known = Object.fromEntries(Object.entries(extra).filter(([, v]) => has(v)));
		return { ...p, ...known, url: p.url };
	};

	const clientFacetKeys = useMemo(
		() => (caps?.facets ?? []).filter((k) => (k === 'type' || k === 'vendor') && !serverFacets),
		[caps, serverFacets]
	);
	const facetOptions = useMemo(() => {
		const out = {};
		for (const key of clientFacetKeys) {
			const tally = new Map();
			for (const p of products) {
				if (p[key]) {
					tally.set(p[key], (tally.get(p[key]) ?? 0) + 1);
				}
			}
			out[key] = [...tally].sort((a, b) => b[1] - a[1]).slice(0, 80);
		}
		return out;
	}, [products, clientFacetKeys]);

	const pickedBy = useMemo(() => {
		const out = new Map();
		for (const b of basket.values()) {
			if (b.from) {
				out.set(b.from, (out.get(b.from) ?? 0) + 1);
			}
		}
		return out;
	}, [basket]);

	const liveTags = useMemo(() => {
		const tally = new Map();
		for (const p of products) {
			for (const tag of p.tags ?? []) {
				tally.set(tag, (tally.get(tag) ?? 0) + 1);
			}
		}
		const tags = [...tally].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).slice(0, 150);
		return { tags, loaded: products.length };
	}, [products]);
	const [tagMenu, setTagMenu] = useState({ tags: [], loaded: 0 });
	const tagPicked = serverFacets ? !!serverFilters.tag : !!clientFacets.tag;
	// keep the menu while a tag narrows the list
	useEffect(() => {
		if (!tagPicked) {
			setTagMenu(liveTags);
		}
	}, [liveTags, tagPicked]);

	const keep = useCallback(
		(rows) => {
			let out = rows;
			if (status === 'new') {
				out = out.filter((p) => !p.imported);
			}
			if (status === 'in') {
				out = out.filter((p) => p.imported);
			}
			const q = debounced.trim().toLowerCase();
			if (q && !serverSearch) {
				out = out.filter((p) => `${p.name} ${p.url}`.toLowerCase().includes(q));
			}
			for (const [k, v] of Object.entries(clientFacets)) {
				if (v) {
					out = out.filter((p) => (k === 'tag' ? p.tags?.includes(v) : p[k] === v));
				}
			}
			if (inStock && !serverFacets) {
				out = out.filter((p) => p.available !== false);
			}
			if (onSale && !serverFacets) {
				out = out.filter((p) => has(p.compareAtPrice));
			}
			return out;
		},
		[status, debounced, serverSearch, clientFacets, inStock, onSale, serverFacets]
	);

	const shown = useMemo(() => {
		let rows = keep(products).map(merged);
		const byPrice = (a, b) => (a.price ?? Infinity) - (b.price ?? Infinity);
		if (sort === 'name') {
			rows = [...rows].sort((a, b) => a.name.localeCompare(b.name));
		}
		if (sort === 'priceAsc') {
			rows = [...rows].sort(byPrice);
		}
		if (sort === 'priceDesc') {
			rows = [...rows].sort((a, b) => byPrice(b, a));
		}
		return rows;
	}, [products, keep, sort, enriched]);

	const liveCounts = useMemo(
		() => ({
			fresh: products.filter((p) => !p.imported).length,
			imported: products.filter((p) => p.imported).length,
		}),
		[products]
	);
	// hold the last counts while a new list loads
	const lastCounts = useRef(liveCounts);
	const reloading = (scan.loading || !entry || entry.loading) && !products.length;
	if (!reloading) {
		lastCounts.current = liveCounts;
	}
	const counts = reloading ? lastCounts.current : liveCounts;

	const showGrid = view ? view === 'grid' : caps?.product?.images === 'all';

	useEffect(() => {
		const el = sentinel.current;
		if (!el) {
			return undefined;
		}
		const io = new window.IntersectionObserver((hits) => {
			if (!hits.some((h) => h.isIntersecting)) {
				return;
			}
			if (rendered < shown.length) {
				setRendered((n) => n + RENDER_STEP);
			} else if (entry?.nextCursor && !entry.loading && !entry.partial) {
				loadPage(activeKey, entry.nextCursor);
			}
		});
		io.observe(el);
		return () => io.disconnect();
	}, [rendered, shown.length, entry, activeKey]);

	// tier C: read price and photo for shown rows
	useEffect(() => {
		if (caps?.enrich !== 'visible-rows') {
			return;
		}
		const want = shown
			.slice(0, rendered)
			.map((p) => p.url)
			.filter((u) => !enrichAsked.current.has(u))
			.slice(0, ENRICH_BATCH);
		if (!want.length) {
			return;
		}
		want.forEach((u) => enrichAsked.current.add(u));
		postJson('enrich', { url: siteUrl, urls: want })
			.then((data) => {
				setEnriched((m) => {
					const next = new Map(m);
					for (const item of data?.products ?? []) {
						next.set(item.url, item);
					}
					return next;
				});
			})
			.catch(() => {});
	}, [caps?.enrich, shown, rendered]);

	const collectionName = (id) => scopes.find((c) => c.id === id)?.name ?? __('All products', 'product-upload');

	const toggle = (p, index, shift) => {
		// the updater runs after lastIndex moves
		const anchor = lastIndex.current;
		setBasket((m) => {
			const next = new Map(m);
			const adding = !next.has(p.url);
			if (shift && anchor !== null) {
				const [a, b] = [anchor, index].sort((x, y) => x - y);
				for (const row of shown.slice(a, b + 1)) {
					if (adding) {
						next.set(row.url, { product: row, from: collection });
					} else {
						next.delete(row.url);
					}
				}
			} else if (adding) {
				next.set(p.url, { product: p, from: collection });
			} else {
				next.delete(p.url);
			}
			return next;
		});
		lastIndex.current = index;
	};

	const allShownSelected = shown.length > 0 && shown.every((p) => basket.has(p.url));
	const someShownSelected = !allShownSelected && shown.some((p) => basket.has(p.url));
	const toggleAllShown = () =>
		setBasket((m) => {
			const next = new Map(m);
			for (const p of shown) {
				if (allShownSelected) {
					next.delete(p.url);
				} else {
					next.set(p.url, { product: p, from: collection });
				}
			}
			return next;
		});

	// collection counts include unpublished items
	const total = entry?.total ?? null;
	const [busy, setBusy] = useState(null);
	const stopSelectAll = useRef(false);
	const moreToLoad = !!entry?.nextCursor && !entry?.partial;
	const listComplete = !!entry && !entry.loading && !entry.nextCursor && !entry.partial;
	const narrowedHere =
		(!!debounced.trim() && !serverSearch) ||
		Object.values(clientFacets).some(Boolean) ||
		((inStock || onSale) && !serverFacets);
	// fetch the rest of this list, page by page
	const pageThrough = async (cap, onProgress, count = (r) => r.length) => {
		const key = activeKey;
		const [col, q, filters] = key.split('|');
		let cursor = entry.nextCursor;
		let rows = [...products];
		stopSelectAll.current = false;
		onProgress(count(rows));
		try {
			while (cursor && count(rows) < cap && !stopSelectAll.current) {
				const params = new URLSearchParams({ url: siteUrl, cursor });
				if (col !== ALL) {
					params.set('collection', col);
				}
				if (q) {
					params.set('q', q);
				}
				if (filters && filters !== '{}') {
					params.set('filters', filters);
				}
				const data = await getJson(`listing?${params}`);
				if (siteNow.current !== siteUrl || data.capabilities || data.partial) {
					break;
				}
				const seen = new Set(rows.map((p) => p.url));
				rows = [...rows, ...(data.products ?? []).filter((p) => !seen.has(p.url))];
				cursor = data.nextCursor ?? null;
				const next = cursor;
				const snapshot = rows;
				setLists((st) => ({
					...st,
					[key]: {
						...(st[key] ?? {}),
						products: snapshot,
						nextCursor: next,
						loading: false,
						partial: false,
					},
				}));
				onProgress(count(rows));
			}
		} catch {
			// keep what loaded
		}
		return rows;
	};

	// next 500 not yet picked; click again for more
	const selectEverything = async () => {
		const fresh = (rows) => keep(rows).filter((p) => !basket.has(p.url));
		const rows = await pageThrough(
			SELECT_BATCH,
			(n) => setBusy({ kind: 'select', loaded: n }),
			(r) => fresh(r).length
		);
		const picks = fresh(rows).slice(0, SELECT_BATCH);
		setBasket((m) => {
			const next = new Map(m);
			for (const p of picks) {
				if (!next.has(p.url)) {
					next.set(p.url, { product: p, from: collection });
				}
			}
			return next;
		});
		setBusy(null);
	};

	const loadEverything = async () => {
		await pageThrough(AUTO_LOAD_CAP, (n) => setBusy({ kind: 'load', loaded: n }));
		setBusy(null);
	};

	const selected = basket.size;
	const pickedHere = products.some((p) => basket.has(p.url));
	const fromCount = new Set([...basket.values()].map((b) => b.from ?? ALL)).size;
	const over = has(balance) && balance < selected;

	const topUp = () => {
		onClose();
		onUpgrade();
	};

	const importNow = () => {
		if (!balance) {
			topUp();
			return;
		}
		// over balance: the first picks that fit
		const picks = [...basket.values()].map((b) => merged(b.product)).slice(0, balance);
		onImport(picks.map((p) => p.url));
		onClose();
	};

	// chips search straight away
	const searchFor = (w) => {
		setQuery(w);
		setDebounced(w);
	};

	const siteName = site.name || hostOf(siteUrl);
	const backBar = (subtitle) => (
		<ModalHeader subtitle={subtitle} onBack={onBack} onClose={onClose} />
	);

	if (scan.loading && !caps) {
		return (
			<div className="pu-wc-cb">
				{backBar(siteName)}
				<DiscoveryLoader
					skeleton={
						<ShellSkeleton
							marketplaces={marketplaces}
							siteHost={hostOf(siteUrl).toLowerCase()}
						/>
					}
					fill
					Graphic={SitemapCrawlGraphic}
					graceMs={3000}
					subject={siteName}
					sub={__('This usually takes 15 to 60 seconds. Keep this tab open.', 'product-upload')}
					phases={[
						sprintf(__('Reading %s’s product pages…', 'product-upload'), siteName),
						__('Mapping the site’s collections…', 'product-upload'),
						__('Big catalogs can take up to a minute, hang tight.', 'product-upload'),
						__('Tip: you’ll filter and hand-pick products on the next screen.', 'product-upload'),
						__('Descriptions get rewritten with AI in your store’s tone after import.', 'product-upload'),
					]}
				/>
			</div>
		);
	}

	if (scan.error) {
		return (
			<div className="pu-wc-cb">
				{backBar(siteName)}
				<div className="pu-wc-cb-center">
					<h3>{sprintf(__('Couldn’t scan %s', 'product-upload'), siteName)}</h3>
					<p>
						{__(
							'We hit a snag reading this site’s catalog. It’s often temporary, so try again. You can also paste a specific category or product URL to import those directly.',
							'product-upload'
						)}
					</p>
					<Button variant="secondary" onClick={rescan}>
						{__('Try again', 'product-upload')}
					</Button>
				</div>
			</div>
		);
	}

	if (!caps || caps.tier === 'D') {
		return (
			<div className="pu-wc-cb">
				{backBar(siteName)}
				<CantListScreen
					reason={scan.reason}
					site={site}
					onImport={onImport}
					onClose={onClose}
					onCategoryFallback={onCategoryFallback}
				/>
			</div>
		);
	}

	const tierTone = { A: 'success', B: 'info', C: 'warn' }[caps.tier];
	const tierLabel = {
		A: __('Full catalog', 'product-upload'),
		B: __('Catalog listing', 'product-upload'),
		C: __('Product links only', 'product-upload'),
	}[caps.tier];
	const showRail = collections.length > 0 || !!marketplaces;
	const discovering = !!caps.searchFirst && !debounced.trim();
	const siteHost = hostOf(siteUrl).toLowerCase();
	const market = marketplaces?.find((m) => m.hostMatcher(siteHost));
	const sellerMarket = market && SELLER_MARKETS.has(market.id) ? market : null;
	const dropSeller = (id) => {
		setSellers((l) => l.filter((x) => x.id !== id));
		if (collection === id) {
			setCollection(null);
		}
		postJson('sellers', { remove: id }).catch(() => {});
	};
	// Amazon is the most used fallback
	const altMarket = marketplaces?.find(
		(m) => m.id === (siteHost.includes('amazon') ? 'aliexpress' : 'amazon')
	);
	const railRows = collections.filter(
		(c) => !railQuery || c.name.toLowerCase().includes(railQuery.toLowerCase())
	);
	const loaded = products.length;
	const catalogTotal = lists[listKey(null, '', {})]?.total ?? null;
	const sourceLine = [
		caps.source?.label,
		has(catalogTotal) ? productsText(catalogTotal) : null,
		caps.source?.currency ? sprintf(__('prices in %s', 'product-upload'), caps.source.currency) : null,
	]
		.filter(Boolean)
		.join(' · ');
	const tagValue = serverFacets ? parseTag(serverFilters.tag) ?? '' : clientFacets.tag ?? '';
	const showTags =
		caps.facets?.includes('tag') &&
		tagMenu.tags.length > 1 &&
		!(entry?.facets ?? []).some((f) => /tag/i.test(f.key));
	const setTag = (v) => {
		if (!serverFacets) {
			setClientFacets((f) => ({ ...f, tag: v || undefined }));
			return;
		}
		setServerFilters((f) => {
			const next = { ...f };
			if (v) {
				next.tag = JSON.stringify({ tag: v });
			} else {
				delete next.tag;
			}
			return next;
		});
	};
	const allFacets = serverFacets ? entry?.facets ?? [] : [];
	// active filters stay visible when folded
	const shownFacets = moreFilters
		? allFacets
		: allFacets.filter((f, i) => i < FACETS_SHOWN || serverFilters[f.key]);
	const foldable = allFacets.length > FACETS_SHOWN;

	const filtersOn =
		inStock ||
		onSale ||
		Object.values(clientFacets).some(Boolean) ||
		Object.keys(serverFilters).length > 0;
	const hasChips =
		showTags ||
		clientFacetKeys.length > 0 ||
		caps.product?.stock ||
		caps.product?.compareAt ||
		(serverFacets && entry?.facets?.length);
	const allLabel = caps.searchFirst ? __('Top picks', 'product-upload') : __('All products', 'product-upload');

	const statusLabel = (value) => {
		if (value === 'all') {
			return __('All', 'product-upload');
		}
		if (!listComplete) {
			return value === 'new' ? __('New', 'product-upload') : __('In your store', 'product-upload');
		}
		return value === 'new'
			? sprintf(__('New (%s)', 'product-upload'), num(counts.fresh))
			: sprintf(__('In your store (%s)', 'product-upload'), num(counts.imported));
	};

	const busyText = () => {
		if (busy.kind !== 'select') {
			return loadedOf(busy.loaded, total);
		}
		return has(total)
			? sprintf(
				__('Selecting %1$s of %2$s…', 'product-upload'),
				num(busy.loaded),
				num(Math.min(total, SELECT_BATCH))
			)
			: sprintf(__('Selecting %s…', 'product-upload'), num(busy.loaded));
	};

	const loadedText = () => {
		if (moreToLoad) {
			return loadedOf(loaded, total);
		}
		return loaded === 1 ? __('1 product loaded', 'product-upload') : sprintf(__('All %s loaded', 'product-upload'), num(loaded));
	};

	const selectMoreText = () => {
		if (pickedHere) {
			return sprintf(__('Keep going: next %s', 'product-upload'), num(SELECT_BATCH));
		}
		if (has(total) && total <= SELECT_BATCH && !narrowedHere) {
			return sprintf(__('Select all %s in this list', 'product-upload'), num(total));
		}
		return sprintf(__('Select the first %s', 'product-upload'), num(SELECT_BATCH));
	};

	const selectShownText = () => {
		if (allShownSelected) {
			return sprintf(__('Deselect all %s', 'product-upload'), num(shown.length));
		}
		return moreToLoad
			? sprintf(__('Select %s shown', 'product-upload'), num(shown.length))
			: sprintf(__('Select all %s', 'product-upload'), num(shown.length));
	};

	const discoverHeading = () => {
		const name = entry.discover.label;
		if (entry.discover.kind === 'store') {
			return sprintf(__('Best sellers for “%s”', 'product-upload'), name);
		}
		if (entry.discover.kind === 'seller') {
			return sprintf(__('Products from %s', 'product-upload'), name);
		}
		return sprintf(__('Best sellers in %s', 'product-upload'), name);
	};

	const footNote = () => {
		const bal = balance ?? 0;
		if (!selected) {
			return sprintf(_n('%s import available', '%s imports available', bal, 'product-upload'), num(bal));
		}
		if (over) {
			const short = selected - bal;
			return sprintf(_n('You need %s more import', 'You need %s more imports', short, 'product-upload'), num(short));
		}
		const left = bal - selected;
		return createInterpolateElement(
			sprintf(
				_n('<b>%s</b> import left after this', '<b>%s</b> imports left after this', left, 'product-upload'),
				num(left)
			),
			{ b: <strong /> }
		);
	};

	const fromText =
		fromCount > 1
			? `${sprintf(
				_n('from %s collection ·', 'from %s collections ·', fromCount, 'product-upload'),
				num(fromCount)
			)} `
			: null;

	let body;
	if (reloading) {
		body = <CardsSkeleton />;
	} else if (entry.error && !products.length) {
		body = (
			<MarketDown
				name={caps.source?.label}
				alt={altMarket}
				onRetry={() => loadPage(activeKey, null)}
				onSwitch={() => onSwitchSite?.(altMarket, debounced.trim())}
			/>
		);
	} else if (shown.length === 0) {
		body = (
			<EmptyView
				allImported={
					status === 'new' && products.length > 0 && counts.fresh === 0 && !entry?.nextCursor
				}
				onShowImported={() => setStatus('all')}
			/>
		);
	} else {
		const Item = showGrid ? ProductCard : ProductRow;
		body = (
			<div className={showGrid ? 'pu-wc-cb-grid' : 'pu-wc-cb-list'}>
				{shown.slice(0, rendered).map((p, i) => (
					<Item
						key={p.url}
						p={p}
						caps={caps}
						selected={basket.has(p.url)}
						onToggle={(e) => toggle(p, i, e.shiftKey)}
					/>
				))}
			</div>
		);
	}

	let statusBar = null;
	if (reloading) {
		statusBar = (
			<div className="pu-wc-cb-selectall">
				<span className="pu-wc-cb-busy">
					<Spinner />
					{__('Loading products…', 'product-upload')}
				</span>
			</div>
		);
	} else if (products.length > 0) {
		let tail;
		if (busy) {
			tail = (
				<>
					<Spinner />
					{busyText()}
					<Button
						variant="link"
						onClick={() => {
							stopSelectAll.current = true;
						}}
					>
						{__('Cancel', 'product-upload')}
					</Button>
				</>
			);
		} else if (entry.partial) {
			tail = (
				<Button
					variant="secondary"
					size="small"
					onClick={() => loadPage(activeKey, entry.nextCursor)}
				>
					{__('Couldn’t load the rest. Retry', 'product-upload')}
				</Button>
			);
		} else {
			tail = (
				<>
					{entry.loading && <Spinner />}
					<span>{loadedText()}</span>
					{moreToLoad && (
						<Button variant="link" onClick={selectEverything}>
							{selectMoreText()}
						</Button>
					)}
					{moreToLoad && narrowedHere && (
						<>
							<span>{sprintf(__('Filtering the %s loaded', 'product-upload'), num(loaded))}</span>
							<Button variant="link" onClick={loadEverything}>
								{__('Load all', 'product-upload')}
							</Button>
						</>
					)}
				</>
			);
		}
		const mark = someShownSelected ? ' some' : '';
		statusBar = (
			<div className={`pu-wc-cb-selectall${allShownSelected ? ' on' : mark}`}>
				{shown.length > 0 && (
					<button
						type="button"
						className="pu-wc-cb-allbtn"
						role="checkbox"
						aria-checked={allShownSelected || (someShownSelected ? 'mixed' : false)}
						title={__('Shift-click selects a range', 'product-upload')}
						onClick={toggleAllShown}
					>
						<span className="pu-wc-cb-check">
							{allShownSelected && <Icon icon={check} size={20} />}
							{someShownSelected && <Icon icon={lineSolid} size={20} />}
						</span>
						{selectShownText()}
					</button>
				)}
				<span className="pu-wc-cb-status">{tail}</span>
				{!showGrid && <span className="pu-wc-cb-colprice">{__('Price', 'product-upload')}</span>}
			</div>
		);
	}

	return (
		<div className="pu-wc-cb">
			{backBar(
				<span className="pu-wc-cb-source">
					<span>{sourceLine || siteName}</span>
					{!caps.searchFirst && <span className={`pu-wc-cb-tier ${tierTone}`}>{tierLabel}</span>}
				</span>
			)}
			<div className={`pu-wc-cb-split${showRail ? '' : ' pu-wc-cb-norail'}`}>
				{showRail && (
					<aside className="pu-wc-cb-rail" aria-label={__('Collections', 'product-upload')}>
						{marketplaces && (
							<>
								<div className="pu-wc-cb-railhead">{__('Marketplaces', 'product-upload')}</div>
								<div className="pu-wc-cb-mkts">
									{marketplaces.map((m) => (
										<MarketItem
											key={m.id}
											m={m}
											active={m.hostMatcher(siteHost)}
											onClick={() =>
												!m.hostMatcher(siteHost) && onSwitchSite?.(m, debounced.trim())
											}
										/>
									))}
								</div>
							</>
						)}
						{sellerMarket && (
							<>
								<div className="pu-wc-cb-railhead">{__('Sellers', 'product-upload')}</div>
								{sellers.map((sl) => (
									<div key={sl.id} className="pu-wc-cb-sellerrow">
										<RailItem
											id={sl.id}
											name={sl.name}
											active={collection === sl.id}
											picked={pickedBy.get(sl.id) ?? 0}
											onClick={() => setCollection(sl.id)}
										/>
										<button
											type="button"
											className="pu-wc-cb-x"
											aria-label={sprintf(__('Remove %s', 'product-upload'), sl.name)}
											title={sprintf(__('Remove %s', 'product-upload'), sl.name)}
											onClick={() => dropSeller(sl.id)}
										>
											<Icon icon={closeSmall} size={20} />
										</button>
									</div>
								))}
								<AddSeller
									market={sellerMarket}
									onAdded={(sl) => {
										setSellers((l) => [sl, ...l.filter((x) => x.id !== sl.id)]);
										setCollection(sl.id);
									}}
								/>
							</>
						)}
						<div className="pu-wc-cb-railhead">
							{marketplaces ? __('Categories', 'product-upload') : __('Collections', 'product-upload')}
						</div>
						{collections.length > 8 && (
							<div className="pu-wc-cb-railfind">
								<SearchControl
									__nextHasNoMarginBottom
									label={__('Find a collection', 'product-upload')}
									placeholder={__('Find a collection', 'product-upload')}
									value={railQuery}
									onChange={setRailQuery}
								/>
							</div>
						)}
						<RailItem
							id={null}
							name={allLabel}
							active={!collection}
							picked={basket.size}
							onClick={() => setCollection(null)}
						/>
						{railRows.map((c) => (
							<RailItem
								key={c.id}
								id={c.id}
								name={c.name}
								count={c.productCount}
								image={caps.collections?.images ? c.imageUrl : null}
								child={!!c.parentId}
								active={collection === c.id}
								picked={pickedBy.get(c.id) ?? 0}
								onClick={() => setCollection(c.id)}
							/>
						))}
					</aside>
				)}
				<div className="pu-wc-cb-main">
					{showRail && (
						<div className="pu-wc-cb-mobilepick">
							{marketplaces && (
								<SelectControl
									__nextHasNoMarginBottom
									label={__('Marketplaces', 'product-upload')}
									hideLabelFromVision
									value={market?.id ?? ''}
									options={marketplaces.map((m) => ({ label: m.displayName, value: m.id }))}
									onChange={(id) => {
										const m = marketplaces.find((x) => x.id === id);
										if (m && m !== market) {
											onSwitchSite?.(m, debounced.trim());
										}
									}}
								/>
							)}
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Collections', 'product-upload')}
								hideLabelFromVision
								value={collection ?? ALL}
								options={[
									{ label: allLabel, value: ALL },
									...scopes.map((c) => ({ label: c.name, value: c.id })),
								]}
								onChange={(v) => setCollection(v === ALL ? null : v)}
							/>
						</div>
					)}
					<div className="pu-wc-cb-bar">
						<div className="pu-wc-cb-search">
							<SearchControl
								__nextHasNoMarginBottom
								label={__('Search products', 'product-upload')}
								placeholder={
									serverSearch
										? sprintf(
											__('Search %s', 'product-upload'),
											collection && !caps.searchFirst
												? collectionName(collection)
												: caps.source?.label
										)
										: __('Search loaded products', 'product-upload')
								}
								value={query}
								onChange={setQuery}
							/>
						</div>
						<SelectControl
							__nextHasNoMarginBottom
							label={__('Sort', 'product-upload')}
							labelPosition="side"
							value={sort}
							onChange={setSort}
							options={[
								{
									label: caps.searchFirst ? __('Best match', 'product-upload') : __('Supplier order', 'product-upload'),
									value: 'default',
								},
								{ label: __('Name A to Z', 'product-upload'), value: 'name' },
								...(caps.product?.price !== 'none' || caps.enrich
									? [
										{ label: __('Price, low to high', 'product-upload'), value: 'priceAsc' },
										{ label: __('Price, high to low', 'product-upload'), value: 'priceDesc' },
									]
									: []),
							]}
						/>
						<div className="pu-wc-cb-views">
							<Button
								icon={grid}
								isPressed={showGrid}
								onClick={() => setView('grid')}
								label={__('Grid view', 'product-upload')}
								size="compact"
							/>
							<Button
								icon={formatListBullets}
								isPressed={!showGrid}
								onClick={() => setView('list')}
								label={__('List view', 'product-upload')}
								size="compact"
							/>
						</div>
					</div>

					<div className="pu-wc-cb-filters">
						{STATUSES.map((value) => (
							<button
								key={value}
								type="button"
								className={`pu-wc-cb-pill${status === value ? ' on' : ''}`}
								aria-pressed={status === value}
								onClick={() => setStatus(value)}
							>
								{statusLabel(value)}
							</button>
						))}
						{hasChips && <span className="pu-wc-cb-sep" />}
						{clientFacetKeys.map((key) =>
							facetOptions[key]?.length > 1 ? (
								<FilterPill
									key={key}
									label={facetLabel(key)}
									value={clientFacets[key] ?? ''}
									onChange={(v) => setClientFacets((f) => ({ ...f, [key]: v || undefined }))}
									options={facetOptions[key].map(([v, c]) => ({ label: `${v} (${c})`, value: v }))}
								/>
							) : null
						)}
						{serverFacets &&
							shownFacets.map((f) => (
								<FilterPill
									key={f.key}
									label={f.label}
									value={serverFilters[f.key] ?? ''}
									onChange={(v) =>
										setServerFilters((s) => {
											const next = { ...s };
											if (v) {
												next[f.key] = v;
											} else {
												delete next[f.key];
											}
											return next;
										})
									}
									options={(f.values ?? []).map((v) => ({
										label: has(v.count) ? `${v.label} (${v.count})` : v.label,
										value: v.value,
									}))}
								/>
							))}
						{foldable && (
							<button
								type="button"
								className="pu-wc-cb-pill pu-wc-cb-morepill"
								onClick={() => setMoreFilters((v) => !v)}
							>
								{moreFilters
									? __('Fewer filters', 'product-upload')
									: sprintf(__('More filters (%s)', 'product-upload'), allFacets.length - shownFacets.length)}
							</button>
						)}
						{showTags && (
							<FilterPill
								label={__('Tag', 'product-upload')}
								note={sprintf(
									_n(
										'Counts from %s loaded product',
										'Counts from %s loaded products',
										tagMenu.loaded,
										'product-upload'
									),
									num(tagMenu.loaded)
								)}
								value={tagValue}
								onChange={setTag}
								options={tagMenu.tags.map(([tag, c]) => ({ label: `${tag} (${c})`, value: tag }))}
							/>
						)}
						{caps.product?.stock && !serverFacets && (
							<button
								type="button"
								className={`pu-wc-cb-pill${inStock ? ' on' : ''}`}
								aria-pressed={inStock}
								onClick={() => setInStock((v) => !v)}
							>
								{__('In stock', 'product-upload')}
							</button>
						)}
						{caps.product?.compareAt && !serverFacets && (
							<button
								type="button"
								className={`pu-wc-cb-pill${onSale ? ' on' : ''}`}
								aria-pressed={onSale}
								onClick={() => setOnSale((v) => !v)}
							>
								{__('On sale', 'product-upload')}
							</button>
						)}
						{filtersOn && (
							<Button
								variant="link"
								onClick={() => {
									setClientFacets({});
									setServerFilters({});
									setInStock(false);
									setOnSale(false);
								}}
							>
								{__('Clear filters', 'product-upload')}
							</Button>
						)}
					</div>

					{discovering && entry && !entry.loading && !entry.error && (
						<div className="pu-wc-cb-discover">
							{entry.discover?.kind !== 'seller' &&
								(entry.discover?.suggestions?.length > 0 || recent.length > 0) && (
								<div className="pu-wc-cb-chips">
									{entry.discover?.suggestions?.length > 0 && (
										<span className="pu-wc-cb-chiphead">{__('For your store', 'product-upload')}</span>
									)}
									{entry.discover?.suggestions?.map((w) => (
										<button
											key={w}
											type="button"
											className="pu-wc-cb-pill"
											onClick={() => searchFor(w)}
										>
											{w}
										</button>
									))}
									{recent.length > 0 && (
										<span className="pu-wc-cb-chiphead">{__('Recent', 'product-upload')}</span>
									)}
									{recent.map((w) => (
										<button
											key={`r:${w}`}
											type="button"
											className="pu-wc-cb-pill"
											onClick={() => searchFor(w)}
										>
											<Icon icon={scheduled} size={20} />
											{w}
										</button>
									))}
								</div>
							)}
							{entry.discover?.label && (
								<h3 className="pu-wc-cb-discoverhead">{discoverHeading()}</h3>
							)}
						</div>
					)}
					{entry?.queryNote && (
						<div className="pu-wc-cb-note">
							{sprintf(
								__('Searched %1$s for “%2$s”, translated from %3$s', 'product-upload'),
								caps.source?.label,
								entry.queryNote.query,
								languageName(entry.queryNote.language)
							)}
						</div>
					)}
					{caps.tier === 'C' && (
						<div className="pu-wc-cb-note pu-wc-cb-warn">
							{__(
								'This site only publishes a list of product pages. Photos and prices load for the rows you can see, and every product is read in full when it imports.',
								'product-upload'
							)}
						</div>
					)}
					{entry?.limited && (
						<div className="pu-wc-cb-note pu-wc-cb-warn">
							{__(
								'This site blocks automated reads and we reached this hour’s limit for unblocking it. Try again later, or import products by pasting their links.',
								'product-upload'
							)}
						</div>
					)}
					{capsOverride && (
						<div className="pu-wc-cb-note">
							{__(
								'The full catalog stopped responding, so this list now comes from the site’s list of product pages.',
								'product-upload'
							)}
						</div>
					)}

					{statusBar}
					{body}
					{entry?.loading && products.length > 0 && !busy && (
						<div className="pu-wc-cb-loadmore">
							<Spinner />
							{__('Loading more…', 'product-upload')}
						</div>
					)}
					<div ref={sentinel} style={{ height: 1 }} />
				</div>
			</div>

			<div className="pu-wc-cb-foot">
				{selected > 0 && (
					<div className="pu-wc-cb-stack" aria-hidden="true">
						{[...basket.values()]
							.map((b) => merged(b.product))
							.filter((p) => p.imageUrl)
							.slice(0, 4)
							.map((p) => (
								<img key={p.url} src={proxyImage(thumbUrl(p.imageUrl, 96))} alt="" />
							))}
					</div>
				)}
				<div className="pu-wc-cb-foot-tx">
					<span className="pu-wc-cb-foot-l1">
						<strong>{selectedText(selected)}</strong>
						{selected > 0 && (
							<Button
								variant="link"
								onClick={() => {
									setBasket(new Map());
									lastIndex.current = null;
								}}
							>
								{__('Clear', 'product-upload')}
							</Button>
						)}
					</span>
					<span className={`pu-wc-cb-foot-l2${over ? ' crit' : ''}`}>
						{fromText}
						{footNote()}
					</span>
					{selected > 0 && (selected >= BIG_JOB_PRODUCTS || isTopPlan(planName)) && (
						<DoneForYouLink copy="bigJob" moment={over ? 'bulk-out-of-credits' : 'bulk-review'} />
					)}
				</div>
				<div className="pu-wc-cb-foot-actions">
					{over && (
						<Button variant="secondary" onClick={topUp}>
							{__('Upgrade plan', 'product-upload')}
						</Button>
					)}
					<Button variant="primary" disabled={!selected} onClick={importNow}>
						{over && balance > 0
							? sprintf(__('Import first %s', 'product-upload'), num(balance))
							: importText(selected)}
					</Button>
				</div>
			</div>
		</div>
	);
}

const SK_WIDTHS = [72, 54, 80, 60, 66, 48];

// first load: the browser's own layout, pulsing
function ShellSkeleton({ marketplaces, siteHost }) {
	return (
		<div
			className="pu-wc-cb-split"
			role="status"
			aria-busy="true"
			aria-label={__('Loading products', 'product-upload')}
		>
			<aside className="pu-wc-cb-rail">
				{marketplaces && (
					<>
						<div className="pu-wc-cb-railhead">{__('Marketplaces', 'product-upload')}</div>
						<div className="pu-wc-cb-mkts">
							{marketplaces.map((m) => (
								<MarketItem key={m.id} m={m} active={m.hostMatcher(siteHost)} onClick={() => {}} />
							))}
						</div>
					</>
				)}
				<div className="pu-wc-cb-railhead">
					{marketplaces ? __('Categories', 'product-upload') : __('Collections', 'product-upload')}
				</div>
				{SK_WIDTHS.map((w, i) => (
					<div key={i} className="pu-wc-cb-ri pu-wc-cb-ri-sk">
						<span className="pu-wc-cb-tile pu-wc-cb-sk" />
						<span className="pu-wc-cb-ri-name">
							<span className="pu-wc-cb-sk pu-wc-cb-sk-line" style={{ width: `${w}%` }} />
						</span>
					</div>
				))}
			</aside>
			<div className="pu-wc-cb-main">
				<div className="pu-wc-cb-bar">
					<span className="pu-wc-cb-sk pu-wc-cb-search" style={{ height: 40, borderRadius: 2 }} />
					<span className="pu-wc-cb-sk" style={{ width: 170, height: 32, borderRadius: 2 }} />
					<span className="pu-wc-cb-sk" style={{ width: 66, height: 32, borderRadius: 2 }} />
				</div>
				<div className="pu-wc-cb-filters">
					{[52, 96, 40].map((w) => (
						<span key={w} className="pu-wc-cb-sk" style={{ width: w, height: 26, borderRadius: 99 }} />
					))}
				</div>
				<div className="pu-wc-cb-selectall">
					<span className="pu-wc-cb-sk pu-wc-cb-sk-line" style={{ width: 120 }} />
				</div>
				<CardsSkeleton />
			</div>
		</div>
	);
}

function CardsSkeleton() {
	return (
		<div className="pu-wc-cb-grid" aria-hidden="true">
			{Array.from({ length: 8 }).map((_, i) => (
				<div className="pu-wc-cb-card" key={i}>
					<div className="pu-wc-cb-img pu-wc-cb-sk" />
					<div className="pu-wc-cb-ct">
						<span className="pu-wc-cb-sk pu-wc-cb-sk-line" style={{ width: '90%' }} />
						<span className="pu-wc-cb-sk pu-wc-cb-sk-line" style={{ width: '60%' }} />
						<span className="pu-wc-cb-sk pu-wc-cb-sk-line" style={{ width: '40%' }} />
					</div>
				</div>
			))}
		</div>
	);
}

const tileTone = (name) => {
	let h = 0;
	for (const ch of name) {
		h = (h * 31 + ch.charCodeAt(0)) >>> 0;
	}
	return `t${h % 6}`;
};

function RailItem({ id, name, count, image, child, active, picked, onClick }) {
	let tile;
	if (id === null) {
		tile = (
			<span className="pu-wc-cb-tile all">
				<Icon icon={category} size={20} />
			</span>
		);
	} else if (image) {
		tile = (
			<span className="pu-wc-cb-tile">
				<img src={proxyImage(thumbUrl(image, 96))} alt="" />
			</span>
		);
	} else {
		tile = (
			<span className={`pu-wc-cb-tile ${tileTone(name)}`}>
				{[...name.trim()][0]?.toUpperCase()}
			</span>
		);
	}
	return (
		<button
			type="button"
			className={`pu-wc-cb-ri${active ? ' on' : ''}${child ? ' child' : ''}`}
			onClick={onClick}
			aria-pressed={active}
		>
			{tile}
			<span className="pu-wc-cb-ri-name">{name}</span>
			<span className="pu-wc-cb-ri-end">
				{picked > 0 && (
					<span className="pu-wc-cb-picked" title={selectedText(picked)}>
						{picked}
					</span>
				)}
				{has(count) && <span className="pu-wc-cb-num">{num(count)}</span>}
			</span>
		</button>
	);
}

const SELLER_EXAMPLE = {
	aliexpress: 'aliexpress.com/store/1104956140',
	amazon: 'amazon.com/s?me=A2L77EE7U53NWQ',
	alibaba: 'supplier.en.alibaba.com',
	etsy: 'etsy.com/shop/ShopName',
};

function AddSeller({ market, onAdded }) {
	const [open, setOpen] = useState(false);
	const [value, setValue] = useState('');
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const close = () => {
		setOpen(false);
		setValue('');
		setError('');
	};
	const submit = async () => {
		if (!value.trim()) {
			setError(__('Paste a link first', 'product-upload'));
			return;
		}
		setBusy(true);
		try {
			const data = await postJson('sellers', { url: value.trim(), marketplace: market.id });
			if (!data?.seller) {
				throw new Error('not_a_seller');
			}
			onAdded(data.seller);
			close();
		} catch (e) {
			setError(
				/not_a_seller/.test(String(e?.message))
					? sprintf(__('That isn’t a store or seller link from %s', 'product-upload'), market.displayName)
					: __('Couldn’t add that seller. Try again.', 'product-upload')
			);
		} finally {
			setBusy(false);
		}
	};
	if (!open) {
		return (
			<button
				type="button"
				className="pu-wc-cb-ri pu-wc-cb-addseller"
				onClick={() => setOpen(true)}
			>
				<span className="pu-wc-cb-tile add">
					<Icon icon={plus} size={20} />
				</span>
				<span className="pu-wc-cb-ri-name">{__('Add seller', 'product-upload')}</span>
			</button>
		);
	}
	return (
		<div className={`pu-wc-cb-sellerform${error ? ' has-error' : ''}`}>
			<TextControl
				__nextHasNoMarginBottom
				label={__('Add seller', 'product-upload')}
				hideLabelFromVision
				placeholder={SELLER_EXAMPLE[market.id]}
				help={error || __('Paste a store or seller link from this marketplace.', 'product-upload')}
				value={value}
				onChange={(v) => {
					setValue(v);
					setError('');
				}}
				onKeyDown={(e) => {
					if (e.key === 'Enter') {
						submit();
					}
					if (e.key === 'Escape') {
						e.stopPropagation();
						close();
					}
				}}
				autoComplete="off"
			/>
			<div className="pu-wc-cb-sellerform-actions">
				<Button variant="primary" size="small" isBusy={busy} disabled={busy} onClick={submit}>
					{__('Add', 'product-upload')}
				</Button>
				<Button variant="tertiary" size="small" onClick={close}>
					{__('Cancel', 'product-upload')}
				</Button>
			</div>
		</div>
	);
}

// obscure glyphs: the name, white on brand
const WORDMARKS = { alibaba: 'Alibaba' };

function MarketItem({ m, active, onClick }) {
	const [broken, setBroken] = useState(false);
	const full = FULL_TILE_LOGOS.has(m.id) && !broken;
	const word = WORDMARKS[m.id];
	const src = word ? null : SUPPLIER_LOGO_SRC[m.id];
	return (
		<button
			type="button"
			className={`pu-wc-cb-mkt${active ? ' on' : ''}`}
			style={{ background: m.iconBg }}
			onClick={onClick}
			aria-pressed={active}
			aria-label={m.displayName}
			title={m.displayName}
		>
			{src && !broken ? (
				<img
					src={src}
					alt=""
					style={{ padding: full ? 0 : 6, filter: full ? 'none' : 'brightness(0) invert(1)' }}
					onError={() => setBroken(true)}
				/>
			) : (
				<span className={`pu-wc-cb-mkt-word${word ? ' long' : ''}`} style={{ color: m.iconFg }}>
					{word ?? m.iconLabel}
				</span>
			)}
		</button>
	);
}

function MarketDown({ name, alt, onRetry, onSwitch }) {
	return (
		<div className="pu-wc-cb-center">
			<h3>{sprintf(__('%s search isn’t responding right now', 'product-upload'), name)}</h3>
			<p>{__('Your search is fine. Try again in a moment, or search another marketplace.', 'product-upload')}</p>
			<div className="pu-wc-cb-actions">
				<Button variant="secondary" onClick={onRetry}>
					{__('Try again', 'product-upload')}
				</Button>
				{alt && (
					<Button variant="primary" onClick={onSwitch}>
						{sprintf(__('Search %s instead', 'product-upload'), alt.displayName)}
					</Button>
				)}
			</div>
		</div>
	);
}

function FilterPill({ label, note, value, options, onChange }) {
	return (
		<span className={`pu-wc-cb-pill pu-wc-cb-sel${value ? ' on' : ''}`}>
			<select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
				<option value="">{label}</option>
				{note && (
					<option value="__note" disabled>
						{note}
					</option>
				)}
				{options.map((o) => (
					<option key={o.value} value={o.value}>
						{o.label}
					</option>
				))}
			</select>
			<span className="pu-wc-cb-chev">
				<Icon icon={chevronDown} size={20} />
			</span>
		</span>
	);
}

function Price({ p, caps }) {
	if (!has(p.price)) {
		return caps.enrich ? <Skel w={44} h={10} /> : null;
	}
	// supplier currency: show the store's, roughly
	const rate =
		p.currency && p.currency !== caps.fx?.currency ? caps.fx?.rates?.[p.currency] : null;
	if (rate) {
		return (
			<span className="pu-wc-cb-price pu-wc-cb-fx">
				<span className="pu-wc-cb-now">
					{`≈ ${fmtMoney(p.price * rate, caps.fx.currency)}`}
				</span>
				<span className="pu-wc-cb-orig">{fmtMoney(p.price, p.currency)}</span>
			</span>
		);
	}
	return (
		<span className="pu-wc-cb-price">
			{fmtMoney(p.price, p.currency)}
			{has(p.compareAtPrice) && <s>{fmtMoney(p.compareAtPrice, p.currency)}</s>}
		</span>
	);
}

function Meta({ p }) {
	if (!p.rating && !p.sales) {
		return null;
	}
	const sold =
		p.sales && sprintf(__('%s sold', 'product-upload'), typeof p.sales === 'number' ? num(p.sales) : p.sales);
	return (
		<span className="pu-wc-cb-meta">
			{p.rating && (
				<span className="pu-wc-cb-rating">
					<Icon icon={starFilled} size={16} />
					{p.rating}
				</span>
			)}
			{p.rating && sold && ' · '}
			{sold}
		</span>
	);
}

const keyToggle = (onToggle) => (e) => {
	if (e.key === ' ' || e.key === 'Enter') {
		e.preventDefault();
		onToggle(e);
	}
};

function OpenOriginal({ url }) {
	return (
		<a
			className="pu-wc-cb-open"
			href={url}
			target="_blank"
			rel="noopener noreferrer"
			aria-label={__('View original', 'product-upload')}
			title={__('View original', 'product-upload')}
			onClick={(e) => e.stopPropagation()}
		>
			<Icon icon={external} size={20} />
		</a>
	);
}

function ViewImported({ id }) {
	return (
		<a
			href={productEditUrl(id)}
			target="_blank"
			rel="noopener noreferrer"
			onClick={(e) => e.stopPropagation()}
		>
			{__('View imported', 'product-upload')}
		</a>
	);
}

function ProductCard({ p, caps, selected, onToggle }) {
	const soldOut = p.available === false;
	let badge = null;
	if (p.imported) {
		badge = __('Imported', 'product-upload');
	} else if (soldOut) {
		badge = __('Sold out', 'product-upload');
	} else if (has(p.compareAtPrice)) {
		badge = __('Sale', 'product-upload');
	}
	return (
		<div
			role="checkbox"
			aria-checked={selected}
			tabIndex={0}
			className={`pu-wc-cb-card${selected ? ' on' : ''}${p.imported || soldOut ? ' dim' : ''}`}
			onClick={onToggle}
			onKeyDown={keyToggle(onToggle)}
		>
			<span className="pu-wc-cb-check">{selected && <Icon icon={check} size={20} />}</span>
			{badge && (
				<span className={`pu-wc-cb-badge${!p.imported && !soldOut ? ' sale' : ''}`}>{badge}</span>
			)}
			<div className="pu-wc-cb-img">
				{p.imageUrl ? (
					<img src={proxyImage(thumbUrl(p.imageUrl, THUMB))} alt="" loading="lazy" />
				) : null}
				<OpenOriginal url={p.url} />
			</div>
			<div className="pu-wc-cb-ct">
				<span className="pu-wc-cb-name">{p.name}</span>
				<span className="pu-wc-cb-line">
					<Price p={p} caps={caps} />
					{p.variantCount > 1 && (
						<span className="pu-wc-cb-sub">{variantsText(p.variantCount)}</span>
					)}
				</span>
				<Meta p={p} />
				{p.externalId && <ViewImported id={p.externalId} />}
			</div>
		</div>
	);
}

function ProductRow({ p, caps, selected, onToggle }) {
	let path = p.url;
	try {
		path = new URL(p.url).pathname;
	} catch {
		// keep the raw url
	}
	const soldOut = p.available === false;
	const dot = ' · ';
	// status first so the path is what truncates
	const parts = [
		p.imported && __('Imported', 'product-upload'),
		soldOut && __('Sold out', 'product-upload'),
		has(p.compareAtPrice) && !soldOut && <span className="crit">{__('Sale', 'product-upload')}</span>,
		p.variantCount > 1 && variantsText(p.variantCount),
		p.externalId && <ViewImported id={p.externalId} />,
		path,
	].filter(Boolean);
	return (
		<div
			role="checkbox"
			aria-checked={selected}
			tabIndex={0}
			className={`pu-wc-cb-row${selected ? ' on' : ''}${p.imported || soldOut ? ' dim' : ''}`}
			onClick={onToggle}
			onKeyDown={keyToggle(onToggle)}
		>
			<span className="pu-wc-cb-check">{selected && <Icon icon={check} size={20} />}</span>
			<div className="pu-wc-cb-thumb">
				{p.imageUrl ? (
					<img src={proxyImage(thumbUrl(p.imageUrl, 96))} alt="" loading="lazy" />
				) : null}
			</div>
			<div className="pu-wc-cb-rowtx">
				<span className="pu-wc-cb-name">{p.name}</span>
				<span className="pu-wc-cb-path">
					{parts.map((part, k) => (
						<span key={k}>
							{k > 0 && dot}
							{part}
						</span>
					))}
				</span>
			</div>
			<span className="pu-wc-cb-rowprice">
				<Price p={p} caps={caps} />
			</span>
			<OpenOriginal url={p.url} />
		</div>
	);
}

function EmptyView({ allImported, onShowImported }) {
	return (
		<div className="pu-wc-cb-center">
			<p className="pu-wc-cb-strong">
				{allImported ? __('You’re all caught up', 'product-upload') : __('No products match', 'product-upload')}
			</p>
			{allImported && (
				<Button variant="secondary" onClick={onShowImported}>
					{__('Show imported products', 'product-upload')}
				</Button>
			)}
		</div>
	);
}

function CantListScreen({ reason, site, onImport, onClose, onCategoryFallback }) {
	const [links, setLinks] = useState('');
	const [categoryUrl, setCategoryUrl] = useState('');
	const urls = links
		.split(/\s+/)
		.map((s) => s.trim())
		.filter(isHttpUrl);
	const why =
		{
			blocked: __(
				'Its security blocks automated catalog reads. Products still import fine one page at a time.',
				'product-upload'
			),
			limited: __(
				'Its security blocks automated reads and we reached this hour’s limit for unblocking it. You can still import products by link.',
				'product-upload'
			),
		}[reason] ??
		__(
			'It doesn’t publish a list of its products we can read. Products still import fine one page at a time.',
			'product-upload'
		);
	const name = site.name || hostOf(site.url);
	const base = (site.url?.startsWith('http') ? site.url : `https://${site.url}`).replace(/\/+$/, '');
	return (
		<div className="pu-wc-cl">
			<div className="pu-wc-cl-hero">
				<span className="pu-wc-cl-icon warn">
					<Icon icon={shield} size={24} />
				</span>
				<div>
					<h3>
						{sprintf(
							__('We can’t browse %s’s catalog, but you can still import from it', 'product-upload'),
							name
						)}
					</h3>
					<p>{why}</p>
				</div>
			</div>

			<div className="pu-wc-cl-opts">
				<div className="pu-wc-cl-opt">
					<OptionHead icon={link} tone="success" title={__('Paste product links', 'product-upload')} />
					<TextareaControl
						__nextHasNoMarginBottom
						label={__('Paste product links', 'product-upload')}
						hideLabelFromVision
						help={__('One per line. Each is imported in full.', 'product-upload')}
						placeholder={`${base}/…\n${base}/…\n${base}/…`}
						value={links}
						onChange={setLinks}
						rows={4}
					/>
					<div className="pu-wc-cl-opt-end">
						<Button
							variant="primary"
							disabled={!urls.length}
							onClick={() => {
								onImport(urls);
								onClose();
							}}
						>
							{importText(urls.length)}
						</Button>
					</div>
				</div>
				{onCategoryFallback && (
					<div className="pu-wc-cl-opt">
						<OptionHead icon={page} tone="info" title={__('Import a category page', 'product-upload')} />
						<TextControl
							__nextHasNoMarginBottom
							label={__('Import a category page', 'product-upload')}
							hideLabelFromVision
							help={__(
								'Paste a category or search results link. We read the products on that page.',
								'product-upload'
							)}
							placeholder={`${base}/…`}
							value={categoryUrl}
							onChange={setCategoryUrl}
							autoComplete="off"
						/>
						<div className="pu-wc-cl-opt-end">
							<Button
								variant="secondary"
								disabled={!isHttpUrl(categoryUrl.trim())}
								onClick={() => onCategoryFallback(categoryUrl.trim())}
							>
								{__('Import this page', 'product-upload')}
							</Button>
						</div>
					</div>
				)}
			</div>

			<div className="pu-wc-cl-more">
				<a className="pu-wc-cl-card info" href={CWS_URL} target="_blank" rel="noopener noreferrer">
					<span className="pu-wc-cl-icon">
						<Icon icon={external} size={20} />
					</span>
					<span className="pu-wc-cl-tx">
						<strong>{__('Browse with the Chrome extension', 'product-upload')}</strong>
						<span>
							{__(
								'Shop the site normally and add products as you go. Works on sites that block scans.',
								'product-upload'
							)}
						</span>
					</span>
					<span className="pu-wc-cl-cta">
						{__('Get the extension', 'product-upload')}
						<Icon icon={external} size={20} />
					</span>
				</a>
				<a
					className="pu-wc-cl-card magic"
					href={doneForYouUrl('bulk-cant-list')}
					target="_blank"
					rel="noopener noreferrer"
				>
					<span className="pu-wc-cl-icon">
						<Icon icon={people} size={20} />
					</span>
					<span className="pu-wc-cl-tx">
						<strong>{__('Have us set it up', 'product-upload')}</strong>
						<span>{__('For large catalogs, our team imports it for you.', 'product-upload')}</span>
					</span>
					<span className="pu-wc-cl-cta">
						{__('Done-for-you imports', 'product-upload')}
						<Icon icon={external} size={20} />
					</span>
				</a>
			</div>
		</div>
	);
}

function OptionHead({ icon, title, tone }) {
	return (
		<div className="pu-wc-cl-head">
			<span className={`pu-wc-cl-icon ${tone}`}>
				<Icon icon={icon} size={20} />
			</span>
			<strong>{title}</strong>
		</div>
	);
}
