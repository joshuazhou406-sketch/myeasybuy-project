/**
 * Blueprint description-layout builder for the Woo settings modal.
 *
 * A block list (reorder / add / remove / configure) with a live preview,
 * rendered in the "clean" look only. Mirrors the Shopify builder
 * (shopify-app/web/components/DescriptionLayoutBuilder.jsx) in WordPress
 * components. Keep the block model in sync via ./descriptionLayout.
 */
import { createContext, useContext, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Modal,
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	DropdownMenu,
} from '@wordpress/components';
import { chevronUp, chevronDown, trash, plus } from '@wordpress/icons';
import {
	NEW_BLOCK,
	BLOCK_TYPES,
	BLOCK_LABEL,
	BLOCK_SOURCE,
	TONE_OPTIONS,
	SECTION_KINDS,
	ALL_SECTION_KINDS,
	SECTION_LABEL,
	sourceSectionMode,
	sourceSectionKinds,
	DEFAULT_LAYOUT,
	withBlockIds,
	serializeLayout,
	describeLayout,
	blockSummary,
} from './descriptionLayout';

// Keep: overview = supplier text, no FAQ
const KeepContext = createContext(false);
const keepLabel = (type) =>
	type === 'ai_overview' ? __("Supplier's description", 'product-upload') : BLOCK_LABEL[type];
// a supplier's own, plainer copy
const SUPPLIER_SAMPLE =
	'Noa vertical radiator, anthracite. Steel, powder-coated. 1800 x 470mm, 3242 BTU. 10 year guarantee.';

// preview sample content
const SAMPLE = {
	title: 'JTP Noa Anthracite Vertical Designer Radiator 1800 x 470mm',
	specs: [
		{ key: 'Height', value: '1800mm' },
		{ key: 'Width', value: '470mm' },
		{ key: 'Finish', value: 'Anthracite' },
		{ key: 'Material', value: 'Steel' },
		{ key: 'Heat Output', value: '3242 BTU' },
	],
	specsExtra: [
		{ key: 'Projection', value: '95mm' },
		{ key: 'Pipe Centres', value: '400mm' },
		{ key: 'Fixing Centres', value: '1730 x 400mm' },
		{ key: 'Weight', value: '24kg' },
		{ key: 'Connections', value: '1/2" BSP' },
		{ key: 'Max Working Pressure', value: '10 bar' },
		{ key: 'Guarantee', value: '10 years' },
	],
	sections: {
		highlights: ['High 3242 BTU heat output', 'Slimline 470mm footprint', '10 year guarantee'],
		materials_care: ['Premium powder-coated steel', 'Wipe clean with a soft, damp cloth'],
		sizing_fit: ['Vertical orientation suits narrow walls', 'Projects 95mm from the wall'],
		size_chart: ['1800 x 470mm (H x W)', 'Fixing centres: 1730 x 400mm'],
		compatibility: ['Works with standard 1/2" valve connections', 'Suitable for sealed systems'],
		how_to_use: ['Mount to a load-bearing wall', 'Bleed before first use'],
		ingredients: ['Mild steel body', 'Powder-coat finish'],
		warranty: ['10 year manufacturer guarantee against manufacturing defects'],
		whats_in_the_box: ['Radiator', 'Wall mounting kit', 'Air vent and blanking plug'],
	},
	faq: [
		{ question: 'Is this radiator electric?', answer: 'No, it is a central-heating radiator that connects to standard valves.' },
		{ question: 'What is the heat output?', answer: '3242 BTU, suitable for small to medium rooms.' },
		{ question: 'Does it come with a wall mounting kit?', answer: 'Yes, all fixings and a fitting guide are included in the box.' },
		{ question: 'Can the finish be painted over?', answer: 'No, the powder-coated finish should not be painted.' },
		{ question: 'Is it suitable for bathrooms?', answer: 'Yes, when installed outside the wet zones.' },
		{ question: 'What valves do I need?', answer: 'Any standard 1/2" valves; angled valves suit most vertical installations.' },
	],
	documents: [
		{ label: 'Installation guide' },
		{ label: 'Technical datasheet' },
		{ label: 'Warranty terms' },
	],
};

