// account-bar balance pill; mirrors Shopify's
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

const LOW_WATER = 5;
const RUNNING_LOW = 2;

// "nearly gone", not a fraction of an allowance
const MiniMeter = ({ remaining, kind }) => (
	<span className={`pu-wc-imports-meter pu-wc-imports-meter--${kind}`} aria-hidden="true">
		{Array.from({ length: LOW_WATER }, (_, i) => (
			<span key={i} className={i < remaining ? 'is-filled' : ''} />
		))}
	</span>
);

// one grid cell, so hover never resizes the pill
const SwapLabel = ({ showHover, resting, hover }) => (
	<span className="pu-wc-imports-swap">
		<span style={{ visibility: showHover ? 'hidden' : 'visible' }}>{resting}</span>
		<span style={{ visibility: showHover ? 'visible' : 'hidden' }}>{hover}</span>
	</span>
);

const ImportsControl = ({ balance, isActivePlan, onUpgrade }) => {
	const [hover, setHover] = useState(false);
	const remaining = Math.max(0, Number(balance) || 0);

	const free = !isActivePlan;
	const spent = remaining === 0;
	const low = free && remaining <= RUNNING_LOW;
	const showMeter = free && !spent && remaining <= LOW_WATER;

	const label = spent
		? __('Out of imports', 'product-upload')
		: sprintf(
			// translators: %d: imports remaining.
			_n('%d import left', '%d imports left', remaining, 'product-upload'),
			remaining
		);
	const next = free
		? __('Choose a plan', 'product-upload')
		: __('Change plan', 'product-upload');
	const kind = spent ? 'critical' : low ? 'caution' : free ? 'neutral' : 'quiet';

	const resting = (
		<>
			{showMeter && <MiniMeter remaining={remaining} kind={kind} />}
			{label}
			{free && ` · ${__('Upgrade', 'product-upload')}`}
		</>
	);

	return (
		<button
			type="button"
			className={`pu-wc-imports-pill pu-wc-imports-pill--${kind}`}
			onClick={onUpgrade}
			onMouseEnter={() => setHover(true)}
			onMouseLeave={() => setHover(false)}
			onFocus={() => setHover(true)}
			onBlur={() => setHover(false)}
			aria-label={`${label}. ${next}`}
		>
			<SwapLabel showHover={hover} resting={resting} hover={`${next} →`} />
		</button>
	);
};

export default ImportsControl;
