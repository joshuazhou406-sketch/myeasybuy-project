import assert from "node:assert/strict";
import { marketplaceQuery as q } from "./marketplaceQuery.mjs";

// search pages, as pasted by merchants
assert.equal(q("https://www.aliexpress.us/w/wholesale-Pet-Supplies.html?categoryTab=pet_supplies&isFromCategory=y"), "Pet Supplies");
assert.equal(q("https://nl.aliexpress.com/w/wholesale-Auto-stuur.html?spm=a2g0o.productlist.search.0"), "Auto stuur");
assert.equal(q("https://www.aliexpress.us/w/wholesale-Bags-%26-Luggage.html"), "Bags & Luggage");
assert.equal(q("https://www.amazon.com/s?k=iphone&rh=n%3A2811119011&ref=nb_sb_noss"), "iphone");
assert.equal(q("https://www.amazon.com/s?k=The+Leupold+GX-6C+Golf+Rangefinder&crid=LGK"), "The Leupold GX 6C Golf Rangefinder");
assert.equal(q("https://www.ebay.com/sch/i.html?_dkr=1&_ssn=hopecaserealm&_nkw=iphone"), "iphone");
assert.equal(q("https://www.alibaba.com/trade/search?tab=all&SearchText=climatiseur"), "climatiseur");
assert.equal(q("https://www.alibaba.com/dropshipping/products?tracelog=lpfrontheader&keyword=thigh+trainer"), "thigh trainer");
assert.equal(q("https://www.etsy.com/search?q=customize+gifts+for+men&ref=auto-1"), "customize gifts for men");
assert.equal(
  q("https://www.etsy.com/search/clothing/womens-clothing/hoodies-and-sweatshirts?q=sequin%20embroidered%20sweatshirt&order=most_relevant"),
  "sequin embroidered sweatshirt",
);
assert.equal(q("https://www.walmart.com/search?q=yoga+mat"), "yoga mat");
assert.equal(q("https://s.1688.com/selloffer/offer_search.htm?charset=utf8&keywords=%E6%9C%8D%E9%A5%B0%E9%85%8D%E4%BB%B6"), "服饰配件");
assert.equal(q("https://s.taobao.com/search?page=1&q=%E6%B5%81%E8%A1%8C%E5%A5%B3%E8%A3%85&tab=pc_taobao"), "流行女装");
assert.equal(q("https://www.shein.com/pdsearch/floral%20dress/?ici=s1"), "floral dress");
assert.equal(q("https://www.cjdropshipping.com/list/wholesale-womens-skirts-l-2608160534451626700.html"), "womens skirts");

// category pages -> their name
assert.equal(q("https://www.amazon.co.uk/gp/bestsellers/kitchen/2850828031/ref=pd_zg_hrsr_kitchen"), "kitchen");
assert.equal(q("https://www.amazon.com/gp/bestsellers/sporting-goods/13280951/ref=x"), "sporting goods");
assert.equal(q("https://www.amazon.fr/stores/TheItalianHome%C2%AE/page/C3B8AC75-D912-49CF-B0CF-8112B5D00097"), "TheItalianHome®");
assert.equal(q("https://www.alibaba.com/category/line-boring-machine_100007203.html?spm=a2700"), "line boring machine");
assert.equal(q("https://www.etsy.com/nz/c/gifts/for-him?ref=lp"), "gifts for him");
assert.equal(q("https://m.shein.com/us/Women-Clothing-c-2030.html?entranceType=sbc"), "Women Clothing");
assert.equal(q("https://www.walmart.com/browse/home/kitchen/4044_623679"), "kitchen");

// not searches
for (const url of [
  "https://www.aliexpress.com/item/1005008248103251.html",
  "https://www.aliexpress.com/store/1102061814/pages/all-items.html?SearchText=",
  "https://www.amazon.com/dp/B0DJWNR26P",
  "https://www.amazon.com/stores/page/0223241D-4643-4497-A8B0-2E7362043691",
  "https://www.amazon.com/s?i=merchant-items&me=A3MKQQ62RUVY6L",
  "https://www.etsy.com/listing/1301638116/14k-gold",
  "https://www.walmart.com/ip/Foo/123",
  "https://www.temu.com/search_result.html?search_key=parasol",
  "https://example.com/search?q=yoga",
  "not a url",
]) {
  assert.equal(q(url), "", url);
}
// literal % survives
assert.equal(q("https://www.walmart.com/search?q=100%25+cotton"), "100% cotton");

console.log("marketplaceQuery: all assertions passed");