const TONE_PARAGRAPHS = {
	professional: [
		'The Noa vertical radiator from JTP combines bold anthracite styling with a high heat output, making it a striking centrepiece for any modern living space. Its slim vertical profile draws the eye while warming the room with quiet efficiency.',
		'Built from premium steel with a durable powder-coated finish, it delivers reliable warmth that lasts for years. The compact 470mm width slots neatly into narrow wall spaces where a horizontal radiator simply will not fit.',
		'A 10 year manufacturer guarantee comes as standard for complete peace of mind. Standard 1/2 inch valve connections let a qualified fitter have it running in under an hour.',
	],
	persuasive: [
		'Imagine stepping into a warm room where the radiator is part of the decor, not an afterthought. The Noa makes that happen from the day it goes on the wall.',
		'Its slim vertical profile frees up the wall space that bulkier radiators waste, so you get more warmth from less room. More heat, less clutter, and a room that finally feels finished.',
		'And with a 10 year guarantee included, there has never been a better time to upgrade. Order today and turn the coldest corner of your home into its cosiest.',
	],
	playful: [
		'Meet the Noa: the radiator that decided boring was optional. Bold anthracite looks, serious warmth, and absolutely zero fuss.',
		'It stands tall, slips into narrow spots, and turns the chilliest corner into the cosiest seat in the house. Your toes are going to love it.',
		'Ten year guarantee? Of course. Fit it, forget it, and bask away.',
	],
	formal: [
		'The Noa vertical radiator is engineered to provide dependable heating performance while maintaining a refined anthracite appearance. The vertical format is particularly suited to rooms with limited horizontal wall space.',
		'Constructed from premium-grade steel with a powder-coated finish, the unit is well suited to contemporary residential interiors. The finish resists everyday wear and is straightforward to maintain.',
		'Each radiator is supplied with a 10 year manufacturer guarantee. Connections conform to standard sizing for compatibility with most central heating systems.',
	],
	expert: [
		'The Noa pairs a 3242 BTU output with a compact vertical footprint, an efficient choice for rooms where horizontal wall space is limited. At 1800 x 470mm it suits standard fixing centres without modification.',
		'Its mild steel body and powder-coat finish resist corrosion in heated living areas. Fixing centres of 1730 x 400mm align with common bracket spacing for a secure mount.',
		'It is specified with a 10 year manufacturer guarantee. Output is quoted at Delta T50 for straightforward sizing against room requirements.',
	],
	friendly: [
		'Say hello to the Noa, a tall and good-looking radiator that keeps your room lovely and warm. It does the job without taking up much wall at all.',
		'It is built from sturdy steel with a smooth anthracite finish, so it looks great and lasts for years. A quick wipe with a damp cloth is all it ever needs.',
		'And with a 10 year guarantee, you can fit it and simply forget about it. Standard valves mean most plumbers will have it sorted in no time.',
	],
	casual: [
		'The Noa is a tall, dark and handsome radiator that just gets on with keeping your place warm. And it actually looks decent while doing it.',
		'Solid steel, a nice matt finish, and it fits where bulkier radiators will not. Give it a wipe now and then and it stays looking sharp.',
		'Comes with a 10 year guarantee too. Standard fittings make it an easy swap for your old one.',
	],
	technical: [
		'Vertical panel radiator measuring 1800 x 470mm and delivering 3242 BTU (950W) at Delta T50. Net weight is approximately 24kg, suited to a load-bearing wall.',
		'The anthracite finish is powder-coated to RAL 7016 with a 95mm projection from the wall. Wall brackets and fixings are supplied in the box.',
		'Rated for sealed and open central heating systems at up to 10 bar working pressure. Compatible with 1/2 inch BSP valve connections.',
	],
};

const previewSpecs = (format, mode) => {
	const specs = mode === 'extensive' ? [...SAMPLE.specs, ...SAMPLE.specsExtra] : SAMPLE.specs;
	if (format === 'table') {
		return (
			<table className="pu-wc-dlb-spectable" width="100%">
				<tbody>
					{specs.map((s) => (
						<tr key={s.key}>
							<td>{s.key}</td>
							<td>{s.value}</td>
						</tr>
					))}
				</tbody>
			</table>
		);
	}
	if (format === 'list') {
		return (
			<p>
				{specs.flatMap((s) => [
					<strong key={`${s.key}-k`}>{s.key}:</strong>,
					` ${s.value}`,
					<br key={`${s.key}-b`} />,
				])}
			</p>
		);
	}
	return (
		<ul>
			{specs.map((s) => (
				<li key={s.key}>
					<strong>{s.key}:</strong> {s.value}
				</li>
			))}
		</ul>
	);
};

