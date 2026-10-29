/**
 * Product Upload for WooCommerce – category triage modal.
 *
 * Surfaced when /api/external/query (via the PHP ApiClient with
 * supports_category=1) detects the pasted URL was a category/listing page
 * rather than a single product. Backend returns a list of extracted product
 * URLs; the user picks which to import (default: all selected) and the
 * parent re-queues each through the normal single-URL import flow.
 *
 * Mirrors the Shopify gadget's CategoryTriageModal.jsx and the Wix
 * dashboard modal (modals/category-triage/modal.tsx) so the experience is
 * consistent across all surfaces.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice } from '@wordpress/components';
import DoneForYouLink, { BIG_JOB_PRODUCTS, isTopPlan } from './DoneForYouLink';

const CategoryTriageModal = ({
	sourceUrl,
	products,
	balance,
	onClose,
	onImport,
	onUpgrade,
	upgrading,
	planName,
}) => {
	const total = products.length;
	const balanceSafe = Math.max(0, Number(balance) || 0);

	// Default everything selected — the LLM already filtered the page down
	// to product cards, so explicit deselect is more honest than the inverse.
	const [selectedUrls, setSelectedUrls] = useState(
		() => new Set(products.map((p) => p.url))
	);
	const [showDetails, setShowDetails] = useState(false);

	// Re-seed if the modal is reopened with a different product set.
	useEffect(() => {
		setSelectedUrls(new Set(products.map((p) => p.url)));
		setShowDetails(false);
	}, [products]);

	const selectedCount = selectedUrls.size;
	const noneSelected = selectedCount === 0;
	const overQuota = selectedCount > balanceSafe;

	const orderedSelected = useMemo(
		() => products.map((p) => p.url).filter((u) => selectedUrls.has(u)),
		[products, selectedUrls]
	);

	const toggleOne = (url) =>
		setSelectedUrls((prev) => {
			const next = new Set(prev);
			if (next.has(url)) {
				next.delete(url);
			} else {
				next.add(url);
			}
			return next;
		});

	const selectAll = () =>
		setSelectedUrls(new Set(products.map((p) => p.url)));
	const deselectAll = () => setSelectedUrls(new Set());

	const handleConfirm = () => {
		if (noneSelected || overQuota) {
			return;
		}
		onImport(orderedSelected);
	};

	const primaryLabel = noneSelected
		? __('Select at least one', 'product-upload')
		: sprintf(
				/* translators: %d: number of selected products */
				selectedCount === 1
					? __('Import %d product', 'product-upload')
					: __('Import %d products', 'product-upload'),
				selectedCount
		  );

	return (
		<Modal
			title={__('Category import', 'product-upload')}
			onRequestClose={onClose}
			className="pu-wc-category-modal"
			isFullScreen={false}
		>
			<div className="pu-wc-category__body">
				{/* Heads-up banner — keeps users oriented on the current
					 limitations of category import without an EXPERIMENTAL
					 chip eating the left column. */}
				<div className="pu-wc-category__banner">
					<strong>
						{__(
							'Category import is experimental',
							'product-upload'
						)}
					</strong>
					<p>
						{__(
							'First page only — no pagination or infinite scroll yet. Pick what you want; the rest stays untouched.',
							'product-upload'
						)}
					</p>
					<Button
						variant="link"
						onClick={() => setShowDetails((v) => !v)}
						size="small"
					>
						{showDetails
							? __('Hide tips', 'product-upload')
							: __(
									'Tips for getting more products',
									'product-upload'
							  )}
					</Button>
					{showDetails && (
						<div className="pu-wc-category__tips">
							<p>
								<strong>
									{__('More pages:', 'product-upload')}
								</strong>{' '}
								{__(
									"paste each page's URL separately (look for ?page=2 or a Next link on the supplier site).",
									'product-upload'
								)}
							</p>
							<p>
								<strong>
									{__(
										'Narrower URLs work best',
										'product-upload'
									)}
								</strong>{' '}
								{__(
									'— categories filtered by brand, colour, or size return more relevant items than broad top-level URLs.',
									'product-upload'
								)}
							</p>
							<p>
								<strong>
									{__(
										'Missing a product?',
										'product-upload'
									)}
								</strong>{' '}
								{__(
									'Sites that load on scroll may hide items from us. Paste a more specific URL.',
									'product-upload'
								)}
							</p>
							<p className="pu-wc-category__tip-footer">
								{__('Hit a bug? ', 'product-upload')}
								<a href="mailto:hello@productupload.co?subject=Category%20import%20bug">
									{__('Email us', 'product-upload')}
								</a>
							</p>
						</div>
					)}
				</div>

				{/* Source URL provenance */}
				{sourceUrl && (
					<div className="pu-wc-category__source">
						<span>
							{__('Found at:', 'product-upload')}
						</span>
						<span className="pu-wc-category__source-url" title={sourceUrl}>
							{sourceUrl}
						</span>
					</div>
				)}

				{/* Action row */}
				<div className="pu-wc-category__action-row">
					<div className="pu-wc-category__action-buttons">
						<Button
							variant="link"
							size="small"
							onClick={selectAll}
							disabled={selectedCount === total}
						>
							{__('Select all', 'product-upload')}
						</Button>
						<Button
							variant="link"
							size="small"
							onClick={deselectAll}
							disabled={noneSelected}
						>
							{__('Deselect all', 'product-upload')}
						</Button>
					</div>
					<div className="pu-wc-category__count">
						{sprintf(
							/* translators: 1: selected count, 2: total count */
							__('%1$d of %2$d selected', 'product-upload'),
							selectedCount,
							total
						)}
					</div>
				</div>

				{/* Over-quota warning */}
				{overQuota && (
					<div style={{ padding: '0 24px 8px' }}>
						<Notice status="warning" isDismissible={false}>
							{sprintf(
								/* translators: 1: balance, 2: deselect count */
								__(
									'You have %1$d credits left — deselect %2$d to import the full selection or top up.',
									'product-upload'
								),
								balanceSafe,
								selectedCount - balanceSafe
							)}
						</Notice>
					</div>
				)}

				{(selectedCount >= BIG_JOB_PRODUCTS || isTopPlan(planName)) && (
					<div style={{ padding: '0 24px 8px' }}>
						<DoneForYouLink
							copy="bigJob"
							moment={overQuota ? 'category-out-of-credits' : 'bulk-review'}
						/>
					</div>
				)}

				{/* Product list */}
				{total === 0 ? (
					<div className="pu-wc-category__empty">
						{__(
							'No products to import.',
							'product-upload'
						)}
					</div>
				) : (
					<table className="pu-wc-category__table">
						<thead>
							<tr>
								<th style={{ width: '32px' }}>
									<input
										type="checkbox"
										checked={selectedCount === total}
										ref={(el) => {
											if (el) {
												el.indeterminate =
													selectedCount > 0 && selectedCount < total;
											}
										}}
										onChange={(e) => {
											if (e.target.checked) {
												selectAll();
											} else {
												deselectAll();
											}
										}}
									/>
								</th>
								<th>{__('Product', 'product-upload')}</th>
								<th style={{ width: '60px' }}>
									{__('Link', 'product-upload')}
								</th>
							</tr>
						</thead>
						<tbody>
							{products.map((p) => {
								const isSelected = selectedUrls.has(p.url);
								return (
									<tr
										key={p.url}
										className={
											isSelected
												? 'pu-wc-category__row--selected'
												: ''
										}
										onClick={() => toggleOne(p.url)}
									>
										<td>
											<input
												type="checkbox"
												checked={isSelected}
												onChange={() => toggleOne(p.url)}
												onClick={(e) => e.stopPropagation()}
											/>
										</td>
										<td>
											<div className="pu-wc-category__product-cell">
												{p.image ? (
													<div className="pu-wc-category__product-thumb">
														<img
															src={p.image}
															alt=""
															loading="lazy"
															onError={(e) => {
																e.currentTarget.style.visibility =
																	'hidden';
															}}
														/>
													</div>
												) : (
													<div className="pu-wc-category__product-thumb pu-wc-category__product-thumb--empty" />
												)}
												<div className="pu-wc-category__product-info">
													<div
														className="pu-wc-category__product-name"
														title={p.name}
													>
														{p.name || p.url}
													</div>
													<div
														className="pu-wc-category__product-url"
														title={p.url}
													>
														{p.url}
													</div>
												</div>
											</div>
										</td>
										<td>
											<a
												href={p.url}
												target="_blank"
												rel="noopener noreferrer"
												onClick={(e) => e.stopPropagation()}
											>
												{__('View', 'product-upload')}
											</a>
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				)}

				{/* Footer pinned via sticky inside __body — the only reliable
					 scroll container — so the Import button stays visible
					 regardless of how many products the page returned. */}
				<div className="pu-wc-category__footer">
					<Button variant="tertiary" onClick={onClose}>
						{__('Cancel', 'product-upload')}
					</Button>
					{overQuota && typeof onUpgrade === 'function' ? (
						// Selection exceeds available credits — surface an
						// Upgrade path instead of a dead-disabled button.
						<Button
							variant="primary"
							onClick={onUpgrade}
							isBusy={!!upgrading}
							disabled={!!upgrading}
						>
							{upgrading
								? __('Opening upgrade…', 'product-upload')
								: __('Upgrade plan', 'product-upload')}
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={handleConfirm}
							disabled={noneSelected}
						>
							{primaryLabel}
						</Button>
					)}
				</div>
			</div>
		</Modal>
	);
};

export default CategoryTriageModal;
