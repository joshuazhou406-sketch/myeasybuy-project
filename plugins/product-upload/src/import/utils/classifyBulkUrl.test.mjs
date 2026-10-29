import assert from "node:assert/strict";
import { classifyBulkUrl, isSupplierModalUrl } from "./classifyBulkUrl.mjs";

const collection = (url) => classifyBulkUrl(url).isCollection;
const category = (url) => classifyBulkUrl(url).isProductCategory;

// the reported bug: market prefix broke the match
assert.equal(
  collection("https://navando.nl/nl-be/collections/vakantie-reisaccessoires"),
  true,
);
assert.equal(
  classifyBulkUrl(
    "https://navando.nl/nl-be/collections/vakantie-reisaccessoires",
  ).collectionId,
  "vakantie-reisaccessoires",
);
assert.equal(
  isSupplierModalUrl(
    "https://navando.nl/nl-be/collections/vakantie-reisaccessoires",
  ),
  true,
);

// unprefixed still works
assert.equal(collection("https://www.varenzo.nl/collections/home"), true);
assert.equal(classifyBulkUrl("https://www.varenzo.nl/collections/home").collectionId, "home");

// language-only and longer market codes
assert.equal(collection("https://x.com/nl/collections/tassen"), true);
assert.equal(collection("https://x.com/en-us/collections/bags"), true);

// woo categories get the same treatment
assert.equal(category("https://koopje.com/product-category/tech-gadgets"), true);
assert.equal(category("https://koopje.com/nl-be/product-category/tech-gadgets"), true);

// a product inside a collection is NOT a collection
assert.equal(collection("https://x.com/collections/home/products/lamp"), false);
assert.equal(
  collection("https://x.com/nl-be/collections/home/products/lamp"),
  false,
);
assert.equal(collection("https://x.com/products/lamp"), false);

// bare two-letter path stays a product
assert.equal(classifyBulkUrl("https://x.com/nl").isEntireWebsite, false);
assert.equal(collection("https://x.com/nl"), false);

// roots and junk
assert.equal(classifyBulkUrl("https://x.com/").isEntireWebsite, true);
assert.equal(classifyBulkUrl("not a url"), null);

console.log("classifyBulkUrl: all assertions passed");

// store pages open the importer on that seller
const seller = (url) => classifyBulkUrl(url).isMarketplaceSeller;
assert.equal(seller("https://www.aliexpress.com/store/1104956140"), true);
assert.equal(seller("https://www.amazon.ca/s?me=A2832FOFER8XD&marketplaceID=A2EUQ1WTGCTBG2"), true);
assert.equal(seller("https://senfuhome.m.en.alibaba.com/productgrouplist-826058623-5/Carpets.html"), true);
// a supplier's home is a seller, not a whole website
assert.equal(seller("https://zslrdz.en.alibaba.com/"), true);
assert.equal(classifyBulkUrl("https://zslrdz.en.alibaba.com/").isEntireWebsite, false);
assert.equal(isSupplierModalUrl("https://www.aliexpress.com/store/1104956140"), true);
assert.equal(seller("https://www.aliexpress.com/item/1005001234.html"), false);
assert.equal(seller("https://www.amazon.com/s?k=yoga+mat"), false);
assert.equal(seller("https://www.alibaba.com/"), false);
assert.equal(seller("https://www.etsy.com/shop/AceElegance?ref=shop_sugg"), true);
assert.equal(seller("https://www.etsy.com/uk/shop/AceElegance"), true);
assert.equal(seller("https://www.etsy.com/listing/1301638116/14k-gold"), false);


// marketplace searches open the browser pre-searched
const search = (url) => classifyBulkUrl(url);
assert.equal(search("https://www.aliexpress.us/w/wholesale-Pet-Supplies.html").searchQuery, "Pet Supplies");
assert.equal(isSupplierModalUrl("https://www.amazon.com/s?k=yoga+mat"), true);
assert.equal(isSupplierModalUrl("https://www.amazon.com/dp/B0DJWNR26P"), false);
// a seller page stays a seller, not a search
assert.equal(search("https://www.amazon.com/s?i=merchant-items&me=A3MKQQ62RUVY6L&k=mat").isMarketplaceSearch, false);

// eBay sellers: /usr/, /str/, ?_ssn=
assert.equal(classifyBulkUrl("https://www.ebay.com/usr/slickfe-70").isMarketplaceSeller, true);
assert.equal(classifyBulkUrl("https://www.ebay.co.uk/str/corporatetogs").isMarketplaceSeller, true);
assert.equal(classifyBulkUrl("https://www.ebay.com/sch/i.html?_ssn=hopecaserealm&_nkw=iphone").isMarketplaceSeller, true);
assert.equal(classifyBulkUrl("https://www.ebay.com/sch/i.html?_nkw=iphone").isMarketplaceSeller, false);
assert.equal(classifyBulkUrl("https://www.ebay.com/itm/147632365343").isMarketplaceSeller, false);
