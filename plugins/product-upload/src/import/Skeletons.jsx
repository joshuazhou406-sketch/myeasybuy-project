/**
 * Shimmer skeleton placeholders for the import-jobs UI loading states
 * (mirrors the Shopify app's CompletedImportsSkeleton). Pure presentational.
 *
 * @package ProductUploadForWoocommerce
 */

export const Skel = ({ w, h = 12, r = 5, style }) => (
	<span
		className="pu-wc-skel"
		style={{ width: w, height: h, borderRadius: r, ...(style || {}) }}
	/>
);

export const JobCardSkeleton = () => (
	<div className="pu-wc-job-card" role="status" aria-busy="true" aria-label="Loading import">
		<div className="pu-wc-job-card__head">
			<Skel w="42%" h={14} />
			<Skel w={64} h={18} r={10} />
		</div>
		<div style={{ marginTop: 10 }}>
			<Skel w="30%" h={11} />
		</div>
		<div style={{ marginTop: 14 }}>
			<div className="pu-wc-skel-row">
				<Skel w={36} h={36} r={4} />
				<Skel w="52%" h={12} />
			</div>
			<div className="pu-wc-skel-row">
				<Skel w={36} h={36} r={4} />
				<Skel w="44%" h={12} />
			</div>
		</div>
	</div>
);

export const DoneRowSkeleton = () => (
	<div
		className="pu-wc-done-row"
		role="status"
		aria-busy="true"
		style={{ cursor: 'default' }}
	>
		<Skel w={36} h={36} r={6} />
		<span style={{ flex: 1, minWidth: 0 }}>
			<Skel w="52%" h={13} style={{ display: 'block' }} />
			<Skel w="30%" h={11} style={{ display: 'block', marginTop: 7 }} />
		</span>
		<Skel w={16} h={16} r={4} />
	</div>
);

export const SupplierRowsSkeleton = ({ rows = 5 }) => (
	<ul className="pu-wc-suppliers__list" role="status" aria-busy="true" aria-label="Loading suppliers">
		{Array.from({ length: rows }).map((_, i) => (
			<li className="pu-wc-suppliers__item pu-wc-suppliers__item--skel" key={i}>
				<Skel w={28} h={28} r={6} />
				<span style={{ flex: 1, minWidth: 0 }}>
					<Skel w={`${52 + (i % 3) * 9}%`} h={12} style={{ display: 'block', marginBottom: 6 }} />
					<Skel w={`${30 + (i % 2) * 8}%`} h={10} style={{ display: 'block' }} />
				</span>
			</li>
		))}
	</ul>
);

export const ItemRowsSkeleton = ({ rows = 4 }) => (
	<div className="pu-wc-job-card__items" role="status" aria-busy="true" aria-label="Loading products">
		{Array.from({ length: rows }).map((_, i) => (
			<div className="pu-wc-job-item" key={i}>
				<Skel w={36} h={36} r={4} />
				<div className="pu-wc-job-item__info">
					<Skel w={`${58 + (i % 3) * 10}%`} h={12} />
				</div>
				<div className="pu-wc-job-item__right">
					<Skel w={42} h={12} />
				</div>
			</div>
		))}
	</div>
);