const sectionBody = (kind) => {
	if (kind === 'file_downloads') {
		return (
			<ul>
				{SAMPLE.documents.map((d) => (
					<li key={d.label}>
						<a href="#" onClick={(e) => e.preventDefault()}>{d.label}</a>
					</li>
				))}
			</ul>
		);
	}
	if (kind === 'videos') {
		return (
			<div className="pu-wc-dlb-video" aria-hidden="true">
				<svg width="40" height="40" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4v16l13 -8z" /></svg>
			</div>
		);
	}
	return (
		<ul>
			{(SAMPLE.sections[kind] ?? []).map((item) => (
				<li key={item}>{item}</li>
			))}
		</ul>
	);
};

const withHeading = (block, content) =>
	content ? (
		<>
			{block.heading ? <h3>{block.heading}</h3> : null}
			{content}
		</>
	) : null;

const SourceSectionsPreview = ({ kinds }) => {
	const [showAll, setShowAll] = useState(false);
	const shown = showAll ? kinds : kinds.slice(0, 2);
	const hidden = kinds.length - shown.length;
	return (
		<>
			{shown.map((kind) => (
				<div className="pu-wc-dlb-srcsec" key={kind}>
					<h3>{SECTION_LABEL[kind] ?? __('Section', 'product-upload')}</h3>
					{sectionBody(kind)}
				</div>
			))}
			{kinds.length > 2 && (
				<button type="button" className="pu-wc-dlb-more" onClick={() => setShowAll((v) => !v)}>
					{showAll
						? __('Show fewer', 'product-upload')
						: /* translators: %d: number of hidden sections */
							`+${hidden} ${__('more sections', 'product-upload')}`}
				</button>
			)}
		</>
	);
};

const PreviewBlock = ({ block }) => {
	const keep = useContext(KeepContext);
	if (keep && block.type === 'ai_overview') return withHeading(block, <p>{SUPPLIER_SAMPLE}</p>);
	if (keep && block.type === 'faq') return null;
	switch (block.type) {
		case 'title':
			return <h2>{SAMPLE.title}</h2>;
		case 'ai_overview':
			return withHeading(
				block,
				(TONE_PARAGRAPHS[block.tone] ?? TONE_PARAGRAPHS.professional)
					.slice(0, block.paragraphs || 2)
					.map((p, i) => <p key={i}>{p}</p>)
			);
		case 'specs':
			return withHeading(block, previewSpecs(block.format, block.mode));
		case 'description_images':
			return withHeading(
				block,
				<div style={{ display: 'grid', gap: 6 }}>
					{[0, 1].map((i) => (
						<div
							key={i}
							style={{
								background: '#f1f2f4',
								border: '1px solid #e3e3e3',
								borderRadius: 8,
								height: 54,
							}}
						/>
					))}
				</div>
			);
		case 'custom_text':
			return block.html ? <div dangerouslySetInnerHTML={{ __html: block.html }} /> : null;
		case 'faq': {
			const items = SAMPLE.faq.slice(0, block.count || 4);
			const content =
				block.display === 'plain' ? (
					<>
						{items.map((q) => (
							<div className="pu-wc-dlb-faqpair" key={q.question}>
								<h4>{q.question}</h4>
								<p>{q.answer}</p>
							</div>
						))}
					</>
				) : (
					<>
						{items.map((q, i) => (
							<details className="pu-wc-dlb-faq" key={q.question} open={i === 0}>
								<summary><strong>{q.question}</strong></summary>
								<p>{q.answer}</p>
							</details>
						))}
					</>
				);
			return withHeading(block, content);
		}
		case 'source_sections': {
			const kinds = sourceSectionKinds(block);
			return kinds.length ? <SourceSectionsPreview kinds={kinds} /> : null;
		}
		default:
			return null;
	}
};

