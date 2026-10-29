/**
 * Card + segmented-toggle primitives for the settings modal.
 *
 * Mirrors shopify-app/web/components/ContentFields.jsx (FieldCard / SourceSegment
 * / CARD_CSS) in WordPress components so the Woo settings match the Shopify
 * look. Keep in sync.
 */
import { Icon } from '@wordpress/components';

export const CARD_CSS = `
.pu-fcard { border: 1px solid #e0e0e0; border-radius: 12px; background: #fff; }
.pu-fcard + .pu-fcard { margin-top: 12px; }
.pu-fcard__head { display: flex; align-items: center; gap: 12px; padding: 12px 14px; width: 100%; box-sizing: border-box; text-align: left; }
.pu-fcard__head--toggle { cursor: pointer; border-radius: 11px; transition: background .12s; }
.pu-fcard__head--toggle:hover, .pu-adv:hover { background: #f6f6f7; }
.pu-fcard__chip { width: 32px; height: 32px; flex: none; display: flex; align-items: center; justify-content: center;
	border-radius: 9px; color: var(--accent); background: color-mix(in srgb, var(--accent) 14%, transparent); }
.pu-fcard__chip svg { width: 19px; height: 19px; fill: currentColor; display: block; }
.pu-fcard__body .components-button svg, .pu-adv-body .components-button svg { width: 20px; height: 20px; flex: none; }
.pu-fcard__chip--muted { --accent: #757575; background: #f1f1f3; }
.pu-fcard__meta { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 1px; }
.pu-fcard__label { font-size: 14px; font-weight: 600; color: #1e1e1e; }
.pu-fcard__sub { font-size: 12px; color: #757575; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pu-fcard__body { padding: 2px 14px 14px 58px; }
.pu-csrc { display: inline-flex; flex: none; gap: 2px; padding: 3px; border-radius: 9px; background: #f1f1f3; }
.pu-csrc--full { display: flex; }
.pu-csrc--full button { flex: 1; }
.pu-csrc button { border: 0; background: none; padding: 5px 13px; border-radius: 6px; cursor: pointer;
	font-size: 12.5px; font-weight: 500; color: #757575; transition: background .14s, color .14s, box-shadow .14s; }
.pu-csrc button:hover:not(.is-on) { color: #1e1e1e; }
.pu-csrc button.is-on { background: #fff; color: #1e1e1e; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
@media (max-width: 520px) {
	.pu-fcard__head { flex-wrap: wrap; }
	.pu-fcard__head > .pu-csrc { flex-basis: 100%; margin-top: 10px; display: flex; }
	.pu-fcard__head > .pu-csrc button { flex: 1; }
	.pu-fcard__body { padding-left: 14px; }
}
.pu-adv { cursor: pointer; background: none; border: 0; border-radius: 11px; transition: background .12s; }
.pu-adv__edit { flex: none; margin-left: auto; font-size: 12px; font-weight: 600; color: #3858e9; }
.pu-adv__chev { flex: none; display: flex; color: #3858e9; transition: transform .15s; }
.pu-adv__chev svg { width: 18px; height: 18px; fill: currentColor; display: block; }
.pu-adv[aria-expanded="true"] .pu-adv__chev { transform: rotate(180deg); }
.pu-adv-body { padding: 4px 14px 16px; display: flex; flex-direction: column; gap: 18px; }
.pu-adv-group { display: flex; flex-direction: column; gap: 6px; }
.pu-adv-group__title { font-size: 12px; font-weight: 600; color: #1e1e1e; }
.pu-adv-note { font-size: 12px; color: #757575; margin: 0; }
.pu-rename-row { display: flex; gap: 8px; align-items: flex-start; }
.pu-rename-row__field { flex: 1; min-width: 0; }
.pu-rename-row__arrow { color: #757575; padding-top: 6px; }
.pu-fcard__body-check { margin-top: 12px; }
.pu-fcard__reveal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px; }
.pu-fcard__reveal-head > span { font-size: 13px; font-weight: 500; color: #1e1e1e; }
`;

export const SourceSegment = ({ value, options, onChange, full }) => (
	<div className={`pu-csrc${full ? ' pu-csrc--full' : ''}`} role="group">
		{options.map((o) => (
			<button
				type="button"
				key={o.value}
				className={value === o.value ? 'is-on' : ''}
				aria-pressed={value === o.value}
				onClick={() => onChange(o.value)}
			>
				{o.label}
			</button>
		))}
	</div>
);

export const FieldCard = ({ accent, icon, label, summary, control, onToggle, children }) => {
	const onHeadClick = onToggle
		? (e) => {
				if (e.target.closest('.pu-csrc')) {
					return;
				}
				onToggle();
			}
		: undefined;
	return (
		<div className="pu-fcard" style={{ '--accent': accent }}>
			<div
				className={`pu-fcard__head${onToggle ? ' pu-fcard__head--toggle' : ''}`}
				onClick={onHeadClick}
			>
				<span className="pu-fcard__chip">
					<Icon icon={icon} />
				</span>
				<span className="pu-fcard__meta">
					<span className="pu-fcard__label">{label}</span>
					{summary ? <span className="pu-fcard__sub">{summary}</span> : null}
				</span>
				{control}
			</div>
			{children}
		</div>
	);
};
