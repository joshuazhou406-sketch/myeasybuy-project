// marketplaces the catalog browser lists

export const clientSearchSuppliers = [
  {
    id: "aliexpress",
    displayName: "AliExpress",
    hostMatcher: (h) => h.includes("aliexpress"),
    homepageUrl: "https://www.aliexpress.com",
    iconBg: "#FF4747",
    iconFg: "#FFFFFF",
    iconLabel: "Ae",
  },
  {
    id: "alibaba",
    displayName: "Alibaba",
    hostMatcher: (h) => h.includes("alibaba.com") && !h.includes("aliexpress"),
    homepageUrl: "https://www.alibaba.com",
    iconBg: "#FF6A00",
    iconFg: "#FFFFFF",
    iconLabel: "Al",
  },
  {
    id: "amazon",
    displayName: "Amazon",
    hostMatcher: (h) => /(^|\.)amazon\./.test(h),
    homepageUrl: "https://www.amazon.com",
    iconBg: "#232F3E",
    iconFg: "#FF9900",
    iconLabel: "Am",
  },
  {
    id: "ebay",
    displayName: "eBay",
    hostMatcher: (h) => h.includes("ebay."),
    homepageUrl: "https://www.ebay.com",
    iconBg: "#E53238",
    iconFg: "#FFFFFF",
    iconLabel: "eB",
  },
  {
    id: "shein",
    displayName: "Shein",
    hostMatcher: (h) => h.includes("shein.com"),
    homepageUrl: "https://www.shein.com",
    iconBg: "#000000",
    iconFg: "#FFFFFF",
    iconLabel: "Sh",
  },
  {
    id: "1688",
    displayName: "1688",
    hostMatcher: (h) => h.includes("1688.com"),
    homepageUrl: "https://www.1688.com",
    iconBg: "#FF7300",
    iconFg: "#FFFFFF",
    iconLabel: "1688",
  },
  {
    id: "etsy",
    displayName: "Etsy",
    hostMatcher: (h) => /(^|\.)etsy\.com$/.test(h),
    homepageUrl: "https://www.etsy.com",
    iconBg: "#F1641E",
    iconFg: "#FFFFFF",
    iconLabel: "Et",
  },
  {
    id: "walmart",
    displayName: "Walmart",
    hostMatcher: (h) => /(^|\.)walmart\.com$/.test(h),
    homepageUrl: "https://www.walmart.com",
    iconBg: "#0071DC",
    iconFg: "#FFFFFF",
    iconLabel: "Wm",
  },
  {
    id: "cjdropshipping",
    displayName: "CJ Dropshipping",
    hostMatcher: (h) => /(^|\.)cjdropshipping\.com$/.test(h),
    homepageUrl: "https://cjdropshipping.com",
    iconBg: "#FF7700",
    iconFg: "#FFFFFF",
    iconLabel: "CJ",
  },
  {
    id: "taobao",
    displayName: "Taobao",
    hostMatcher: (h) => h.includes("taobao.com") || h.includes("tmall.com"),
    homepageUrl: "https://www.taobao.com",
    iconBg: "#FF4400",
    iconFg: "#FFFFFF",
    iconLabel: "Tb",
  },
];

// served by productupload.co, not bundled
export const SUPPLIER_LOGO_SRC = {
  aliexpress: "https://productupload.co/suppliers/aliexpress.svg",
  alibaba: "https://productupload.co/suppliers/alibaba.svg",
  amazon: "https://productupload.co/suppliers/amazon.svg",
  ebay: "https://productupload.co/suppliers/ebay.svg",
  shein: "https://productupload.co/suppliers/shein.png",
  1688: "https://productupload.co/suppliers/1688-tile.svg",
  taobao: "https://productupload.co/suppliers/taobao.svg",
  etsy: "https://productupload.co/suppliers/etsy.png",
  walmart: "https://productupload.co/suppliers/walmart.svg",
  cjdropshipping: "https://productupload.co/suppliers/cjdropshipping-tile.png",
};

// logo is its own tile: no white invert
export const FULL_TILE_LOGOS = new Set(["1688", "cjdropshipping"]);

export const getSupplierIconUrl = (homepageUrl) => {
  try {
    const host = new URL(homepageUrl).host;
    return `https://www.google.com/s2/favicons?domain=${host}&sz=128`;
  } catch {
    return null;
  }
};

const parseHost = (raw) => {
  if (!raw) return null;
  try {
    const full = raw.startsWith("http") ? raw : `https://${raw}`;
    return new URL(full).host.toLowerCase();
  } catch {
    return null;
  }
};

export function getClientSearchSupplier(urlOrHost) {
  const host = parseHost(urlOrHost);
  if (!host) return undefined;
  return clientSearchSuppliers.find((s) => s.hostMatcher(host));
}

// CORP-less CDNs go through our proxy
const IMAGE_PROXY_URL = "https://productupload.co/api/external/image-proxy";
const IMAGE_PROXY_HOST_SUFFIXES = [
  ".alicdn.com",
  ".aliexpress-media.com",
  ".ltwebstatic.com",
];
const IMAGE_PROXY_HOST_EXACT = new Set([
  "m.media-amazon.com",
  "images-na.ssl-images-amazon.com",
  "i.ebayimg.com",
]);
export function proxyImage(url) {
  if (!url || typeof url !== "string") return url ?? "";
  try {
    const u = new URL(url);
    if (u.protocol !== "https:") return url;
    const host = u.hostname;
    const needsProxy =
      IMAGE_PROXY_HOST_EXACT.has(host) ||
      IMAGE_PROXY_HOST_SUFFIXES.some((s) => host.endsWith(s));
    if (!needsProxy) return url;
    return `${IMAGE_PROXY_URL}?url=${encodeURIComponent(url)}`;
  } catch {
    return url;
  }
}

// ask the supplier's CDN for a card-sized image
export function thumbUrl(url, width) {
  if (!url || typeof url !== "string") return url;
  try {
    const u = new URL(url);
    const host = u.hostname;
    if (host === "cdn.shopify.com" || u.pathname.includes("/cdn/shop/")) {
      u.searchParams.set("width", String(width));
      return u.toString();
    }
    if (host.endsWith(".bigcommerce.com") && /\/images\/stencil\/[^/]+\//.test(u.pathname)) {
      u.pathname = u.pathname.replace(/\/images\/stencil\/[^/]+\//, `/images/stencil/${width}x${width}/`);
      return u.toString();
    }
    if (host === "images.squarespace-cdn.com") {
      u.searchParams.set("format", `${width}w`);
      return u.toString();
    }
    if (host === "static.wixstatic.com" && /^\/media\/[^/]+$/.test(u.pathname)) {
      const file = u.pathname.split("/").pop();
      u.pathname = `${u.pathname}/v1/fill/w_${width},h_${width},al_c,q_80/${file}`;
      return u.toString();
    }
    return url;
  } catch {
    return url;
  }
}