const PREVIEW_CSS = `
.pu-wc-dlb-preview { font-size: 13px; line-height: 1.55; color: #1e1e1e; }
.pu-wc-dlb-preview > *:first-child { margin-top: 0; }
.pu-wc-dlb-preview p, .pu-wc-dlb-preview ul, .pu-wc-dlb-preview table { margin: 0 0 12px; }
.pu-wc-dlb-preview h2 { font-size: 17px; font-weight: 650; margin: 0 0 12px; }
.pu-wc-dlb-preview h3 { font-size: 14px; font-weight: 650; margin: 18px 0 8px; }
.pu-wc-dlb-preview h4 { font-size: 13px; font-weight: 650; margin: 10px 0 2px; }
.pu-wc-dlb-preview ul { padding-left: 18px; }
.pu-wc-dlb-preview li { margin: 3px 0; }
.pu-wc-dlb-spectable { border-collapse: collapse; }
.pu-wc-dlb-spectable td { padding: 4px 12px 4px 0; vertical-align: top; }
.pu-wc-dlb-srcsec + .pu-wc-dlb-srcsec { border-top: 1px solid #e0e0e0; }
.pu-wc-dlb-faq { padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
.pu-wc-dlb-faq summary { cursor: pointer; list-style: none; }
.pu-wc-dlb-faq summary::-webkit-details-marker { display: none; }
.pu-wc-dlb-faq p { margin: 6px 0 0; }
.pu-wc-dlb-faqpair p { margin: 0 0 8px; }
.pu-wc-dlb-video { width: 100%; max-width: 320px; aspect-ratio: 16/9; background: #111; color: #fff;
	border-radius: 6px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.pu-wc-dlb-more { display: inline-flex; align-items: center; margin: 4px 0 12px; padding: 6px 12px; gap: 4px;
	cursor: pointer; font: inherit; font-size: 12px; font-weight: 600; border: 0; border-radius: 6px;
	background: rgba(0,0,0,.05); color: #555; }
`;

const DescriptionPreview = ({ blocks }) => (
	<div className="pu-wc-dlb-preview">
		<style>{PREVIEW_CSS}</style>
		{blocks
			.filter((b) => b && b.enabled !== false)
			.map((b, i) => (
				<PreviewBlock key={b._id || i} block={b} />
			))}
	</div>
);

// Per-block settings editors
const CollapsibleField = ({ value, label, addLabel, placeholder, multiline, onChange }) =>
	value === undefined ? (
		<Button className="pu-wc-dlb-addbtn pu-wc-dlb-addbtn--sm" icon={plus} onClick={() => onChange('')}>
			{addLabel}
		</Button>
	) : multiline ? (
		<TextareaControl
			label={label}
			value={value}
			placeholder={placeholder}
			rows={2}
			onChange={onChange}
		/>
	) : (
		<TextControl label={label} value={value} placeholder={placeholder} onChange={onChange} />
	);

const SourceSectionsControl = ({ block, onChange }) => {
	const mode = sourceSectionMode(block);
	const selected = sourceSectionKinds(block);
	const toggle = (kind) => {
		const next = selected.includes(kind)
			? selected.filter((k) => k !== kind)
			: [...selected, kind];
		onChange(
			next.length === ALL_SECTION_KINDS.length
				? { mode: 'all' }
				: { mode: 'specific', kinds: next }
		);
	};
	return (
		<div className="pu-wc-dlb-src">
			<div className="pu-wc-dlb-seg" role="group" aria-label={__('Section selection', 'product-upload')}>
				<button type="button" className={mode === 'all' ? 'is-on' : ''} onClick={() => onChange({ mode: 'all' })}>
					{__('All', 'product-upload')}
				</button>
				<button type="button" className={mode === 'specific' ? 'is-on' : ''} onClick={() => onChange({ mode: 'specific', kinds: selected })}>
					{__('Specific', 'product-upload')}
				</button>
			</div>
			<p className="pu-wc-dlb-hint">
				{mode === 'all'
					? __('Every supplier section we support, including new ones added later.', 'product-upload')
					: __('Pick exactly which supplier sections appear.', 'product-upload')}
			</p>
			{mode === 'specific' && (
				<div className="pu-wc-dlb-secgrid">
					{SECTION_KINDS.map((s) => {
						const on = selected.includes(s.kind);
						return (
							<button
								type="button"
								key={s.kind}
								className={`pu-wc-dlb-tile${on ? ' is-on' : ''}`}
								aria-pressed={on}
								onClick={() => toggle(s.kind)}
							>
								{on ? '✓ ' : ''}{s.label}
							</button>
						);
					})}
				</div>
			)}
			<p className="pu-wc-dlb-hint">
				{__('Included when the supplier page has them as text. Pages that show details only inside images skip these.', 'product-upload')}
			</p>
		</div>
	);
};

