/**
 * Per-supplier import history: a flat list of the products this shop has
 * imported from one supplier (distinct from the global Completed tab, which
 * is grouped by job). Mirrors the Shopify app's RecentlyImportedTab: thumb +
 * name + "Imported {when}", "Load more" by 20, links to the WooCommerce edit
 * screen and the original source page, with skeleton + empty states.
 *
 * @package ProductUploadForWoocommerce
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { ItemRowsSkeleton } from './Skeletons';

const PAGE_SIZE = 20;

// Woo edit screen for a post
function editUrl(externalId) {
	if (!externalId) {
		return null;
	}
	const id = String(externalId).split('/').pop();
	if (!id || !/^\d+$/.test(id)) {
		return null;
	}
	const base = (window.puWCAdmin && window.puWCAdmin.adminPostUrl) || '/wp-admin/post.php';
	return `${base}?post=${id}&action=edit`;
}

function relativeTime(iso) {
	if (!iso) {
		return __('recently', 'product-upload');
	}
	const diffDays = Math.ceil(Math.abs(Date.now() - new Date(iso).getTime()) / 86400000);
	return diffDays <= 1 ? __('today', 'product-upload') : `${diffDays} ${__('days ago', 'product-upload')}`;
}

export default function SupplierHistory({ supplier, onBulkImport }) {
	const [products, setProducts] = useState([]);
	const [page, setPage] = useState(1);
	const [loading, setLoading] = useState(true);
	const [errored, setErrored] = useState(false);
	const [moreError, setMoreError] = useState(false);
	const aliveRef = useRef(true);

	useEffect(() => {
		aliveRef.current = true;
		setProducts([]);
		setPage(1);
		setErrored(false);
		void load(1, true);
		return () => { aliveRef.current = false; };
		// eslint-disable-next-line
	}, [supplier?.id]);

	const load = async (pageNum, replace) => {
		setLoading(true);
		if (!replace) {
			setMoreError(false);
		}
		try {
			const body = await apiFetch({
				path: `/product-upload/v1/supplier-products?supplier_id=${encodeURIComponent(supplier?.id)}&page=${pageNum}`,
			});
			if (!aliveRef.current) {
				return;
			}
			const rows = Array.isArray(body?.data) ? body.data : [];
			setProducts((prev) => (replace ? rows : [...prev, ...rows]));
		} catch {
			if (!aliveRef.current) {
				return;
			}
			if (replace) {
				setErrored(true);
			} else {
				setMoreError(true);
			}
		} finally {
			if (aliveRef.current) {
				setLoading(false);
			}
		}
	};

	if (loading && products.length === 0) {
		return (
			<div className="pu-wc-hist">
				<ItemRowsSkeleton rows={5} />
			</div>
		);
	}

	if (products.length === 0) {
		return (
			<div className="pu-wc-hist__empty">
				<span className="pu-wc-hist__empty-chip" aria-hidden="true">
					<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
						<path d="M3 7.5l9-4.5 9 4.5v9l-9 4.5-9-4.5z M3 7.5l9 4.5 9-4.5 M12 12v9" />
					</svg>
				</span>
				<h3>{errored ? __('Could not load history', 'product-upload') : __('No imports yet', 'product-upload')}</h3>
				<p>
					{errored
						? __('Something went wrong loading this supplier’s imports.', 'product-upload')
						: `${__('Products you import from', 'product-upload')} ${supplier?.name || supplier?.url} ${__('will show up here.', 'product-upload')}`}
				</p>
				{errored ? (
					<Button onClick={() => load(1, true)}>{__('Try again', 'product-upload')}</Button>
				) : (
					onBulkImport && (
						<Button variant="primary" onClick={() => onBulkImport(supplier?.url)}>
							{__('Browse products to import', 'product-upload')}
						</Button>
					)
				)}
			</div>
		);
	}

	const canLoadMore = products.length % PAGE_SIZE === 0 && products.length !== 0;

	return (
		<div className="pu-wc-hist">
			<ul className="pu-wc-hist__list">
				{products.map((p) => {
					const img = p.product_media?.[0]?.src_image_url;
					const edit = editUrl(p.external_product_id);
					return (
						<li className="pu-wc-hist__row" key={p.id}>
							<span className="pu-wc-hist__thumb">
								{img ? <img src={img} alt="" loading="lazy" /> : <span className="pu-wc-hist__thumb-ph" />}
							</span>
							<span className="pu-wc-hist__info">
								<span className="pu-wc-hist__name">{p.product_name || __('Untitled product', 'product-upload')}</span>
								<span className="pu-wc-hist__when">{__('Imported', 'product-upload')} {relativeTime(p.created_at)}</span>
							</span>
							<span className="pu-wc-hist__actions">
								{p.product_source_url && (
									<a className="pu-wc-hist__link" href={p.product_source_url} target="_blank" rel="noreferrer">
										{__('Original', 'product-upload')}
									</a>
								)}
								{edit && (
									<a className="pu-wc-hist__link" href={edit} target="_blank" rel="noreferrer">
										{__('View', 'product-upload')}
									</a>
								)}
							</span>
						</li>
					);
				})}
			</ul>
			<div className="pu-wc-hist__more">
				{moreError && !loading && (
					<span className="pu-wc-hist__more-err">{__('Couldn’t load more. Try again.', 'product-upload')}</span>
				)}
				{canLoadMore && !loading && (
					<Button
						variant="secondary"
						onClick={() => { const next = page + 1; setPage(next); void load(next, false); }}
					>
						{moreError ? __('Try again', 'product-upload') : __('Load more', 'product-upload')}
					</Button>
				)}
				{loading && (
					<span className="pu-wc-hist__loading"><Spinner /> {__('Loading products…', 'product-upload')}</span>
				)}
			</div>
		</div>
	);
}
