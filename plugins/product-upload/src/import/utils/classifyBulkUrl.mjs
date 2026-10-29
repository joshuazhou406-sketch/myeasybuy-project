import { marketplaceQuery } from "./marketplaceQuery.mjs";

// market prefix: /nl-be/collections/x
const LOCALE_SEG = /^[a-z]{2}(?:-[a-z]{2})?$/i;

// store pages; mirrors main-app seller.ts
const SELLER_PAGE = [
  (u) => /(^|\.)aliexpress\.[a-z.]+$/.test(u.hostname) && /\/store\/(?:[a-z-]+\/)?\d{5,}/i.test(u.pathname),
  (u) =>
    /(^|\.)amazon\.[a-z.]+$/.test(u.hostname) &&
    /^[A-Z0-9]{8,20}$/i.test(u.searchParams.get("me") || u.searchParams.get("seller") || ""),
  (u) => /^(?!www\.)[a-z0-9-]+\.(?:m\.)?en\.alibaba\.com$/.test(u.hostname),
  (u) => /(^|\.)etsy\.com$/.test(u.hostname) && /^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?shop\/[A-Za-z0-9]+/.test(u.pathname),
  (u) =>
    /(^|\.)ebay\.[a-z.]+$/.test(u.hostname) &&
    (/^\/(?:usr|str)\/[A-Za-z0-9._-]{2,}/.test(u.pathname) || /^[A-Za-z0-9._-]{2,64}$/.test(u.searchParams.get('_ssn') || '')),
];

// collection/category/root
export function classifyBulkUrl(raw) {
  let uri;
  try {
    uri = new URL(raw);
  } catch {
    return null;
  }
  const isMarketplaceSeller = SELLER_PAGE.some((f) => f(uri));
  // search/category page: browse on its terms
  const searchQuery = isMarketplaceSeller ? "" : marketplaceQuery(raw);
  const isEntireWebsite = !isMarketplaceSeller && (uri.pathname === "/" || !uri.pathname);
  const segs = uri.pathname.split("/").filter(Boolean);
  // >2 leaves a bare /nl path reading as a product
  while (segs.length > 2 && LOCALE_SEG.test(segs[0])) segs.shift();
  const isCollection =
    segs.length === 2 && segs[0].toLowerCase() === "collections";
  const isProductCategory =
    segs.length === 2 && segs[0].toLowerCase() === "product-category";
  return {
    isEntireWebsite,
    isCollection,
    isProductCategory,
    isMarketplaceSeller,
    isMarketplaceSearch: !!searchQuery,
    searchQuery,
    collectionId: isCollection ? segs[1] : undefined,
  };
}

export function isSupplierModalUrl(raw) {
  const c = classifyBulkUrl(raw);
  return (
    !!c && (c.isCollection || c.isEntireWebsite || c.isProductCategory || c.isMarketplaceSeller || c.isMarketplaceSearch)
  );
}