const BlockSettings = ({ block, onChange }) => {
	const keep = useContext(KeepContext);
	if (keep && (block.type === 'ai_overview' || block.type === 'faq')) {
		return (
			<p className="pu-wc-dlb-hint">
				{block.type === 'faq'
					? __('Available with Rewrite', 'product-upload')
					: __("The supplier's description, exactly as written", 'product-upload')}
			</p>
		);
	}
	switch (block.type) {
		case 'description_images':
			return (
				<div className="pu-wc-dlb-row2">
					<SelectControl
						label={__('How many', 'product-upload')}
						value={String(block.count ?? 0)}
						options={[
							{ label: __('All images', 'product-upload'), value: '0' },
							{ label: __('Up to 4', 'product-upload'), value: '4' },
							{ label: __('Up to 8', 'product-upload'), value: '8' },
							{ label: __('Up to 12', 'product-upload'), value: '12' },
						]}
						onChange={(v) => onChange({ count: parseInt(v, 10) })}
					/>
					<div className="pu-wc-dlb-full">
						<CollapsibleField
							value={block.heading}
							label={__('Heading', 'product-upload')}
							addLabel={__('Add heading', 'product-upload')}
							placeholder={__('Product details', 'product-upload')}
							onChange={(v) => onChange({ heading: v })}
						/>
					</div>
				</div>
			);
		case 'ai_overview':
			return (
				<div className="pu-wc-dlb-row2">
					<SelectControl
						label={__('Length', 'product-upload')}
						value={String(block.paragraphs || 2)}
						options={[
							{ label: __('Short (1 paragraph)', 'product-upload'), value: '1' },
							{ label: __('Medium (2 paragraphs)', 'product-upload'), value: '2' },
							{ label: __('Long (3 paragraphs)', 'product-upload'), value: '3' },
						]}
						onChange={(v) => onChange({ paragraphs: parseInt(v, 10) })}
					/>
					<SelectControl
						label={__('Tone', 'product-upload')}
						value={block.tone || 'expert'}
						options={TONE_OPTIONS}
						onChange={(v) => onChange({ tone: v })}
					/>
					<div className="pu-wc-dlb-full">
						<CollapsibleField
							value={block.heading}
							label={__('Heading', 'product-upload')}
							addLabel={__('Add heading', 'product-upload')}
							placeholder={__('About this product', 'product-upload')}
							onChange={(v) => onChange({ heading: v })}
						/>
					</div>
				</div>
			);
		case 'specs':
			return (
				<div className="pu-wc-dlb-row2">
					<SelectControl
						label={__('Format', 'product-upload')}
						value={block.format || 'bullets_bold'}
						options={[
							{ label: __('Bullets with bold labels', 'product-upload'), value: 'bullets_bold' },
							{ label: __('Plain list', 'product-upload'), value: 'list' },
							{ label: __('Table', 'product-upload'), value: 'table' },
						]}
						onChange={(v) => onChange({ format: v })}
						help={block.format === 'table' ? __('Tables lose some styling on WooCommerce; bullets render most reliably.', 'product-upload') : undefined}
					/>
					<SelectControl
						label={__('Detail', 'product-upload')}
						value={block.mode || 'extensive'}
						options={[
							{ label: __('Brief (key specs)', 'product-upload'), value: 'brief' },
							{ label: __('Extensive (all specs)', 'product-upload'), value: 'extensive' },
						]}
						onChange={(v) => onChange({ mode: v })}
					/>
					<div className="pu-wc-dlb-full">
						<CollapsibleField
							value={block.exampleSpecs}
							label={__('Spec fields to always include', 'product-upload')}
							addLabel={__('Choose spec fields', 'product-upload')}
							placeholder={__('Brand, Capacity, Material, Country of Origin', 'product-upload')}
							multiline
							onChange={(v) => onChange({ exampleSpecs: v })}
						/>
					</div>
					<div className="pu-wc-dlb-full">
						<CollapsibleField
							value={block.heading}
							label={__('Heading', 'product-upload')}
							addLabel={__('Add heading', 'product-upload')}
							placeholder={__('Specifications', 'product-upload')}
							onChange={(v) => onChange({ heading: v })}
						/>
					</div>
				</div>
			);
		case 'faq':
			return (
				<div className="pu-wc-dlb-row2">
					<SelectControl
						label={__('Questions', 'product-upload')}
						value={String(block.count || 4)}
						options={['3', '4', '5', '6'].map((n) => ({ label: n, value: n }))}
						onChange={(v) => onChange({ count: parseInt(v, 10) })}
					/>
					<SelectControl
						label={__('Display', 'product-upload')}
						value={block.display || 'expandable'}
						options={[
							{ label: __('Expandable', 'product-upload'), value: 'expandable' },
							{ label: __('Plain', 'product-upload'), value: 'plain' },
						]}
						onChange={(v) => onChange({ display: v })}
					/>
					<div className="pu-wc-dlb-full">
						<CollapsibleField
							value={block.heading}
							label={__('Heading', 'product-upload')}
							addLabel={__('Add heading', 'product-upload')}
							placeholder={__('Frequently asked questions', 'product-upload')}
							onChange={(v) => onChange({ heading: v })}
						/>
					</div>
				</div>
			);
		case 'custom_text':
			return (
				<TextareaControl
					label={__('Your text', 'product-upload')}
					value={block.html ?? ''}
					placeholder={__('Free delivery on all orders over $50.', 'product-upload')}
					onChange={(html) => onChange({ html })}
					rows={3}
				/>
			);
		case 'source_sections':
			return <SourceSectionsControl block={block} onChange={onChange} />;
		default:
			return (
				<p className="pu-wc-dlb-hint">{__('No settings for this block.', 'product-upload')}</p>
			);
	}
};

