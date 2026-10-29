/**
 * Reusable "skeleton -> animated drop-in on delay" loader for the slow
 * discovery flows (marketplace search, collection fetch, product discovery).
 * Mirrors SupplierOverview's LoadingBody: a layout skeleton shows instantly
 * (covers fast fetches), then a context graphic + cycling phases fade in over
 * it only when the operation is genuinely taking a while. Reuses the existing
 * .pu-wc-ov-overlay / .pu-wc-ov-research styles.
 *
 * @package ProductUploadForWoocommerce
 */
import { useEffect, useState } from '@wordpress/element';

const PHASE_MS = 4500;
const DEFAULT_SUB = 'This usually takes 15 to 60 seconds. Keep this tab open.';

// fill: stretch to the parent's height
export function DiscoveryLoader({ skeleton, Graphic, phases, subject, graceMs = 2500, sub, fill = false }) {
	const [escalated, setEscalated] = useState(false);
	useEffect(() => {
		const id = setTimeout(() => setEscalated(true), graceMs);
		return () => clearTimeout(id);
	}, [graceMs]);

	return (
		<div
			style={
				fill
					? { position: 'relative', flex: 1, display: 'flex', flexDirection: 'column' }
					: { position: 'relative' }
			}
		>
			{skeleton}
			{escalated && (
				<div className="pu-wc-ov-overlay">
					<div className="pu-wc-ov-overlay__scrim" />
					<div className="pu-wc-ov-overlay__inner">
						<PhasePanel Graphic={Graphic} phases={phases} subject={subject} sub={sub} />
					</div>
				</div>
			)}
		</div>
	);
}

function PhasePanel({ Graphic, phases, subject, sub }) {
	const [i, setI] = useState(0);
	useEffect(() => {
		const id = setInterval(() => setI((n) => (n + 1) % phases.length), PHASE_MS);
		return () => clearInterval(id);
	}, [phases.length]);
	const msg = (phases[i] || '').replace('{subject}', subject || 'the site');
	return (
		<div className="pu-wc-ov-research">
			{Graphic ? <Graphic /> : null}
			<p className="pu-wc-ov-research__phase" aria-live="polite">{msg}</p>
			<p className="pu-wc-ov-research__sub">{sub || DEFAULT_SUB}</p>
		</div>
	);
}

// sitemap: branch to leaves
export function SitemapCrawlGraphic() {
	return (
		<div aria-hidden="true">
			<svg width="220" height="140" viewBox="0 0 240 150" xmlns="http://www.w3.org/2000/svg">
				<style>{`
					@keyframes pu-wc-dlsm-draw { 0%{stroke-dashoffset:100} 35%{stroke-dashoffset:0} 82%{stroke-dashoffset:0} 100%{stroke-dashoffset:100} }
					@keyframes pu-wc-dlsm-pop { 0%,18%{opacity:0;transform:translateY(7px)} 42%,86%{opacity:1;transform:translateY(0)} 100%{opacity:0;transform:translateY(7px)} }
					@keyframes pu-wc-dlsm-pulse { 0%,100%{opacity:.45} 50%{opacity:.9} }
					.pu-wc-dlsm-line { stroke-dasharray:100; animation: pu-wc-dlsm-draw 3.6s ease-in-out infinite; }
					.pu-wc-dlsm-line-2{animation-delay:.3s}.pu-wc-dlsm-line-3{animation-delay:.6s}
					.pu-wc-dlsm-leaf { animation: pu-wc-dlsm-pop 3.6s ease-in-out infinite; }
					.pu-wc-dlsm-leaf-1{animation-delay:.5s}.pu-wc-dlsm-leaf-2{animation-delay:.8s}.pu-wc-dlsm-leaf-3{animation-delay:1.1s}
					.pu-wc-dlsm-ring { animation: pu-wc-dlsm-pulse 1.8s ease-in-out infinite; transform-origin:center; }
					@media (prefers-reduced-motion: reduce){ .pu-wc-dlsm-line,.pu-wc-dlsm-leaf,.pu-wc-dlsm-ring{animation:none;stroke-dashoffset:0;opacity:1} }
				`}</style>
				<line className="pu-wc-dlsm-line" x1="120" y1="40" x2="44" y2="100" stroke="#7480ff" strokeWidth="2" strokeLinecap="round" />
				<line className="pu-wc-dlsm-line pu-wc-dlsm-line-2" x1="120" y1="40" x2="120" y2="100" stroke="#7480ff" strokeWidth="2" strokeLinecap="round" />
				<line className="pu-wc-dlsm-line pu-wc-dlsm-line-3" x1="120" y1="40" x2="196" y2="100" stroke="#7480ff" strokeWidth="2" strokeLinecap="round" />
				<circle className="pu-wc-dlsm-ring" cx="120" cy="24" r="18" fill="rgba(116,128,255,0.12)" />
				<rect x="100" y="12" width="40" height="24" rx="6" fill="#FAFBFB" stroke="#7480ff" strokeWidth="1.5" />
				<rect x="106" y="18" width="28" height="4" rx="2" fill="#7480ff" />
				<rect x="106" y="26" width="20" height="3" rx="1.5" fill="#C9CCCF" />
				{[18, 94, 170].map((x, i) => (
					<g key={x} className={`pu-wc-dlsm-leaf pu-wc-dlsm-leaf-${i + 1}`}>
						<rect x={x} y="100" width="52" height="40" rx="6" fill="#FFFFFF" stroke="#E1E3E5" strokeWidth="1.5" />
						<rect x={x + 6} y="106" width="40" height="18" rx="3" fill="#E4E5E7" />
						<rect x={x + 6} y="128" width="28" height="4" rx="2" fill="#C9CCCF" />
						<rect x={x + 38} y="128" width="8" height="4" rx="2" fill="#5BBD7E" />
					</g>
				))}
			</svg>
		</div>
	);
}
