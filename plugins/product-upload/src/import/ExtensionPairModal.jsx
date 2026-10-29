/**
 * Integrations: pair the Chrome extension from inside WooCommerce: mint a short-lived code
 * here and have the merchant paste it into the extension popup, so they never
 * have to leave wp-admin for productupload.co.
 *
 * @package ProductUploadForWoocommerce
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal, Button, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { IntegrationGlyph } from './IntegrationGlyph';

export const CWS_URL =
	'https://chromewebstore.google.com/detail/product-upload/ljpmjnjdkgddjcgndgnhajnfcnpopjgm';

const AI_GUIDE_URL =
	'https://productupload.co/ai-assistants?utm_source=woocommerce-app&utm_medium=in-app&utm_content=integrations';

const ExtensionPairModal = ({ onClose, onSetUpAssistant }) => {
	const [code, setCode] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [copied, setCopied] = useState(false);

	const mint = useCallback(() => {
		setLoading(true);
		setError(null);
		setCopied(false);
		apiFetch({ path: '/product-upload/v1/pair-code', method: 'POST' })
			.then((data) => setCode((data && data.code) || null))
			.catch((err) =>
				setError(
					(err && err.message) ||
					__('Could not generate a pairing code. Please try again.', 'product-upload')
				)
			)
			.finally(() => setLoading(false));
	}, []);

	useEffect(() => {
		mint();
	}, [mint]);

	// the email an assistant signs in with
	const [signInEmail, setSignInEmail] = useState(undefined);
	useEffect(() => {
		apiFetch({ path: '/product-upload/v1/users' })
			.then((data) => {
				const me = (data?.users || []).find((u) => u.profile_id === data?.currentProfileId);
				setSignInEmail(me?.email || null);
			})
			.catch(() => setSignInEmail(null));
	}, []);
	const generated = !signInEmail || /@wc\.productupload\.co$|@notanemail\.com$|^notanemail@/i.test(signInEmail);

	const copy = () => {
		if (!code) {
			return;
		}
		try {
			navigator.clipboard.writeText(code);
			setCopied(true);
		} catch {
			/* clipboard blocked */
		}
	};

	return (
		<Modal
			title={__('Integrations', 'product-upload')}
			onRequestClose={onClose}
			className="pu-wc-pair-modal"
		>
			<h3 className="pu-wc-pair__section">
				<IntegrationGlyph kind="extension" />
				{__('Browser extension', 'product-upload')}
			</h3>
			<p className="pu-wc-pair__intro">
				{__(
					'The browser extension captures products from sites that block URL imports (logged-in wholesale, anti-bot pages) straight into this store.',
					'product-upload'
				)}
			</p>

			<div className="pu-wc-pair__step">
				<span className="pu-wc-pair__step-num">1</span>
				<div>
					<div className="pu-wc-pair__step-title">
						{__('Install the extension', 'product-upload')}
					</div>
					<Button variant="secondary" href={CWS_URL} target="_blank" rel="noreferrer">
						{__('Get it on the Chrome Web Store', 'product-upload')}
					</Button>
				</div>
			</div>

			<div className="pu-wc-pair__step">
				<span className="pu-wc-pair__step-num">2</span>
				<div className="pu-wc-pair__step-body">
					<div className="pu-wc-pair__step-title">
						{__(
							'Open the extension, click "Connect a store", and paste this code',
							'product-upload'
						)}
					</div>

					{loading ? (
						<div className="pu-wc-pair__code-row">
							<Spinner />
						</div>
					) : error ? (
						<Notice status="error" isDismissible={false}>
							{error}
						</Notice>
					) : (
						<>
							<div className="pu-wc-pair__code-row">
								<code className="pu-wc-pair__code">{code}</code>
								<Button variant="primary" onClick={copy}>
									{copied
										? __('Copied', 'product-upload')
										: __('Copy', 'product-upload')}
								</Button>
							</div>
							<div className="pu-wc-pair__hint">
								{__('Codes expire after 90 seconds.', 'product-upload')}{' '}
								<Button variant="link" onClick={mint}>
									{__('Generate a new code', 'product-upload')}
								</Button>
							</div>
						</>
					)}
				</div>
			</div>

			<h3 className="pu-wc-pair__section">
				<IntegrationGlyph kind="ai" />
				{__('AI assistants', 'product-upload')}
			</h3>
			<p className="pu-wc-pair__intro">
				{__(
					'Connect Claude or ChatGPT to Product Upload to check on imports or update a product from a chat. Imports use the same settings as this plugin.',
					'product-upload'
				)}
			</p>
			{signInEmail !== undefined && !generated && (
				<p className="pu-wc-pair__intro">
					{__('Sign in with', 'product-upload')} <strong>{signInEmail}</strong>
				</p>
			)}
			<p>
				{onSetUpAssistant && signInEmail !== undefined && generated && (
					<Button variant="secondary" onClick={onSetUpAssistant}>
						{__('Confirm an email', 'product-upload')}
					</Button>
				)}{' '}
				<Button variant="link" href={AI_GUIDE_URL} target="_blank" rel="noreferrer">
					{__('How to connect', 'product-upload')}
				</Button>
			</p>
		</Modal>
	);
};

export default ExtensionPairModal;