// Block row
const BlockRow = ({ block, index, total, open, onToggle, onMove, onRemove, onChange }) => {
	const keep = useContext(KeepContext);
	const keepOverview = keep && block.type === 'ai_overview';
	const keepFaq = keep && block.type === 'faq';
	const src = keepOverview ? BLOCK_SOURCE.source_sections : BLOCK_SOURCE[block.type];
	const summary = keepOverview
		? __("The supplier's own text, word for word", 'product-upload')
		: keepFaq
			? __('Available with Rewrite', 'product-upload')
			: blockSummary(block);
	return (
		<div
			className={`pu-wc-dlb-blk${open ? ' is-open' : ''}`}
			style={keepFaq ? { opacity: 0.55 } : undefined}
		>
			<div className="pu-wc-dlb-blkhead">
				<button type="button" className="pu-wc-dlb-blktoggle" onClick={onToggle} aria-expanded={open}>
					<span className="pu-wc-dlb-blkname">{keep ? keepLabel(block.type) : BLOCK_LABEL[block.type]}</span>
					{src && (
						<span className="pu-wc-dlb-blksrc" style={{ '--c': src.color }}>{src.label}</span>
					)}
					{!open && <span className="pu-wc-dlb-blksum">{summary}</span>}
					<span className="pu-wc-dlb-pm" aria-hidden="true">{open ? '−' : '+'}</span>
				</button>
				<div className="pu-wc-dlb-blkactions">
					<Button
						icon={chevronUp}
						label={__('Move up', 'product-upload')}
						disabled={index === 0}
						onClick={() => onMove(index, index - 1)}
						size="small"
					/>
					<Button
						icon={chevronDown}
						label={__('Move down', 'product-upload')}
						disabled={index === total - 1}
						onClick={() => onMove(index, index + 1)}
						size="small"
					/>
					<Button
						icon={trash}
						label={__('Remove', 'product-upload')}
						isDestructive
						onClick={onRemove}
						size="small"
					/>
				</div>
			</div>
			{open && (
				<div className="pu-wc-dlb-blkbody">
					<BlockSettings block={block} onChange={onChange} />
				</div>
			)}
		</div>
	);
};

