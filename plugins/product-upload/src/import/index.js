/**
 * Product Upload for WooCommerce – Import page entry point.
 *
 * Registers the Import page with the WooCommerce Admin page controller.
 */
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import App from './App';
import './index.scss';

addFilter(
	'woocommerce_admin_pages_list',
	'product-upload/import',
	( pages ) => {
		pages.push( {
			container: App,
			path: '/product-upload',
			breadcrumbs: [
				__( 'Product Upload', 'product-upload' ),
			],
			navArgs: {
				id: 'product_upload_for_woocommerce',
			},
		} );
		return pages;
	}
);
