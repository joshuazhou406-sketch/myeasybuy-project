/**
 * Client-side bilingual keyword retrieval (bigrams + dictionary + BM25-ish).
 * Answers always point at verbatim policy clauses — nothing is invented.
 */
(function (root) {
	'use strict';

	var STOP = {
		the: 1, a: 1, an: 1, of: 1, and: 1, or: 1, to: 1, for: 1, in: 1, on: 1, at: 1,
		is: 1, are: 1, be: 1, was: 1, were: 1, it: 1, its: 1, we: 1, you: 1, your: 1,
		our: 1, i: 1, me: 1, my: 1, do: 1, does: 1, did: 1, can: 1, how: 1, what: 1,
		when: 1, where: 1, who: 1, which: 1, with: 1, from: 1, this: 1, that: 1,
		if: 1, not: 1, no: 1, please: 1, about: 1, into: 1, than: 1, then: 1,
		的: 1, 了: 1, 吗: 1, 呢: 1, 吧: 1, 啊: 1, 是: 1, 和: 1, 与: 1, 在: 1, 有: 1,
		我: 1, 你: 1, 您: 1, 们: 1, 这: 1, 那: 1, 什么: 1, 怎么: 1, 如何: 1, 请问: 1
	};

	var SYN = {
		returns: 'return', returning: 'return', refunds: 'refund', refunded: 'refund',
		exchanges: 'exchange', cancelled: 'cancel', cancellation: 'cancel',
		fees: 'fee', postage: 'shipping', freight: 'shipping', volumetric: 'shipping',
		batteries: 'battery', prohibited: 'prohibited', forbidden: 'prohibited',
		banned: 'prohibited', restricted: 'restricted', lost: 'loss', damaged: 'damage',
		compensate: 'compensation', compensated: 'compensation', insurance: 'protection',
		consolidate: 'consolidation', combining: 'consolidation', combine: 'consolidation',
		duties: 'duty', taxes: 'tax', customs: 'customs',
		'退货': 'return', '退款': 'refund', '退换': 'return', '退换货': 'return',
		'取消': 'cancel', '服务费': 'fee', '手续费': 'fee', '费用': 'fee',
		'运费': 'shipping', '国际运费': 'shipping', '计费重': 'shipping', '体积重': 'shipping',
		'禁运': 'prohibited', '违禁': 'prohibited', '禁止': 'prohibited', '限运': 'restricted',
		'电池': 'battery', '仓储': 'storage', '仓储费': 'storage', '存放': 'storage',
		'赔偿': 'compensation', '理赔': 'compensation', '丢失': 'loss', '遗失': 'loss',
		'损坏': 'damage', '破损': 'damage', '保障': 'protection', '包裹保障': 'protection',
		'合箱': 'consolidation', '拼箱': 'consolidation', '打包': 'consolidation',
		'关税': 'duty', '税费': 'tax', '清关': 'customs', '海关': 'customs',
		'查扣': 'customs', '期限': 'window', '隐私': 'privacy'
	};

	function isCjk(ch) {
		var c = ch.charCodeAt(0);
		return c >= 0x3400 && c <= 0x9fff;
	}

	function tokenize(text) {
		var s = String(text || '').toLowerCase();
		var tokens = [];
		var seen = {};
		function add(tok) {
			if (!tok || STOP[tok]) return;
			// Drop 1-letter Latin noise (e.g. the "s" in "what's"). Keep digits.
			if (/^[a-z]$/.test(tok)) return;
			var canon = SYN[tok] || tok;
			if (!seen[canon]) {
				tokens.push(canon);
				seen[canon] = 1;
			}
			if (canon !== tok && !seen[tok]) {
				tokens.push(tok);
				seen[tok] = 1;
			}
		}
		var wordRe = /[a-z][a-z0-9%]*|[0-9]+(?:\.[0-9]+)?/g;
		var m;
		while ((m = wordRe.exec(s))) add(m[0]);
		var cjk = '';
		for (var i = 0; i < s.length; i++) {
			if (isCjk(s[i])) cjk += s[i];
			else {
				flushCjk(cjk, add);
				cjk = '';
			}
		}
		flushCjk(cjk, add);
		return tokens;
	}

	function flushCjk(run, add) {
		if (!run) return;
		// Character bigrams/trigrams only (plus a lone character if that is the whole run).
		// Unigrams on longer runs are too noisy for Chinese queries with no word spaces.
		if (run.length === 1) {
			add(run);
			return;
		}
		for (var j = 0; j < run.length - 1; j++) add(run.slice(j, j + 2));
		if (run.length >= 3) {
			for (var k = 0; k < run.length - 2; k++) add(run.slice(k, k + 3));
		}
	}

	function clauseTokens(clause) {
		if (clause._tokens) return clause._tokens;
		var bag = [
			clause.title_en, clause.title_zh, clause.text_en, clause.text_zh,
			clause.slug, clause.num
		].join('\n');
		clause._tokens = tokenize(bag);
		var tf = {};
		clause._tokens.forEach(function (t) {
			tf[t] = (tf[t] || 0) + 1;
		});
		clause._tf = tf;
		clause._len = clause._tokens.length || 1;
		return clause._tokens;
	}

	function buildIndex(kb) {
		var clauses = (kb && kb.clauses) || [];
		var df = {};
		var total = clauses.length || 1;
		var avg = 0;
		clauses.forEach(function (c) {
			var toks = clauseTokens(c);
			avg += c._len;
			var uniq = {};
			toks.forEach(function (t) {
				if (!uniq[t]) {
					uniq[t] = 1;
					df[t] = (df[t] || 0) + 1;
				}
			});
		});
		avg = avg / total;
		var faqs = (kb && kb.faqs) || [];
		return { clauses: clauses, df: df, n: total, avg: avg || 1, faqs: faqs };
	}

	function idf(term, idx) {
		var d = idx.df[term] || 0;
		return Math.log((idx.n - d + 0.5) / (d + 0.5) + 1);
	}

	function bm25(queryToks, clause, idx) {
		var k1 = 1.4;
		var b = 0.75;
		var score = 0;
		var tf = clause._tf || {};
		queryToks.forEach(function (t) {
			var f = tf[t] || 0;
			if (!f) return;
			var id = idf(t, idx);
			var norm = f * (k1 + 1) / (f + k1 * (1 - b + b * (clause._len / idx.avg)));
			score += id * norm;
		});
		return score;
	}

	function faqBoost(queryToks, qraw, idx) {
		var boosts = {};
		var faqByClause = {};
		var qset = {};
		queryToks.forEach(function (t) { qset[t] = 1; });
		var qlow = String(qraw || '').toLowerCase();
		idx.faqs.forEach(function (faq) {
			var hit = 0;
			(faq.keywords || []).forEach(function (kw) {
				var k = String(kw).toLowerCase();
				if (k && qlow.indexOf(k) !== -1) {
					hit += k.length >= 4 ? 2 : 1;
					return;
				}
				var ktoks = tokenize(kw);
				if (!ktoks.length) return;
				// Multi-word keywords must match all tokens, so "shipping cost"
				// does not fire on a lone "shipping".
				var all = ktoks.every(function (t) { return qset[t]; });
				if (all) hit += ktoks.length > 1 ? 2 : 1;
			});
			if (hit <= 0) return;
			(faq.clause_ids || []).forEach(function (id, i) {
				boosts[id] = (boosts[id] || 0) + hit * (i === 0 ? 4.5 : 2.5);
				if (!faqByClause[id] || hit > faqByClause[id].hit) {
					faqByClause[id] = { faq: faq, hit: hit };
				}
			});
		});
		return { boosts: boosts, faqByClause: faqByClause };
	}

	function formatRowLine(row) {
		return (row || []).filter(function (c) { return String(c || '').trim(); }).join(' — ');
	}

	function compactStr(s) {
		return String(s || '').toLowerCase().replace(/\s+/g, '');
	}

	function scoreTableRow(query, qtoks, rowEn, rowZh) {
		var lineEn = formatRowLine(rowEn);
		var lineZh = formatRowLine(rowZh);
		var hay = (lineEn + ' ' + lineZh).toLowerCase();
		var compact = compactStr(lineEn + lineZh);
		var qlow = String(query || '').toLowerCase();
		var s = 0;
		qtoks.forEach(function (t) {
			if (!t || t.length < 2) return;
			if (hay.indexOf(t) !== -1 || compact.indexOf(compactStr(t)) !== -1) {
				s += t.length >= 3 ? 2 : 1;
			}
		});
		// Distinctive number/window overlap (5 days, 90 days, 6%, etc.).
		if (/(?:^|\s)5(?:\s|$)|5\s*day|five day|5\s*天|五天|五日|几天/.test(qlow) && /5\s*day|5\s*天|五天|五日/.test(hay + compact)) s += 8;
		if (/90\s*day|90\s*天|九十/.test(qlow) && /90\s*day|90\s*天/.test(hay + compact)) s += 8;
		if (/30\s*day|30\s*天|三十/.test(qlow) && /30\s*day|30\s*天/.test(hay + compact)) s += 6;
		if (/180/.test(qlow) && /180/.test(hay)) s += 8;
		if (/warehouse|入库|仓库/.test(qlow) && /warehouse|入库/.test(hay)) s += 4;
		if (/free storage|免费仓储/.test(qlow) && /free storage|免费仓储/.test(hay)) s += 6;
		return s;
	}

	function bestTableRows(query, qtoks, clause, limit) {
		limit = limit || 1;
		var tablesEn = clause.tables_en || [];
		var tablesZh = clause.tables_zh || [];
		var scored = [];
		tablesEn.forEach(function (t, ti) {
			(t.rows || []).forEach(function (row, ri) {
				var zhTable = tablesZh[ti] || {};
				var rowZh = (zhTable.rows && zhTable.rows[ri]) || [];
				var sc = scoreTableRow(query, qtoks, row, rowZh);
				if (sc <= 0) return;
				scored.push({
					table: ti,
					row: ri,
					score: sc,
					line_en: formatRowLine(row),
					line_zh: formatRowLine(rowZh)
				});
			});
		});
		// If only ZH tables exist
		if (!tablesEn.length && tablesZh.length) {
			tablesZh.forEach(function (t, ti) {
				(t.rows || []).forEach(function (row, ri) {
					var sc = scoreTableRow(query, qtoks, [], row);
					if (sc <= 0) return;
					scored.push({
						table: ti,
						row: ri,
						score: sc,
						line_en: '',
						line_zh: formatRowLine(row)
					});
				});
			});
		}
		scored.sort(function (a, b) { return b.score - a.score; });
		if (!scored.length) return [];
		// Keep rows that are clearly relevant; require a modest score, or a clear winner.
		var topScore = scored[0].score;
		var min = Math.max(4, topScore * 0.6);
		return scored.filter(function (r) { return r.score >= min; }).slice(0, limit);
	}

	function search(query, kb, opts) {
		opts = opts || {};
		var limit = opts.limit || 3;
		var minScore = opts.minScore == null ? 4.5 : opts.minScore;
		var idx = kb._index || (kb._index = buildIndex(kb));
		var qtoks = tokenize(query);
		if (!qtoks.length) return [];
		var boosted = faqBoost(qtoks, query, idx);
		var boosts = boosted.boosts;
		var faqByClause = boosted.faqByClause;
		var scored = idx.clauses.map(function (c) {
			var s = bm25(qtoks, c, idx) + (boosts[c.id] || 0);
			// Title hits matter more.
			var titleToks = tokenize((c.title_en || '') + ' ' + (c.title_zh || ''));
			titleToks.forEach(function (t) {
				if (qtoks.indexOf(t) !== -1) s += 1.2;
			});
			// Weak single-token overlaps (e.g. "weather" in a delays clause) are not answers.
			var cov = 0;
			qtoks.forEach(function (t) {
				if (c._tf && c._tf[t]) cov += 1;
			});
			if (!boosts[c.id] && cov < 2 && s < 14) s = 0;
			var rows = s > 0 ? bestTableRows(query, qtoks, c, 2) : [];
			if (rows[0] && rows[0].score >= 8) s += 2;
			var faqInfo = faqByClause[c.id];
			var leadEn = '';
			var leadZh = '';
			if (rows[0] && rows[0].score >= 4) {
				leadEn = rows[0].line_en || '';
				leadZh = rows[0].line_zh || '';
			} else if (faqInfo && faqInfo.faq) {
				leadEn = faqInfo.faq.lead_en || '';
				leadZh = faqInfo.faq.lead_zh || '';
				// If the FAQ lead is itself a table row, highlight / promote that row.
				if (!rows.length && (leadEn || leadZh)) {
					var te = c.tables_en || [];
					var tz = c.tables_zh || [];
					te.forEach(function (t, ti) {
						(t.rows || []).forEach(function (row, ri) {
							var lineEn = (row || []).join(' — ');
							var lineZh = ((tz[ti] && tz[ti].rows && tz[ti].rows[ri]) || []).join(' — ');
							if ((leadEn && lineEn === leadEn) || (leadZh && lineZh === leadZh)) {
								rows = [{ table: ti, row: ri, score: 1, line_en: lineEn, line_zh: lineZh }];
							}
						});
					});
				}
			}
			return {
				clause: c,
				score: s,
				faqId: faqInfo && faqInfo.faq ? faqInfo.faq.id : '',
				matchedRows: rows,
				lead_en: leadEn,
				lead_zh: leadZh
			};
		});
		scored.sort(function (a, b) { return b.score - a.score; });
		var top = scored.filter(function (r) { return r.score >= minScore; }).slice(0, limit);
		if (!top.length && scored[0] && scored[0].score >= minScore * 0.7) {
			top = scored.slice(0, 1);
		}
		return top;
	}

	root.MebOsSearch = { tokenize: tokenize, search: search, buildIndex: buildIndex };
})(typeof window !== 'undefined' ? window : globalThis);
