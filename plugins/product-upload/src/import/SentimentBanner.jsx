import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Two-phase review prompt banner.
 *
 *  - status='eligible'        → three-way sentiment + dismiss
 *  - status='awaiting_phase2' → follow-up CTA opening WP.org plugin reviews page
 *
 * positive → arms Phase-2 follow-up (button click only — WP.org has no
 *            in-context API, and silent tab-popping is hostile UX).
 * neutral  → 60d cooldown, no review ever solicited (avoids 3-star drag).
 * negative → opens Crisp pre-filled (Crisp is loaded by App.jsx).
 * dismiss  → 30d snooze.
 *
 * All eligibility / quota / pre-gate logic lives in the SQL function
 * get_review_prompt_eligibility — this component just reads/writes state.
 */

const WP_ORG_REVIEW_URL =
	'https://wordpress.org/support/plugin/product-upload/reviews/#new-post';

export default function SentimentBanner() {
	const [status, setStatus] = useState(null);
	const [reason, setReason] = useState(null);
	const [loading, setLoading] = useState(true);

	useEffect(() => {
		let cancelled = false;
		apiFetch({ path: '/product-upload/v1/review-prompt-status' })
			.then((data) => {
				if (cancelled) return;
				setStatus(data?.status ?? 'ineligible');
				setReason(data?.reason ?? null);
			})
			.catch(() => {
				if (!cancelled) setStatus('ineligible');
			})
			.finally(() => {
				if (!cancelled) setLoading(false);
			});
		return () => {
			cancelled = true;
		};
	}, []);

	const submitSentiment = useCallback(
		async (action) => {
			// Optimistic hide. Positive → arms Phase-2 follow-up CTA in same banner.
			setStatus(action === 'positive' ? 'awaiting_phase2' : 'ineligible');
			try {
				await apiFetch({
					path: '/product-upload/v1/review-sentiment',
					method: 'POST',
					data: { action, eligibility_reason: reason },
				});
			} catch (e) {
				console.error('[review-sentiment] submit failed:', e);
			}
		},
		[reason]
	);

	const openCrispNegative = () => {
		// Open Crisp + show a founder greeting via message:show (renders as an
		// operator message). Pre-filling the composer with message:text is
		// easier to miss and feels less personal than an actual greeting.
		if (typeof window !== 'undefined' && window.$crisp) {
			window.$crisp.push(['do', 'chat:open']);
			window.$crisp.push([
				'do',
				'message:show',
				[
					'text',
					__(
						"Sorry to hear it's not clicking 😔 I'm Harrison, the founder. What's going wrong? I read every message and will jump in to help.",
						'product-upload',
					),
				],
			]);
		}
	};

	const openCrispNeutral = () => {
		// Aspirational ask: capture feature requests + missing-feature signals
		// from middle-of-the-road users — highest-leverage product input.
		if (typeof window !== 'undefined' && window.$crisp) {
			window.$crisp.push(['do', 'chat:open']);
			window.$crisp.push([
				'do',
				'message:show',
				[
					'text',
					__(
						"Thanks for the honest take! I'm Harrison, the founder. What would make ProductUpload feel like a 10/10 for you — a missing feature, something annoying, anything? I read every reply.",
						'product-upload',
					),
				],
			]);
		}
	};

	const handleSentiment = async (action) => {
		await submitSentiment(action);
		if (action === 'negative') openCrispNegative();
		if (action === 'neutral') openCrispNeutral();
	};

	const openReviewPage = async () => {
		try {
			window.open(WP_ORG_REVIEW_URL, '_blank', 'noopener,noreferrer');
		} catch (e) {
			console.error('[SentimentBanner] window.open failed:', e);
		}
		// success=true + code='success' → RPC snoozes 60d (same path Shopify uses).
		try {
			await apiFetch({
				path: '/product-upload/v1/review-attempt',
				method: 'POST',
				data: {
					success: true,
					code: 'success',
					channel: 'in_app_banner',
					metadata: { ui: 'wp_org_review_link' },
				},
			});
		} catch (e) {
			console.error('[review-attempt] log failed:', e);
		}
	};

	if (loading) return null;

	if (status === 'eligible') {
		return (
			<Notice key="sentiment" status="info" isDismissible={false}>
				<div className="pu-wc-sentiment-banner">
					<span>{__("How's ProductUpload working out so far?", 'product-upload')}</span>
					<div className="pu-wc-sentiment-banner__actions">
						<Button variant="secondary" onClick={() => handleSentiment('positive')}>
							{__('😍 Loving it', 'product-upload')}
						</Button>
						<Button variant="secondary" onClick={() => handleSentiment('neutral')}>
							{__("😐 It's okay", 'product-upload')}
						</Button>
						<Button variant="secondary" onClick={() => handleSentiment('negative')}>
							{__('😞 Not great', 'product-upload')}
						</Button>
						<Button variant="tertiary" onClick={() => handleSentiment('dismiss')}>
							{__('Maybe later', 'product-upload')}
						</Button>
					</div>
				</div>
			</Notice>
		);
	}

	if (status === 'awaiting_phase2') {
		return (
			<Notice key="phase2" status="success" isDismissible={false}>
				<div className="pu-wc-sentiment-banner">
					<span>
						{__('Thanks! Mind sharing on WordPress.org?', 'product-upload')}
					</span>
					<div className="pu-wc-sentiment-banner__actions">
						<Button variant="primary" onClick={openReviewPage}>
							{__('Leave a review →', 'product-upload')}
						</Button>
						<Button variant="tertiary" onClick={() => handleSentiment('dismiss')}>
							{__('Not now', 'product-upload')}
						</Button>
					</div>
				</div>
			</Notice>
		);
	}

	return null;
}
