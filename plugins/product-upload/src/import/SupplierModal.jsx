/**
 * Supplier modal: the per-supplier home, opened from a sidebar row. Mirrors
 * the Shopify app's Supplier modal with an Overview tab (AI scorecard +
 * locale pin) and an Import history tab. The "Bulk import" affordance hands
 * back to the parent, which closes this and opens the proven scan modal
 * (avoids nesting modals / a risky browse refactor).
 *
 * @package ProductUploadForWoocommerce
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';
import SupplierOverview from './SupplierOverview';
import SupplierHistory from './SupplierHistory';

function capitalize(s) {
	return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
}

const SupplierModal = ({ supplier, initialTab = 'overview', onClose, onBulkImport, onConnectExtension }) => {
	const [tab, setTab] = useState(initialTab === 'history' ? 'history' : 'overview');

	const name = capitalize(supplier?.name || supplier?.url || '');
	const count = supplier?.product_count;
	const historyLabel = count ? `${__('Import history', 'product-upload')} (${count})` : __('Import history', 'product-upload');

	return (
		<Modal
			title={`${__('Import products from', 'product-upload')} ${name}`}
			onRequestClose={onClose}
			className="pu-wc-supplier-modal"
			shouldCloseOnClickOutside
		>
			<div className="pu-wc-supplier-modal__tabs" role="tablist">
				<button
					type="button"
					role="tab"
					aria-selected={tab === 'overview'}
					className={`pu-wc-supplier-modal__tab${tab === 'overview' ? ' is-active' : ''}`}
					onClick={() => setTab('overview')}
				>
					{__('Overview', 'product-upload')}
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={tab === 'history'}
					className={`pu-wc-supplier-modal__tab${tab === 'history' ? ' is-active' : ''}`}
					onClick={() => setTab('history')}
				>
					{historyLabel}
				</button>
			</div>

			<div className="pu-wc-supplier-modal__body">
				{ /* keep both mounted */}
				<div style={{ display: tab === 'overview' ? '' : 'none' }}>
					<SupplierOverview
						supplier={supplier}
						onBulkImport={onBulkImport}
						onConnectExtension={onConnectExtension}
					/>
				</div>
				<div style={{ display: tab === 'history' ? '' : 'none' }}>
					<SupplierHistory supplier={supplier} onBulkImport={onBulkImport} />
				</div>
			</div>
		</Modal>
	);
};

export default SupplierModal;
