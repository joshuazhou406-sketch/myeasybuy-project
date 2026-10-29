/**
 * Detail view for a completed import: opened from a CompletedJobRow, fetches
 * the job once and lists every item in the batch. Mirrors the Shopify
 * JobProductsModal.
 *
 * @package ProductUploadForWoocommerce
 */
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import JobItemRow from './JobItemRow';
import { ItemRowsSkeleton } from './Skeletons';
import { jobTitle, hostOf } from './jobUtils';
import DoneForYouLink, { isHardSiteFailure } from './DoneForYouLink';

const JobDetailModal = ({ jobId, summary, onClose, onCategory, onRetry }) => {
	const [job, setJob] = useState(null);
	const [items, setItems] = useState(null);
	const [error, setError] = useState(false);

	useEffect(() => {
		let alive = true;
		apiFetch({ path: `/product-upload/v1/import-jobs/${encodeURIComponent(jobId)}` })
			.then((d) => {
				if (!alive) {
					return;
				}
				setJob((d && d.job) || null);
				setItems(Array.isArray(d && d.items) ? d.items : []);
			})
			.catch(() => {
				if (alive) {
					setError(true);
					setItems([]);
				}
			});
		return () => {
			alive = false;
		};
	}, [jobId]);

	const sCount = summary ? summary.count || summary.total || 0 : 0;
	const total = job ? job.total || (items ? items.length : 0) : sCount;
	const host =
		items && items.length ? hostOf(items[0].url) : (summary && summary.host) || '';
	const title = total ? jobTitle(total, host) : __('Import', 'product-upload');

	return (
		<Modal title={title} onRequestClose={onClose} className="pu-wc-job-modal">
			{items === null ? (
				<ItemRowsSkeleton rows={4} />
			) : error ? (
				<p className="pu-wc-job-modal__empty">
					{__('Could not load this import. Please try again.', 'product-upload')}
				</p>
			) : items.length === 0 ? (
				<p className="pu-wc-job-modal__empty">
					{__('No products in this import.', 'product-upload')}
				</p>
			) : (
				<div className="pu-wc-job-card__items">
					{!items.some((i) => i.status === 'succeeded') && items.some(isHardSiteFailure) && (
						<DoneForYouLink copy="hardSite" moment="import-failed" />
					)}
					{items.map((item) => (
						<JobItemRow
							key={item.id}
							item={item}
							jobId={jobId}
							onCategory={onCategory}
							onRetry={onRetry ? (url) => { onRetry(url); onClose(); } : undefined}
						/>
					))}
				</div>
			)}
		</Modal>
	);
};

export default JobDetailModal;
