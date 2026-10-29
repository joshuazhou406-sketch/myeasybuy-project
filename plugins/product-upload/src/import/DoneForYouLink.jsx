import { __ } from '@wordpress/i18n';

// one import this size is a setup job
export const BIG_JOB_PRODUCTS = 1000;

// Franchise and up
export const isTopPlan = (plan) =>
	['franchise', 'plaza', 'citadel', 'precinct'].includes(String(plan || '').toLowerCase());

// a supplier page we couldn't read
export const isHardSiteFailure = (item) =>
	item.status === 'needs_browser' ||
	(item.status === 'failed' &&
		['NO_PRODUCT_DATA', 'BLOCKED', 'MAX_ATTEMPTS'].includes(item.error_code));

// utm_content says which moment sent them
export const doneForYouUrl = (moment) =>
	`https://productupload.co/done-for-you?utm_source=woocommerce-app&utm_medium=in-app&utm_content=${moment}`;

// done-for-you link, high-intent moments only
const DoneForYouLink = ({ copy, moment }) => (
	<p className="pu-wc-dfy">
		{copy === 'hardSite'
			? __('Tricky supplier site?', 'product-upload')
			: __('Big catalogue?', 'product-upload')}{' '}
		<a href={doneForYouUrl(moment)} target="_blank" rel="noopener noreferrer">
			{copy === 'hardSite'
				? __('Our team can import it for you.', 'product-upload')
				: __('We can set it up for you.', 'product-upload')}
		</a>
	</p>
);

export default DoneForYouLink;
