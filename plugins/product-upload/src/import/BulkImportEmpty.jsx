/**
 * Suppliers empty state: a dashed drop-zone that invites bulk-importing from a
 * site. Click (or drop a site URL onto it) to start. Mirrors the Shopify app's
 * bulk-import empty state, including the animated grid + badge + spark graphic.
 *
 * @package ProductUploadForWoocommerce
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const Illustration = () => (
	<div aria-hidden="true">
		<svg width="96" height="96" viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg">
			<defs>
				<linearGradient id="pu-wc-bulk-bg" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0" stopColor="#eef0ff" />
					<stop offset="1" stopColor="#e6eaff" />
				</linearGradient>
			</defs>
			<circle cx="48" cy="48" r="40" fill="url(#pu-wc-bulk-bg)" />
			<g className="pu-wc-bulk-float">
				<g fill="#ffffff" stroke="#7480ff" strokeWidth="2">
					<rect x="28" y="28" width="15" height="15" rx="3.5" />
					<rect x="47" y="28" width="15" height="15" rx="3.5" />
					<rect x="28" y="47" width="15" height="15" rx="3.5" />
					<rect x="47" y="47" width="15" height="15" rx="3.5" />
				</g>
				<g className="pu-wc-bulk-badge">
					<circle cx="63" cy="63" r="10" fill="#7480ff" stroke="#ffffff" strokeWidth="2.5" />
					<path d="M63 58.5 v9 M58.5 63 h9" fill="none" stroke="#ffffff" strokeWidth="2.4" strokeLinecap="round" />
				</g>
			</g>
			<path className="pu-wc-spark" d="M20 24 l1.3 3.4 l3.4 1.3 l-3.4 1.3 l-1.3 3.4 l-1.3 -3.4 l-3.4 -1.3 l3.4 -1.3 z" fill="#ffb020" />
		</svg>
	</div>
);

const BulkImportEmpty = ({ onAdd, onScan }) => {
	const [dragover, setDragover] = useState(false);

	const handleDrop = (e) => {
		e.preventDefault();
		setDragover(false);
		const text = (
			e.dataTransfer.getData('text/uri-list') ||
			e.dataTransfer.getData('text/plain') ||
			''
		).trim();
		try {
			new URL(text);
			if (onScan) {
				onScan(text);
				return;
			}
		} catch {
			/* not a URL */
		}
		onAdd();
	};

	return (
		<button
			type="button"
			className={`pu-wc-bulk-dropzone${dragover ? ' is-dragover' : ''}`}
			onClick={onAdd}
			onDragOver={(e) => {
				e.preventDefault();
				setDragover(true);
			}}
			onDragLeave={() => setDragover(false)}
			onDrop={handleDrop}
			aria-label={__('Add your first website to bulk import', 'product-upload')}
		>
			<Illustration />
			<div className="pu-wc-bulk-dropzone__title">
				{__('Bulk-import from any website', 'product-upload')}
			</div>
			<div className="pu-wc-bulk-dropzone__sub">
				{__(
					"Add a supplier site and we'll pull its products in one go, no pasting URLs one at a time.",
					'product-upload'
				)}
			</div>
			<span className="pu-wc-bulk-dropzone__cta">
				{__('Add your first website', 'product-upload')}
			</span>
		</button>
	);
};

export default BulkImportEmpty;
