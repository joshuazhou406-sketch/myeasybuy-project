/**
 * The persistent Imports view (mirrors the Shopify app): an Active tab with a
 * card per running/paused job and a Completed tab with terminal jobs. While
 * any job is active it polls the job lists and kicks the shop-wide drain so
 * the plugin creates parked products promptly; it stops when nothing is
 * active and resumes when a new job is created (refreshKey bump).
 *
 * @package ProductUploadForWoocommerce
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import ImportJobCard from './ImportJobCard';
import CompletedJobRow from './CompletedJobRow';
import JobDetailModal from './JobDetailModal';
import EmptyState from './EmptyState';
import { JobCardSkeleton, DoneRowSkeleton } from './Skeletons';

const POLL_MS = 4000;

const ImportsSection = ({
	refreshKey,
	view,
	setView,
	starting,
	newJobId,
	onCategory,
	onBalance,
	onUpgrade,
	onCustomize,
	onRetry,
}) => {
	const [active, setActive] = useState([]);
	const [done, setDone] = useState([]);
	const [loaded, setLoaded] = useState(false);
	const [openJob, setOpenJob] = useState(null);
	const aliveRef = useRef(true);
	const timerRef = useRef(null);
	// created ids, until listed or dismissed
	const [pending, setPending] = useState([]);
	const settledRef = useRef(new Set());
	const pendingIds = [...new Set([newJobId, ...pending])].filter((id) => id && !settledRef.current.has(id));
	const pendingRef = useRef(pendingIds);
	pendingRef.current = pendingIds;
	// key whose fetch has landed
	const [fetchedKey, setFetchedKey] = useState(refreshKey);
	const refreshing = loaded && fetchedKey !== refreshKey;

	useEffect(() => {
		if (newJobId) {
			setPending((p) => (p.includes(newJobId) ? p : [newJobId, ...p]));
		}
	}, [newJobId]);

	useEffect(() => {
		aliveRef.current = true;
		const key = refreshKey;

		const fetchLists = async () => {
			const [a, d] = await Promise.all([
				apiFetch({ path: '/product-upload/v1/import-jobs?state=active&limit=20' }).catch(() => null),
				apiFetch({ path: '/product-upload/v1/import-jobs?state=done&limit=10' }).catch(() => null),
			]);
			if (!aliveRef.current) {
				return [];
			}
			const acts = a && Array.isArray(a.jobs) ? a.jobs : [];
			if (a && Array.isArray(a.jobs)) {
				setActive(acts);
			}
			if (d && Array.isArray(d.jobs)) {
				setDone(d.jobs);
			}
			// listed now: drop the placeholder
			for (const j of [...acts, ...(d && Array.isArray(d.jobs) ? d.jobs : [])]) {
				if (pendingRef.current.includes(j.id)) {
					settledRef.current.add(j.id);
				}
			}
			setPending((p) => p.filter((id) => !settledRef.current.has(id)));
			setLoaded(true);
			setFetchedKey(key);
			return acts;
		};

		const loop = async () => {
			const acts = await fetchLists();
			if (!aliveRef.current) {
				return;
			}
			if (acts.length > 0 || pendingRef.current.length > 0) {
				// create parked items
				apiFetch({ path: '/product-upload/v1/drain', method: 'POST' }).catch(() => { });
				timerRef.current = setTimeout(loop, POLL_MS);
			}
		};

		loop();
		return () => {
			aliveRef.current = false;
			if (timerRef.current) {
				clearTimeout(timerRef.current);
			}
		};
	}, [refreshKey]); // eslint-disable-line

	const shown = [...pendingIds.filter((id) => !active.some((j) => j.id === id)).map((id) => ({ id })), ...active];
	// starting keeps active view
	const hasActive = shown.length > 0 || starting || refreshing;
	const showActive = hasActive && view !== 'completed';

	// initial load: skeleton
	if (!loaded) {
		return (
			<div className="pu-wc-imports">
				<div className="pu-wc-imports__list">
					<JobCardSkeleton />
				</div>
			</div>
		);
	}

	if (!hasActive && done.length === 0) {
		return <EmptyState onCustomize={onCustomize} />;
	}

	return (
		<div className="pu-wc-imports">
			{hasActive && (
				<div className="pu-wc-imports__tabs">
					<Button
						variant={showActive ? 'primary' : 'tertiary'}
						size="small"
						onClick={() => setView('active')}
					>
						{__('Active', 'product-upload')} ({shown.length})
					</Button>
					<Button
						variant={!showActive ? 'primary' : 'tertiary'}
						size="small"
						onClick={() => setView('completed')}
					>
						{__('Completed', 'product-upload')}
					</Button>
				</div>
			)}

			<div className="pu-wc-imports__list">
				{showActive ? (
					<>
						{(starting || (refreshing && shown.length === 0)) && <JobCardSkeleton />}
						{shown.length === 0 && !starting && !refreshing && (
							<div className="pu-wc-imports__hint">
								{__('No imports in progress.', 'product-upload')}
							</div>
						)}
						{shown.map((j) => (
							<ImportJobCard
								key={j.id}
								jobId={j.id}
								active
								onCategory={onCategory}
								onBalance={onBalance}
								onUpgrade={onUpgrade}
								onDismissed={(id) => {
									setActive((a) => a.filter((x) => x.id !== id));
									settledRef.current.add(id);
									setPending((p) => p.filter((x) => x !== id));
								}}
								onRetry={onRetry}
							/>
						))}
					</>
				) : done.length === 0 ? (
					<EmptyState onCustomize={onCustomize} />
				) : (
					done.map((j) => (
						<CompletedJobRow key={j.id} job={j} onOpen={() => setOpenJob(j)} />
					))
				)}
			</div>

			{openJob && (
				<JobDetailModal
					jobId={openJob.id}
					summary={openJob}
					onClose={() => setOpenJob(null)}
					onCategory={onCategory}
					onRetry={onRetry}
				/>
			)}
		</div>
	);
};

export default ImportsSection;
