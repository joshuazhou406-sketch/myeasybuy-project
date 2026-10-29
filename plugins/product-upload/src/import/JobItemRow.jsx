/**
 * One row in a job's item list. Shared by the active ImportJobCard and the
 * completed-job detail modal so the per-item rendering stays consistent.
 *
 * @package ProductUploadForWoocommerce
 */
import { __ } from '@wordpress/i18n';
import { Spinner, Button } from '@wordpress/components';
import { adminPostUrl, urlTail, QUEUED_STORE } from './jobUtils';

// queued / waiting states
const PENDING = ['queued'];

const IconExternal = (
	<svg className="pu-wc-job-item__ext-ico" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
		<polyline points="15 3 21 3 21 9" />
		<line x1="10" y1="14" x2="21" y2="3" />
	</svg>
);

const IconClock = (
	<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<circle cx="12" cy="12" r="9" />
		<polyline points="12 7 12 12 15 14" />
	</svg>
);

const IconGlobe = (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<circle cx="12" cy="12" r="10" />
		<line x1="2" y1="12" x2="22" y2="12" />
		<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
	</svg>
);

const IconSparkles = (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0l1.58 6.13a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z" />
	</svg>
);

const IconStore = (
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
		<line x1="3" y1="6" x2="21" y2="6" />
		<path d="M16 10a4 4 0 0 1-8 0" />
	</svg>
);

// at render: __() needs loaded locale data
const stageSteps = () => [
	['reading', __('Reading page', 'product-upload'), IconGlobe],
	['writing', __('Writing listing', 'product-upload'), IconSparkles],
	['publishing', __('Adding to store', 'product-upload'), IconStore],
];

// the in-WP create is the last step
const stageOf = (item) =>
	QUEUED_STORE.includes(item.status)
		? 'publishing'
		: item.status === 'processing'
			? item.stage
			: null;

// step icon in a ring that fills per step
const StageRing = ({ icon, progress }) => {
	const r = 13;
	const c = 2 * Math.PI * r;
	return (
		<span className="pu-wc-stage-ring" aria-hidden="true">
			<span className="pu-wc-stage-ring__ping" />
			<svg width="30" height="30" viewBox="0 0 30 30">
				<circle className="pu-wc-stage-ring__track" cx="15" cy="15" r={r} />
				<circle
					className="pu-wc-stage-ring__arc"
					cx="15"
					cy="15"
					r={r}
					strokeDasharray={c}
					strokeDashoffset={c * (1 - progress)}
					transform="rotate(-90 15 15)"
				/>
			</svg>
			<span className="pu-wc-stage-ring__icon">{icon}</span>
		</span>
	);
};

const displayUrl = (u) =>
	String(u || '').replace(/^https?:\/\/(www\.)?/, '').replace(/\/+$/, '');

const JobItemRow = ({ item, jobId, onCategory, onDismiss, onRetry }) => {
	const media = Array.isArray(item.product_media) ? item.product_media : [];
	const src = media[0] && (media[0].src_image_url || media[0].src);
	const isFailed = item.status === 'failed' || item.status === 'needs_browser';

	let right;
	if (item.status === 'category') {
		const products = Array.isArray(item.category_products) ? item.category_products : [];
		right = (
			<div className="pu-wc-job-item__actions">
				<Button
					variant="primary"
					size="small"
					onClick={() => onCategory && onCategory({ sourceUrl: item.url, products, jobId })}
				>
					{__('Choose products', 'product-upload')}
				</Button>
				{onDismiss && (
					<Button variant="tertiary" size="small" onClick={() => onDismiss(jobId)}>
						{__('Dismiss', 'product-upload')}
					</Button>
				)}
			</div>
		);
	} else if (item.status === 'succeeded') {
		const editUrl =
			adminPostUrl && item.external_product_id
				? `${adminPostUrl}?post=${encodeURIComponent(item.external_product_id)}&action=edit`
				: null;
		right = editUrl ? (
			<a className="pu-wc-job-item__view" href={editUrl} target="_blank" rel="noreferrer">
				{__('View', 'product-upload')}
			</a>
		) : (
			<span className="pu-wc-pill pu-wc-pill--ok">{__('Imported', 'product-upload')}</span>
		);
	} else if (isFailed) {
		right = (
			<div className="pu-wc-job-item__actions">
				<span className="pu-wc-pill pu-wc-pill--fail">{__('Failed', 'product-upload')}</span>
				{onRetry && item.url && (
					<Button variant="secondary" size="small" onClick={() => onRetry(item.url)}>
						{__('Retry', 'product-upload')}
					</Button>
				)}
			</div>
		);
	} else if (item.status === 'cancelled') {
		right = <span className="pu-wc-pill pu-wc-pill--muted">{__('Cancelled', 'product-upload')}</span>;
	} else if (PENDING.includes(item.status)) {
		right = (
			<span className="pu-wc-job-item__pending">
				{IconClock}
				{__('Pending', 'product-upload')}
			</span>
		);
	} else {
		const steps = stageSteps();
		const step = steps.findIndex(([stage]) => stage === stageOf(item));
		// older servers send no stage
		right =
			step < 0 ? (
				<span className="pu-wc-job-item__importing">
					<Spinner />
					{__('Importing', 'product-upload')}
				</span>
			) : (
				// ring last so rings line up on the right
				<span className="pu-wc-job-item__importing">
					{steps[step][1]}
					<StageRing icon={steps[step][2]} progress={(step + 0.5) / steps.length} />
				</span>
			);
	}

	return (
		<div className="pu-wc-job-item">
			{src ? (
				<img className="pu-wc-job-item__thumb" src={src} alt="" loading="lazy" />
			) : (
				<span className="pu-wc-job-item__thumb pu-wc-job-item__thumb--empty" />
			)}
			<div className="pu-wc-job-item__info">
				<span className="pu-wc-job-item__name">
					{item.product_name || urlTail(item.url)}
				</span>
				{item.url && (
					<a
						className="pu-wc-job-item__src"
						href={item.url}
						target="_blank"
						rel="noreferrer"
						title={item.url}
					>
						<span className="pu-wc-job-item__src-text">{displayUrl(item.url)}</span>
						{IconExternal}
					</a>
				)}
				{isFailed && item.error_message && (
					<span className="pu-wc-job-item__err">{item.error_message}</span>
				)}
				{item.no_price && item.status === 'succeeded' && (
					<span className="pu-wc-job-item__warn">
						{__('No price found, add one in WooCommerce', 'product-upload')}
					</span>
				)}
			</div>
			<div className="pu-wc-job-item__right">{right}</div>
		</div>
	);
};

export default JobItemRow;
