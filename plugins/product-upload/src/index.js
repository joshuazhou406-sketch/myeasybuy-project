/**
 * External dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { Dropdown } from '@wordpress/components';
import * as Woo from '@woocommerce/components';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './index.scss';

const MyExamplePage = () => (
	<Fragment>
		<Woo.Section component="article">
			<Woo.SectionHeader
				title={__('Search', 'product-upload')}
			/>
			<Woo.Search
				type="products"
				placeholder="Search for something"
				selected={[]}
				onChange={(items) => setInlineSelect(items)}
				inlineTags
			/>
		</Woo.Section>

		<Woo.Section component="article">
			<Woo.SectionHeader
				title={__('Dropdown', 'product-upload')}
			/>
			<Dropdown
				renderToggle={({ isOpen, onToggle }) => (
					<Woo.DropdownButton
						onClick={onToggle}
						isOpen={isOpen}
						labels={['Dropdown']}
					/>
				)}
				renderContent={() => <p>Dropdown content here</p>}
			/>
		</Woo.Section>

		<Woo.Section component="article">
			<Woo.SectionHeader
				title={__(
					'Pill shaped container',
					'product-upload'
				)}
			/>
			<Woo.Pill className={'pill'}>
				{__('Pill Shape Container', 'product-upload')}
			</Woo.Pill>
		</Woo.Section>

		<Woo.Section component="article">
			<Woo.SectionHeader
				title={__('Spinner', 'product-upload')}
			/>
			<Woo.H>I am a spinner!</Woo.H>
			<Woo.Spinner />
		</Woo.Section>

		<Woo.Section component="article">
			<Woo.SectionHeader
				title={__('Datepicker', 'product-upload')}
			/>
			<Woo.DatePicker
				text={__(
					'I am a datepicker!',
					'product-upload'
				)}
				dateFormat={'MM/DD/YYYY'}
			/>
		</Woo.Section>
	</Fragment>
);

addFilter(
	'woocommerce_admin_pages_list',
	'product-upload',
	(pages) => {
		pages.push({
			container: MyExamplePage,
			path: '/product-upload',
			breadcrumbs: [
				__(
					'Product Upload for WooCommerce',
					'product-upload'
				),
			],
			navArgs: {
				id: 'product_upload_for_woocommerce',
			},
		});

		return pages;
	}
);
