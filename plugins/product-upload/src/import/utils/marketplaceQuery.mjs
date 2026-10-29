// marketplace search/category URL -> search terms

const words = (s) => {
  let t = String(s ?? "").replace(/\+/g, " ");
  try {
    t = decodeURIComponent(t);
  } catch {
    // a literal "%" stays as typed
  }
  return t.replace(/[-_]+/g, " ").replace(/\s+/g, " ").trim();
};

const param = (u, ...keys) => {
  for (const k of keys) {
    const v = u.searchParams.get(k);
    if (v && v.trim()) return words(v);
  }
  return "";
};

const safe = (fn) => {
  try {
    return fn() || "";
  } catch {
    return "";
  }
};

// [host test, path -> terms]
const RULES = [
  [
    /(^|\.)aliexpress\.[a-z.]+$/,
    (u) => u.pathname.match(/\/w\/wholesale-(.+?)\.html/i)?.[1] ?? (u.pathname.includes("/store/") ? "" : param(u, "SearchText")),
  ],
  [
    /(^|\.)amazon\.[a-z.]+$/,
    (u) =>
      (u.pathname === "/s" || u.pathname.startsWith("/s/") ? param(u, "k", "keywords", "field-keywords") : "") ||
      u.pathname.match(/\/(?:gp\/bestsellers|zgbs|gp\/new-releases)\/([a-z-]+)/i)?.[1] ||
      u.pathname.match(/\/stores\/(?!page\/)([^/]+)\/page\//i)?.[1] ||
      "",
  ],
  [/(^|\.)ebay\.[a-z.]+$/, (u) => (u.pathname.startsWith("/sch/") ? param(u, "_nkw") : "")],
  [
    /(^|\.)alibaba\.com$/,
    (u) =>
      param(u, "SearchText", "searchText", "keyword", "keywords") ||
      u.pathname.match(/\/showroom\/([^/]+?)\.html/i)?.[1] ||
      u.pathname.match(/\/category\/(.+?)_\d+\.html/i)?.[1] ||
      "",
  ],
  [
    /(^|\.)etsy\.com$/,
    (u) =>
      (u.pathname.match(/^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?search\b/i) ? param(u, "q") : "") ||
      u.pathname.match(/^\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?c\/(.+?)\/?$/i)?.[1]?.split("/").join(" ") ||
      "",
  ],
  [
    /(^|\.)walmart\.com$/,
    (u) =>
      (u.pathname.startsWith("/search") ? param(u, "q", "query") : "") ||
      u.pathname.match(/^\/(?:browse|cp)\/(?:.*\/)?([a-z][a-z0-9-]*)\/[\d_]+\/?$/i)?.[1] ||
      "",
  ],
  [
    /(^|\.)shein\.[a-z.]+$/,
    (u) =>
      u.pathname.match(/\/pdsearch\/([^/]+)/i)?.[1] ||
      u.pathname.match(/\/([A-Za-z0-9-]+?)-s?c-\d+\.html/i)?.[1] ||
      "",
  ],
  [/(^|\.)1688\.com$/, (u) => (u.hostname.startsWith("s.") ? param(u, "keywords") : "")],
  [/(^|\.)(taobao|tmall)\.com$/, (u) => (u.hostname.startsWith("s.") ? param(u, "q") : "")],
  [
    /(^|\.)cjdropshipping\.com$/,
    (u) => u.pathname.match(/\/list\/wholesale-(.+?)-l-[\w-]+\.html/i)?.[1] || param(u, "keyWord", "keyword"),
  ],
];

/** Search terms a marketplace URL stands for, or "". */
export function marketplaceQuery(raw) {
  let u;
  try {
    u = new URL(raw);
  } catch {
    return "";
  }
  const host = u.hostname.toLowerCase();
  const rule = RULES.find(([re]) => re.test(host));
  const q = rule ? safe(() => words(rule[1](u))) : "";
  // a page's junk text isn't a search
  return q.length >= 2 && q.length <= 80 ? q : "";
}
