/**
 * A completed import as a single compact row (thumbnail deck + title + status
 * + time). Built from the list-endpoint job data; clicking opens the detail
 * modal with the whole batch. Mirrors the Shopify CompletedImportsList row.
 *
 * @package ProductUploadForWoocommerce
 */
import { __ } from '@wordpress/i18n';
import Favicon from './Favicon';
import { jobTitle, hostOf, timeAgo, STATUS_LABEL, STATUS_TONE } from './jobUtils';

const CompletedJobRow = ({ job, onOpen }) => {
	const previews = Array.isArray(job.previews) ? job.previews : [];
	const total = job.count || job.total || previews.length || 0;
	const host = job.host || (previews[0] && hostOf(previews[0].url)) || '';
	const title =
		total <= 1 && previews[0] && previews[0].name ? previews[0].name : jobTitle(total, host);

	const deck = previews.slice(0, 3);
	const metaBits = [timeAgo(job.finished_at || job.created_at)];
	if (job.succeeded) {
		metaBits.push(`${job.succeeded} ${__('imported', 'product-upload')}`);
	}
	const meta = metaBits.filter(Boolean).join(' · ');
	const tone = STATUS_TONE[job.status] || 'muted';
	const label = STATUS_LABEL[job.status] || job.status;

	return (
		<button type="button" className="pu-wc-done-row" onClick={onOpen}>
			<span className="pu-wc-done-row__thumbs">
				{deck.length > 0 ? (
					deck.map((p, i) => (
						<span
							key={p.url || i}
							className="pu-wc-done-row__thumb"
							style={{
								backgroundImage: p.image ? `url(${p.image})` : undefined,
								zIndex: deck.length - i,
							}}
						/>
					))
				) : (
					<span className="pu-wc-done-row__thumb pu-wc-done-row__thumb--empty" />
				)}
			</span>
			<span className="pu-wc-done-row__info">
				<span className="pu-wc-done-row__title">
					<Favicon host={host} size={15} />
					<span className="pu-wc-done-row__title-text">{title}</span>
				</span>
				<span className="pu-wc-done-row__meta">
					<span className={`pu-wc-pill pu-wc-pill--${tone}`}>{label}</span>
					{meta && <span className="pu-wc-done-row__time">{meta}</span>}
				</span>
			</span>
			<span className="pu-wc-done-row__chev" aria-hidden="true">
				›
			</span>
		</button>
	);
};

export default CompletedJobRow;