const BUILDER_CSS = `
.pu-wc-dlb-modal .components-modal__content { padding-top: 0; }
.pu-wc-dlb-grid { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 24px; align-items: start; }
@media (max-width: 782px) { .pu-wc-dlb-grid { grid-template-columns: 1fr; } }
.pu-wc-dlb-col-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #757575; margin: 0 0 10px; }
.pu-wc-dlb-list { display: flex; flex-direction: column; gap: 8px; }
.pu-wc-dlb-blk { border: 1px solid #e0e0e0; border-radius: 8px; background: #fff; }
.pu-wc-dlb-blk.is-open { border-color: #3858e9; box-shadow: 0 0 0 1px #3858e9; }
.pu-wc-dlb-blkhead { display: flex; align-items: center; gap: 4px; padding: 4px 6px 4px 4px; }
.pu-wc-dlb-blktoggle { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; background: none; border: 0;
	padding: 8px; margin: 0; border-radius: 6px; cursor: pointer; text-align: left; font: inherit; }
.pu-wc-dlb-blktoggle:hover { background: #f0f0f0; }
.pu-wc-dlb-blkname { flex: none; font-weight: 600; font-size: 13px; color: #1e1e1e; }
.pu-wc-dlb-blksrc { flex: none; font-size: 10px; font-weight: 600; line-height: 1; padding: 3px 6px; border-radius: 999px;
	white-space: nowrap; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); }
.pu-wc-dlb-blksum { flex: 1; min-width: 0; font-size: 12px; color: #757575; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pu-wc-dlb-pm { flex: none; margin-left: auto; width: 22px; height: 22px; display: flex; align-items: center;
	justify-content: center; font-size: 17px; font-weight: 600; line-height: 1; color: #3858e9; border-radius: 6px;
	background: rgba(56,88,233,.09); }
.pu-wc-dlb-blktoggle:hover .pu-wc-dlb-pm { background: rgba(56,88,233,.16); }
.pu-wc-dlb-blkactions { flex: none; display: flex; align-items: center; }
.pu-wc-dlb-blkbody { padding: 4px 14px 16px; }
.pu-wc-dlb-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.pu-wc-dlb-full { grid-column: 1 / -1; }
.pu-wc-dlb-add { margin-top: 10px; }
.pu-wc-dlb-add .components-dropdown { display: block; width: 100%; }
.pu-wc-dlb-addbtn.components-button {
	display: flex; width: 100%; height: auto; justify-content: center; gap: 6px;
	padding: 12px; border: 1px dashed #a7aaad; border-radius: 8px;
	color: #3858e9; font-weight: 600; background: transparent; }
.pu-wc-dlb-addbtn.components-button:hover:not(:disabled) {
	border-color: #3858e9; background: rgba(56,88,233,.05); color: #3858e9; }
.pu-wc-dlb-addbtn.components-button svg { width: 20px; height: 20px; flex: none; fill: currentColor; }
.pu-wc-dlb-addbtn--sm.components-button { padding: 9px; font-weight: 500; }
.pu-wc-dlb-addbtn--sm.components-button svg { width: 18px; height: 18px; }
.pu-wc-dlb-hint { font-size: 12px; color: #757575; margin: 8px 0 0; }
.pu-wc-dlb-seg { display: inline-flex; gap: 2px; padding: 3px; border-radius: 8px; background: #f0f0f0; }
.pu-wc-dlb-seg button { border: 0; background: none; padding: 5px 14px; border-radius: 6px; cursor: pointer;
	font-size: 12.5px; font-weight: 500; color: #555; }
.pu-wc-dlb-seg button.is-on { background: #fff; color: #1e1e1e; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.15); }
.pu-wc-dlb-secgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 6px; margin-top: 10px; }
.pu-wc-dlb-tile { padding: 10px 8px; box-sizing: border-box; text-align: center; font-size: 12px; cursor: pointer;
	border: 1px solid #ddd; border-radius: 6px; background: #fff; color: #555; }
.pu-wc-dlb-tile.is-on { border-color: #3858e9; background: #f0f3ff; color: #1e1e1e; font-weight: 600; }
.pu-wc-dlb-previewbox { border: 1px solid #e0e0e0; border-radius: 8px; padding: 18px; background: #fff;
	position: sticky; top: 0; max-height: 70vh; overflow: auto; }
`;

