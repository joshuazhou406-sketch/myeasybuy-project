/**
 * An active import job rendered as an expanded card (mirrors the Shopify
 * app's ImportJobCard): header + progress summary + per-item rows. Active
 * jobs self-poll their detail; the shop-wide drain is kicked centrally by
 * ImportsSection, so this component only reads + displays.
 *
 * @package ProductUploadForWoocommerce
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Spinner, Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import JobItemRow from './JobItemRow';
import Favicon from './Favicon';
import { JobCardSkeleton } from './Skeletons';
import { TERMINAL, QUEUED_STORE, hostOf, jobTitle, STATUS_LABEL } from './jobUtils';
import DoneForYouLink, { BIG_JOB_PRODUCTS, isHardSiteFailure } from './DoneForYouLink';

const POLL_MS = 3000;

const ImportJobCard = ({ jobId, active, onCategory, onBalance, onUpgrade, onDismissed, onRetry }) => {
	const [job, setJob] = useState(null);
	const [items, setItems] = useState([]);
	const timerRef = useRef(null);
	const aliveRef = useRef(true);

	useEffect(() => {
		aliveRef.current = true;
		const tick = async () => {
			try {
				const data = await apiFetch({
					path: `/product-upload/v1/import-jobs/${encodeURIComponent(jobId)}`,
				});
				if (!aliveRef.current) {
					return;
				}
				if (data && data.job) {
					setJob(data.job);
					setItems(Array.isArray(data.items) ? data.items : []);
					if (typeof data.balance === 'number' && onBalance) {
						onBalance(data.balance);
					}
					if (TERMINAL.includes(data.job.status) || !active) {
						return; // stop polling
					}
				}
			} catch {
				// transient; keep polling
			}
			if (aliveRef.current) {
				timerRef.current = setTimeout(tick, POLL_MS);
			}
		};
		tick();
		return () => {
			aliveRef.current = false;
			if (timerRef.current) {
				clearTimeout(timerRef.current);
			}
		};
	}, [jobId, active]); // eslint-disable-line

	const handleDismiss = async () => {
		aliveRef.current = false;
		if (timerRef.current) {
			clearTimeout(timerRef.current);
		}
		try {
			await apiFetch({
				path: `/product-upload/v1/import-jobs/${encodeURIComponent(jobId)}/cancel`,
				method: 'POST',
			});
		} catch {
			// best-effort
		}
		if (onDismissed) {
			onDismissed(jobId);
		}
	};

	if (!job) {
		return <JobCardSkeleton />;
	}

	const isRunning = job.status === 'running';
	const total = job.total || items.length;
	const succeeded = items.filter((i) => i.status === 'succeeded').length;
	const failed = items.filter((i) => i.status === 'failed' || i.status === 'needs_browser').length;
	const queuedStore = items.filter((i) => QUEUED_STORE.includes(i.status)).length;
	const noPrice = items.filter((i) => i.no_price).length;
	const done = succeeded + failed;
	const host = items.length ? hostOf(items[0].url) : '';
	const isTerminal = TERMINAL.includes(job.status);
	// whole job dead-ended on hard sites
	const hardSiteDeadEnd = isTerminal && succeeded === 0 && items.some(isHardSiteFailure);

	const summaryParts = [];
	if (isRunning && total > 1) {
		summaryParts.push(`${done} ${__('of', 'product-upload')} ${total}`);
	}
	if (succeeded) {
		summaryParts.push(`${succeeded} ${__('imported', 'product-upload')}`);
	}
	if (queuedStore) {
		summaryParts.push(`${queuedStore} ${__('queued for your store', 'product-upload')}`);
	}
	if (failed) {
		summaryParts.push(`${failed} ${__('failed', 'product-upload')}`);
	}
	if (noPrice) {
		summaryParts.push(`${noPrice} ${__('without a price', 'product-upload')}`);
	}

	return (
		<div className={`pu-wc-job-card pu-wc-job-card--${job.status}`}>
			<div className="pu-wc-job-card__head">
				<span className="pu-wc-job-card__title">
					<Favicon host={host} />
					<span className="pu-wc-job-card__title-text">{jobTitle(total, host)}</span>
				</span>
				<span className="pu-wc-job-card__status">
					{isRunning && <Spinner />}
					<span className={`pu-wc-pill pu-wc-pill--${isRunning ? 'wait' : failed ? 'fail' : 'ok'}`}>
						{STATUS_LABEL[job.status] || job.status}
					</span>
				</span>
			</div>

			{summaryParts.length > 0 && (
				<div className="pu-wc-job-card__summary">{summaryParts.join(' · ')}</div>
			)}

			{job.status === 'paused' && (
				<div className="pu-wc-job-card__notice">
					<span>
						{job.pause_reason === 'out_of_credits'
							? __('Out of credits. Add more to finish this import.', 'product-upload')
							: __('Import paused.', 'product-upload')}
					</span>
					{job.pause_reason === 'out_of_credits' && onUpgrade && (
						<Button variant="primary" size="small" onClick={onUpgrade}>
							{__('Add credits', 'product-upload')}
						</Button>
					)}
				</div>
			)}

			{!isTerminal && total >= BIG_JOB_PRODUCTS && (
				<DoneForYouLink
					copy="bigJob"
					moment={job.pause_reason === 'out_of_credits' ? 'job-out-of-credits' : 'job-queue'}
				/>
			)}
			{hardSiteDeadEnd && <DoneForYouLink copy="hardSite" moment="import-failed" />}

			<div className="pu-wc-job-card__items">
				{items.map((item) => (
					<JobItemRow key={item.id} item={item} jobId={jobId} onCategory={onCategory} onDismiss={handleDismiss} onRetry={onRetry} />
				))}
			</div>
		</div>
	);
};

export default ImportJobCard;
