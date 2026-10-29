/**
 * The "no imports yet" empty state: an animated illustration, a clear prompt,
 * and a rotating tip chip. Mirrors the Shopify app's import empty state.
 *
 * @package ProductUploadForWoocommerce
 */
import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const Illustration = () => (
	<div aria-hidden="true">
		<svg width="96" height="96" viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg">
			<defs>
				<linearGradient id="pu-wc-empty-bg" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0" stopColor="#eef0ff" />
					<stop offset="1" stopColor="#e6eaff" />
				</linearGradient>
			</defs>
			<circle cx="48" cy="48" r="40" fill="url(#pu-wc-empty-bg)" />
			<g className="pu-wc-float">
				<g transform="rotate(-6 48 48)">
					<rect x="32" y="27" width="34" height="42" rx="5" fill="#fff" stroke="#d4d9ff" strokeWidth="1.5" />
					<rect x="36" y="31" width="26" height="19" rx="3" fill="#eef0ff" />
					<circle cx="43" cy="38" r="2.4" fill="#ffb020" />
					<path d="M37 49 l6 -7 l4 4 l5 -5 l10 8 z" fill="#7480ff" />
					<rect x="36" y="54" width="26" height="3.4" rx="1.7" fill="#e4e6f2" />
					<rect x="36" y="60.5" width="16" height="3.4" rx="1.7" fill="#e9ebf4" />
				</g>
			</g>
			<path className="pu-wc-spark" d="M68 25 l1.7 4.4 l4.4 1.7 l-4.4 1.7 l-1.7 4.4 l-1.7 -4.4 l-4.4 -1.7 l4.4 -1.7 z" fill="#7480ff" />
			<path className="pu-wc-spark pu-wc-spark--b" d="M22 30 l1.2 3.1 l3.1 1.2 l-3.1 1.2 l-1.2 3.1 l-1.2 -3.1 l-3.1 -1.2 l3.1 -1.2 z" fill="#ffb020" />
			<circle className="pu-wc-spark pu-wc-spark--c" cx="26" cy="64" r="2.2" fill="#ffb020" />
		</svg>
	</div>
);

const Lightbulb = () => (
	<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b4eff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
		<path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z" />
	</svg>
);

const EmptyState = ({ onCustomize }) => {
	const tips = [
		__('Paste a category page to import all of its products at once.', 'product-upload'),
		onCustomize ? (
			<>
				{__('Adjust title, pricing and more in', 'product-upload')}{' '}
				<a onClick={onCustomize}>{__('Customize', 'product-upload')}</a>{' '}
				{__('before importing.', 'product-upload')}
			</>
		) : (
			__('Adjust your import settings in Customize before importing.', 'product-upload')
		),
		__('Bulk-import from Amazon, AliExpress, eBay, Alibaba and more.', 'product-upload'),
	];
	const [index, setIndex] = useState(0);
	const paused = useRef(false);

	useEffect(() => {
		const id = setInterval(() => {
			if (!paused.current) {
				setIndex((n) => (n + 1) % tips.length);
			}
		}, 6000);
		return () => clearInterval(id);
	}, [tips.length]);

	return (
		<div className="pu-wc-empty">
			<Illustration />
			<div className="pu-wc-empty__title">
				{__('Import your first product', 'product-upload')}
			</div>
			<div className="pu-wc-empty__sub">
				{__(
					'Paste a product or category URL above and AI pulls in the title, images, variants, and price for you.',
					'product-upload'
				)}
			</div>
			<div
				className="pu-wc-tip"
				onMouseEnter={() => {
					paused.current = true;
				}}
				onMouseLeave={() => {
					paused.current = false;
				}}
			>
				<Lightbulb />
				<span key={index} className="pu-wc-tip__text">
					{tips[index]}
				</span>
			</div>
		</div>
	);
};

export default EmptyState;