// Builder modal
const LayoutBuilderModal = ({ value, onChange, onClose }) => {
	const keep = useContext(KeepContext);
	const [blocks, setBlocks] = useState(() =>
		withBlockIds(Array.isArray(value) && value.length ? value : DEFAULT_LAYOUT)
	);
	const [openId, setOpenId] = useState(null);

	const commit = (next) => {
		setBlocks(next);
		onChange(serializeLayout(next));
	};
	const update = (index, patch) =>
		commit(blocks.map((b, i) => (i === index ? { ...b, ...patch } : b)));
	const move = (from, to) => {
		if (to < 0 || to >= blocks.length) return;
		const next = blocks.slice();
		const [moved] = next.splice(from, 1);
		next.splice(to, 0, moved);
		commit(next);
	};
	const remove = (index) => commit(blocks.filter((_, i) => i !== index));
	const add = (type) => {
		const block = withBlockIds([NEW_BLOCK[type]()])[0];
		commit([...blocks, block]);
		setOpenId(block._id);
	};

	return (
		<Modal
			title={__('Description layout', 'product-upload')}
			onRequestClose={onClose}
			className="pu-wc-dlb-modal"
			isFullScreen
		>
			<style>{BUILDER_CSS}</style>
			<div className="pu-wc-dlb-grid">
				<div>
					<p className="pu-wc-dlb-col-title">{__('Blocks', 'product-upload')}</p>
					<div className="pu-wc-dlb-list">
						{blocks.map((block, index) => (
							<BlockRow
								key={block._id}
								block={block}
								index={index}
								total={blocks.length}
								open={openId === block._id}
								onToggle={() => setOpenId(openId === block._id ? null : block._id)}
								onMove={move}
								onRemove={() => remove(index)}
								onChange={(patch) => update(index, patch)}
							/>
						))}
					</div>
					<div className="pu-wc-dlb-add">
						<DropdownMenu
							icon={plus}
							text={__('Add block', 'product-upload')}
							label={__('Add a block', 'product-upload')}
							toggleProps={{ className: 'pu-wc-dlb-addbtn' }}
							controls={BLOCK_TYPES.filter((type) => !(keep && type === 'faq')).map(
								(type) => ({
									title: keep ? keepLabel(type) : BLOCK_LABEL[type],
									// one of each; custom text repeats
									isDisabled: type !== 'custom_text' && blocks.some((b) => b.type === type),
									onClick: () => add(type),
								})
							)}
						/>
					</div>
				</div>
				<div>
					<p className="pu-wc-dlb-col-title">{__('Preview', 'product-upload')}</p>
					<div className="pu-wc-dlb-previewbox">
						<DescriptionPreview blocks={blocks} />
					</div>
				</div>
			</div>
		</Modal>
	);
};

// settings-modal field
const TRIGGER_CSS = `
.pu-wc-dlb-trigger { display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
	border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 14px; background: #fff; }
.pu-wc-dlb-chips { display: flex; flex-wrap: wrap; gap: 6px; flex: 1; min-width: 0; }
.pu-wc-dlb-chip { font-size: 12px; font-weight: 500; padding: 3px 9px; border-radius: 999px; background: #f0f0f0; color: #444; }
`;

export default function DescriptionLayoutField({ value, onChange, keep = false }) {
	const [open, setOpen] = useState(false);
	const chips = keep
		? (Array.isArray(value) && value.length ? value : DEFAULT_LAYOUT)
				.filter((b) => b && b.enabled !== false && b.type !== 'faq')
				.map((b) => keepLabel(b.type))
				.filter(Boolean)
		: describeLayout(value);
	return (
		<KeepContext.Provider value={keep}>
		<div className="pu-wc-dlb-field">
			<style>{TRIGGER_CSS}</style>
			<div className="pu-wc-dlb-trigger">
				<div className="pu-wc-dlb-chips">
					{chips.map((label, i) => (
						<span className="pu-wc-dlb-chip" key={`${label}-${i}`}>{label}</span>
					))}
				</div>
				<Button variant="secondary" onClick={() => setOpen(true)}>
					{__('Edit layout', 'product-upload')}
				</Button>
			</div>
			{open && (
				<LayoutBuilderModal
					value={value}
					onChange={onChange}
					onClose={() => setOpen(false)}
				/>
			)}
		</div>
		</KeepContext.Provider>
	);
}
