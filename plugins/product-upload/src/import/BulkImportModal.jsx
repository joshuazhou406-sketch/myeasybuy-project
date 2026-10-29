import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner, TextControl } from '@wordpress/components';
import { Icon, chevronRight } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import CatalogBrowser, { ModalHeader } from './CatalogBrowser';
import {
	FULL_TILE_LOGOS,
	SUPPLIER_LOGO_SRC,
	clientSearchSuppliers,
	getClientSearchSupplier,
} from './searchSuppliersClient';
import { classifyBulkUrl } from './utils/classifyBulkUrl.mjs';

// still marketplace-only, never scanned
const EARLY_ACCESS_SUPPLIERS = ['temu', 'zalando', 'shopee'];

const displayDomain = (raw) => raw.replace(/^https?:\/\//, '').replace(/^www\./, '');

// pasted URL -> site to browse and how to open it
export function bulkTarget(raw) {
	let uri;
	try {
		uri = new URL(/^https?:\/\//i.test(raw) ? raw : `https://${raw}`);
	} catch {
		return null;
	}
	const c = classifyBulkUrl(uri.href);
	// s.taobao.com, nl.aliexpress.com -> its main row
	const home = c.isMarketplaceSearch && getClientSearchSupplier(uri.href)?.homepageUrl;
	const host = (home ? new URL(home) : uri).hostname;
	let options = { mode: 'all' };
	if (c.isMarketplaceSearch) {
		options = { mode: 'search', query: c.searchQuery, sourceUrl: uri.href };
	} else if (c.isCollection || c.isProductCategory || c.isMarketplaceSeller) {
		options = { mode: 'collection', collectionId: c.collectionId, sourceUrl: uri.href };
	}
	return { site: { name: host.replace(/^www\./, ''), url: `https://${host}` }, options };
}

function MarketplaceTile({ supplier, onSelect }) {
	const [errored, setErrored] = useState(false);
	const fullTile = FULL_TILE_LOGOS.has(supplier.id) && !errored;
	// Alibaba's glyph is obscure: its name instead
	const word = supplier.id === 'alibaba' ? 'Alibaba' : null;
	const src = word ? null : SUPPLIER_LOGO_SRC[supplier.id];
	return (
		<button
			type="button"
			className="pu-wc-mkt-tile"
			onClick={onSelect}
			aria-label={sprintf(__('Bulk import from %s', 'product-upload'), supplier.displayName)}
			title={supplier.displayName}
		>
			<span
				className={`pu-wc-mkt-tile__logo${fullTile ? ' pu-wc-mkt-tile__logo--full' : ''}`}
				style={{ background: supplier.iconBg }}
			>
				{src && !errored ? (
					<img
						src={src}
						alt=""
						style={{ filter: fullTile ? 'none' : 'brightness(0) invert(1)' }}
						onError={() => setErrored(true)}
					/>
				) : (
					<span style={{ fontWeight: 700, fontSize: word ? 9 : 11, color: supplier.iconFg }}>
						{word ?? supplier.iconLabel}
					</span>
				)}
			</span>
			<span className="pu-wc-mkt-tile__label">{supplier.displayName}</span>
		</button>
	);
}

function Landing({ onPick, onClose }) {
	const [suppliers, setSuppliers] = useState(null);
	const [websiteUrl, setWebsiteUrl] = useState('');
	const [error, setError] = useState(null);

	useEffect(() => {
		apiFetch({ path: '/product-upload/v1/suppliers' })
			.then((res) => setSuppliers(res.data ?? []))
			.catch(() => setSuppliers([]));
	}, []);

	const submit = () => {
		const raw = websiteUrl.trim();
		if (!raw) {
			return;
		}
		if (!onPick(raw)) {
			setError(__('Please enter a valid URL.', 'product-upload'));
		}
	};

	if (suppliers === null) {
		return (
			<>
				<ModalHeader onClose={onClose} />
				<div className="pu-wc-scan__loading">
					<Spinner />
				</div>
			</>
		);
	}

	return (
		<>
		<ModalHeader onClose={onClose} />
		<div className="pu-wc-scan__body">
			<div style={{ padding: '8px 24px 4px' }}>
				<div className="pu-wc-mkt-tile-row pu-wc-mkt-tile-row--compact">
					{clientSearchSuppliers.map((s) => (
						<MarketplaceTile key={s.id} supplier={s} onSelect={() => onPick(s.homepageUrl)} />
					))}
				</div>
			</div>

			{suppliers.map((supplier) => {
				const domain = displayDomain(supplier.url || '');
				return (
					<button
						key={supplier.id}
						className="pu-wc-scan__list-item"
						onClick={() => onPick(supplier.url)}
						type="button"
					>
						<div className="pu-wc-scan__list-favicon">
							<img
								src={`https://icons.duckduckgo.com/ip2/${domain}.ico`}
								alt=""
								onError={(e) => {
									e.currentTarget.style.display = 'none';
								}}
							/>
						</div>
						<div className="pu-wc-scan__list-info">
							<div className="pu-wc-scan__list-domain">{domain}</div>
							{supplier.product_count !== null && supplier.product_count !== undefined && (
								<div className="pu-wc-scan__list-count">
									{sprintf(
										_n(
											'%s product added',
											'%s products added',
											supplier.product_count,
											'product-upload'
										),
										supplier.product_count
									)}
								</div>
							)}
						</div>
						<span className="pu-wc-scan__list-chevron">
							<Icon icon={chevronRight} size={20} />
						</span>
					</button>
				);
			})}

			{suppliers.length === 0 && (
				<div className="pu-wc-scan__empty-state">
					<p>
						{__('No suppliers yet. Add your first supplier to get started.', 'product-upload')}
					</p>
				</div>
			)}

			{error && (
				<div style={{ padding: '0 24px 16px' }}>
					<Notice status="error" isDismissible={false}>
						{error}
					</Notice>
				</div>
			)}

			<div className="pu-wc-scan__add-website">
				<label className="pu-wc-scan__add-website-label" htmlFor="pu-wc-add-website-input">
					{__('Add website', 'product-upload')}
				</label>
				<div className="pu-wc-scan__add-website-row">
					<TextControl
						id="pu-wc-add-website-input"
						value={websiteUrl}
						onChange={(v) => {
							setWebsiteUrl(v);
							setError(null);
						}}
						onKeyDown={(e) => e.key === 'Enter' && submit()}
						placeholder="https://mysupplier.com"
						__nextHasNoMarginBottom
					/>
					<Button variant="primary" onClick={submit} disabled={!websiteUrl.trim()}>
						{__('Scan', 'product-upload')}
					</Button>
				</div>
			</div>
		</div>
		</>
	);
}

function NoCatalog({ site, onBack, onClose }) {
	return (
		<div className="pu-wc-cb">
			<ModalHeader subtitle={site.name} onBack={onBack} onClose={onClose} />
			<div className="pu-wc-cb-center">
				<h3>
					{sprintf(__('No products found to bulk import from %s', 'product-upload'), site.name)}
				</h3>
				<p>
					{__(
						'We scanned this site but couldn’t pull a catalog to browse. You can still import individual products, or paste a specific category or collection URL to grab those.',
						'product-upload'
					)}
				</p>
			</div>
		</div>
	);
}

const BulkImportModal = ({
	onClose,
	balance,
	onImport,
	onCategoryFallback,
	onUpgrade,
	initialUrl,
	planName,
	currency,
}) => {
	const [target, setTarget] = useState(() => (initialUrl ? bulkTarget(initialUrl) : null));
	// marketplace switches keep the shell mounted
	const [switched, setSwitched] = useState(null);

	const pick = (raw) => {
		const next = bulkTarget(raw);
		if (!next) {
			return false;
		}
		setSwitched(null);
		setTarget(next);
		return true;
	};
	const back = () => {
		setSwitched(null);
		setTarget(null);
	};

	const site = switched?.site ?? target?.site;
	const searchSupplier = target && getClientSearchSupplier(target.site.url);
	const earlyAccess =
		!!site && EARLY_ACCESS_SUPPLIERS.some((b) => site.name.toLowerCase().includes(b));

	let body;
	if (!target) {
		body = <Landing onPick={pick} onClose={onClose} />;
	} else if (earlyAccess) {
		body = <NoCatalog site={site} onBack={back} onClose={onClose} />;
	} else {
		body = (
			<CatalogBrowser
				key={searchSupplier ? 'marketplaces' : target.site.url}
				site={site}
				bulkOptions={target.options}
				marketplaces={searchSupplier ? clientSearchSuppliers : null}
				initialQuery={switched?.query ?? target.options.query}
				onSwitchSite={(s, query) =>
					setSwitched({
						site: { name: new URL(s.homepageUrl).hostname.replace(/^www\./, ''), url: s.homepageUrl },
						query,
					})
				}
				onBack={back}
				onImport={onImport}
				onClose={onClose}
				onCategoryFallback={onCategoryFallback}
				onUpgrade={onUpgrade}
				balance={balance}
				currency={currency}
				planName={planName}
			/>
		);
	}

	return (
		<Modal
			contentLabel={__('Bulk import', 'product-upload')}
			onRequestClose={onClose}
			className={target ? 'pu-wc-bulk-modal' : 'pu-wc-scan-modal'}
			isFullScreen={false}
			__experimentalHideHeader
		>
			{body}
		</Modal>
	);
};

export default BulkImportModal;
