/**
 * Small supplier favicon for import-group headers. Hides itself on load error
 * so a missing icon leaves no broken-image gap.
 *
 * @package ProductUploadForWoocommerce
 */
const Favicon = ({ host, size = 18 }) => {
	if (!host) {
		return null;
	}
	return (
		<img
			className="pu-wc-favicon"
			src={`https://icons.duckduckgo.com/ip2/${host}.ico`}
			alt=""
			width={size}
			height={size}
			loading="lazy"
			onError={(e) => { e.currentTarget.style.display = 'none'; }}
		/>
	);
};

export default Favicon;
