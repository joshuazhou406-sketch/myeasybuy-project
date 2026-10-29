(function ($) {
	'use strict';

	var currentRequest = null;
	var detailRequest = null;
	var totalShown = 0;
	var currentPlatform =
		(ppwSearch && ppwSearch.platform) ||
		(ppwSearch && ppwSearch.defaultPlatform) ||
		'taobao';
	var lastKeyword = (ppwSearch && ppwSearch.keyword) || '';
	var lastSearchMode = 'keyword';
	var lastImageFile = null;

	function platformLabel(platform) {
		if (ppwSearch.labels && ppwSearch.labels[platform]) {
			return ppwSearch.labels[platform];
		}
		return platform === 'jd' ? '京东' : '淘宝';
	}

	function getResultsSection() {
		return $('#ppw-jd-results-section');
	}

	function getResultsContainer() {
		return $('#ppw-jd-results');
	}

	function getDetailModal() {
		return $('#ppw-jd-detail-modal');
	}

	function getDetailContent() {
		return $('#ppw-jd-detail-content');
	}

	function showSection() {
		getResultsSection().addClass('is-visible');
	}

	function setActivePlatform(platform) {
		currentPlatform = platform === 'jd' ? 'jd' : 'taobao';
		getResultsSection().attr('data-platform', currentPlatform);
		$('[data-ppw-platform]').each(function () {
			var $btn = $(this);
			var isActive = $btn.data('ppw-platform') === currentPlatform;
			$btn.toggleClass('is-active', isActive).attr('aria-selected', isActive ? 'true' : 'false');
		});
	}

	function setLoading(isLoading) {
		getResultsSection().toggleClass('is-loading', isLoading);

		if (isLoading) {
			getResultsContainer().html(
				'<div class="ppw-jd-loading"><span class="ppw-jd-spinner"></span>' +
					ppwSearch.loading +
					'</div>'
			);
			showSection();
		}
	}

	function scrollToResults() {
		var section = getResultsSection();
		if (section.length) {
			$('html, body').animate(
				{
					scrollTop: section.offset().top - 80
				},
				400
			);
		}
	}

	function updateUrl(keyword, platform) {
		if (!window.history || !window.history.replaceState) {
			return;
		}

		var url = new URL(window.location.href);

		if (keyword) {
			url.searchParams.set('ppw_q', keyword);
		} else {
			url.searchParams.delete('ppw_q');
		}
		url.searchParams.delete('ppw_jd');

		if (platform) {
			url.searchParams.set('ppw_platform', platform);
		}

		window.history.replaceState({}, '', url.toString());
	}

	function openDetailModal() {
		getDetailModal().addClass('is-open').attr('aria-hidden', 'false');
		$('body').addClass('ppw-jd-modal-open');
	}

	function closeDetailModal() {
		getDetailModal().removeClass('is-open').attr('aria-hidden', 'true');
		$('body').removeClass('ppw-jd-modal-open');
		getDetailContent().empty();
	}

	function showDetailLoading() {
		getDetailContent().html(
			'<div class="ppw-jd-loading"><span class="ppw-jd-spinner"></span>' +
				ppwSearch.detailLoading +
				'</div>'
		);
		openDetailModal();
	}

	function updateResultsTitle(keyword, count, platform) {
		var $title = $('[data-ppw-results-title]');
		if (!$title.length) {
			return;
		}
		platform = platform || currentPlatform;
		$title.attr('data-keyword', keyword).attr('data-platform', platform);
		var tpl = ppwSearch.titleTpl || '「%1$s」的%2$s商品（已显示 %3$d 件）';
		$title.text(
			tpl
				.replace('%1$s', keyword)
				.replace('%2$s', platformLabel(platform))
				.replace('%3$d', String(count))
		);
	}

	function loadProductDetail($btn) {
		if (window.mebProductSync) {
			window.mebProductSync.openFromCard($btn.get(0));
			return;
		}

		var numIid = $btn.data('item-id');
		var platform = $btn.data('platform') || currentPlatform;

		if (!numIid || String(numIid).indexOf('demo-') === 0) {
			alert(ppwSearch.detailError);
			return;
		}

		if (detailRequest) {
			detailRequest.abort();
		}

		showDetailLoading();

		detailRequest = $.ajax({
			url: ppwSearch.ajaxUrl,
			type: 'POST',
			data: {
				action: 'ppw_jd_product_detail',
				nonce: ppwSearch.nonce,
				num_iid: numIid,
				platform: platform,
				title: $btn.data('title') || '',
				price_rmb: $btn.data('price-rmb') || '',
				image: $btn.data('image') || '',
				url: $btn.data('jd-url') || '',
				shop: $btn.data('shop') || ''
			}
		})
			.done(function (response) {
				if (response.success && response.data && response.data.product_url) {
					window.location.href = response.data.product_url;
					return;
				}
				if (response.success && response.data && response.data.html) {
					getDetailContent().html(response.data.html);
				} else {
					var msg =
						response.data && response.data.message
							? response.data.message
							: ppwSearch.detailError;
					getDetailContent().html('<p class="ppw-jd-error">' + msg + '</p>');
				}
			})
			.fail(function (xhr) {
				var msg = ppwSearch.detailError;
				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					msg = xhr.responseJSON.data.message;
				}
				getDetailContent().html('<p class="ppw-jd-error">' + msg + '</p>');
			})
			.always(function () {
				detailRequest = null;
			});
	}

	function looksLikeProductUrl(value) {
		value = $.trim(value || '');
		if (!value) {
			return false;
		}
		if (/^https?:\/\//i.test(value)) {
			return true;
		}
		return /(?:^|\/\/|www\.)?(?:item\.|detail\.|product\.|m\.|a\.m\.|u\.|e\.)?(?:jd\.(?:com|hk)|taobao\.com|tmall\.(?:com|hk)|tb\.cn)/i.test(
			value
		);
	}

	function resolveProductLink(query) {
		query = $.trim(query);
		if (!query) {
			return;
		}

		if (detailRequest) {
			detailRequest.abort();
		}
		if (currentRequest) {
			currentRequest.abort();
			currentRequest = null;
		}

		lastKeyword = query;
		updateUrl(query, currentPlatform);

		if (window.mebProductSync) {
			window.mebProductSync.openFromLink(query, { title: query });
			return;
		}

		showSection();
		getResultsContainer().html(
			'<div class="ppw-jd-loading"><span class="ppw-jd-spinner"></span>' +
				(ppwSearch.linkLoading || ppwSearch.detailLoading) +
				'</div>'
		);
		showDetailLoading();
		getDetailContent().html(
			'<div class="ppw-jd-loading"><span class="ppw-jd-spinner"></span>' +
				(ppwSearch.linkLoading || ppwSearch.detailLoading) +
				'</div>'
		);

		detailRequest = $.ajax({
			url: ppwSearch.ajaxUrl,
			type: 'POST',
			data: {
				action: 'ppw_resolve_product_link',
				nonce: ppwSearch.nonce,
				query: query
			}
		})
			.done(function (response) {
				if (response.success && response.data && response.data.html) {
					if (response.data.platform) {
						setActivePlatform(response.data.platform);
					}
					getResultsContainer().html(
						'<p class="ppw-jd-link-resolved">' +
							(ppwSearch.linkResolved || '') +
							'</p>'
					);
					getDetailContent().html(response.data.html);
					openDetailModal();
					showSection();
				} else {
					var msg =
						response.data && response.data.message
							? response.data.message
							: ppwSearch.linkError || ppwSearch.detailError;
					getResultsContainer().html('<p class="ppw-jd-error">' + msg + '</p>');
					getDetailContent().html('<p class="ppw-jd-error">' + msg + '</p>');
					showSection();
					openDetailModal();
				}
			})
			.fail(function (xhr) {
				var msg = ppwSearch.linkError || ppwSearch.detailError;
				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					msg = xhr.responseJSON.data.message;
				}
				getResultsContainer().html('<p class="ppw-jd-error">' + msg + '</p>');
				getDetailContent().html('<p class="ppw-jd-error">' + msg + '</p>');
				showSection();
				openDetailModal();
			})
			.always(function () {
				detailRequest = null;
			});
	}

	/**
	 * @param {string} keyword
	 * @param {number} page
	 * @param {boolean} append
	 * @param {string} [platform]
	 */
	function searchProducts(keyword, page, append, platform) {
		keyword = $.trim(keyword);
		page = page || 1;
		append = !!append;
		platform = platform || currentPlatform;

		if (!append && looksLikeProductUrl(keyword)) {
			resolveProductLink(keyword);
			return;
		}

		setActivePlatform(platform);

		if (keyword.length < 2) {
			getResultsSection().removeClass('is-visible is-loading');
			getResultsContainer().empty();
			updateUrl('', currentPlatform);
			totalShown = 0;
			lastKeyword = '';
			return;
		}

		lastKeyword = keyword;
		lastSearchMode = 'keyword';
		lastImageFile = null;

		if (currentRequest) {
			currentRequest.abort();
		}

		if (!append) {
			setLoading(true);
			updateUrl(keyword, currentPlatform);
			totalShown = 0;
		} else {
			var $btn = $('[data-ppw-view-more]');
			$btn.prop('disabled', true).addClass('is-loading').text(ppwSearch.loadingMore);
		}

		currentRequest = $.ajax({
			url: ppwSearch.ajaxUrl,
			type: 'POST',
			data: {
				action: 'ppw_jd_search',
				nonce: ppwSearch.nonce,
				keyword: keyword,
				page: page,
				platform: currentPlatform
			}
		})
			.done(function (response) {
				if (!response.success || !response.data) {
					var failMsg =
						response.data && response.data.message
							? response.data.message
							: ppwSearch.noResults;
					if (!append) {
						getResultsContainer().html(
							'<p class="ppw-jd-empty">' + failMsg + '</p>'
						);
						showSection();
					} else {
						$('[data-ppw-view-more]')
							.prop('disabled', true)
							.removeClass('is-loading')
							.text(ppwSearch.noMore);
					}
					return;
				}

				var data = response.data;
				var count = data.count || 0;
				var plat = data.platform || currentPlatform;

				if (append) {
					if (data.cards_html) {
						$('[data-ppw-jd-grid]').append(data.cards_html);
						totalShown += count;
						updateResultsTitle(keyword, totalShown, plat);
					}

					var $more = $('[data-ppw-view-more]');
					if (data.has_more) {
						$more
							.prop('disabled', false)
							.removeClass('is-loading')
							.attr('data-page', data.page)
							.attr('data-keyword', keyword)
							.attr('data-platform', plat)
							.text(ppwSearch.viewMore);
					} else {
						$more
							.prop('disabled', true)
							.removeClass('is-loading')
							.text(ppwSearch.noMore);
						setTimeout(function () {
							$more.closest('.ppw-jd-view-more-wrap').fadeOut(200);
						}, 800);
					}
				} else if (data.html) {
					getResultsContainer().html(data.html);
					totalShown = count;
					showSection();
					scrollToResults();
				} else {
					getResultsContainer().html(
						'<p class="ppw-jd-empty">' + ppwSearch.noResults + '</p>'
					);
					showSection();
				}
			})
			.fail(function (xhr) {
				var msg = ppwSearch.error;
				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					msg = xhr.responseJSON.data.message;
				}
				if (!append) {
					getResultsContainer().html(
						'<p class="ppw-jd-error">' + msg + '</p>'
					);
					showSection();
				} else {
					$('[data-ppw-view-more]')
						.prop('disabled', false)
						.removeClass('is-loading')
						.text(ppwSearch.viewMore);
					alert(msg);
				}
			})
			.always(function () {
				getResultsSection().removeClass('is-loading');
				currentRequest = null;
			});
	}

	function bindHomepageSearch() {
		$('form[name="product-search"], .header-search-form form, .wf_search-form').each(
			function () {
				var form = $(this);
				var input = form.find(
					'input[name="s"], input.header-search-input, input.wf_search-field, input.search'
				);

				if (!input.length) {
					return;
				}

				if (ppwSearch.searchHint) {
					input.attr('placeholder', ppwSearch.searchHint);
				}

				injectImageSearchControl(form);

				form.on('submit', function (e) {
					if (!ppwSearch.isHome) {
						return;
					}

					e.preventDefault();
					searchProducts(input.val(), 1, false, currentPlatform);
				});
			}
		);
	}

	function injectImageSearchControl(form) {
		if (form.data('ppwImgBound')) {
			return;
		}
		form.data('ppwImgBound', true);

		var $host = form.closest('.header-search-form').length
			? form.closest('.header-search-form')
			: form.parent();

		if ($host.find('.ppw-img-search').length) {
			return;
		}

		var label = ppwSearch.imgButtonLabel || '图片搜索';
		var $wrap = $(
			'<div class="ppw-img-search" title="' +
				label +
				'">' +
				'<label class="ppw-img-search-btn">' +
				'<input type="file" class="ppw-img-search-input" accept="image/jpeg,image/png,image/webp,image/gif,image/*" />' +
				'<span class="ppw-img-search-icon" aria-hidden="true">' +
				'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
				'<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>' +
				'<circle cx="12" cy="13" r="4"></circle>' +
				'</svg>' +
				'</span>' +
				'<span class="ppw-img-search-text">' +
				label +
				'</span>' +
				'</label>' +
				'<span class="ppw-img-search-preview" hidden></span>' +
				'</div>'
		);

		$host.append($wrap);

		$wrap.on('change', '.ppw-img-search-input', function () {
			var inputEl = this;
			var file = inputEl.files && inputEl.files[0] ? inputEl.files[0] : null;
			if (!file) {
				return;
			}

			var allowed = /^(image\/jpeg|image\/png|image\/webp|image\/gif)$/i;
			if (!allowed.test(file.type)) {
				window.alert(ppwSearch.imgInvalidType || '请上传 JPG / PNG / WEBP / GIF 图片。');
				inputEl.value = '';
				return;
			}
			if (file.size > 5 * 1024 * 1024) {
				window.alert(ppwSearch.imgTooLarge || '图片过大，请选择 5MB 以内的图片。');
				inputEl.value = '';
				return;
			}

			var $preview = $wrap.find('.ppw-img-search-preview');
			prepareImageFile(file)
				.then(function (readyFile) {
					if (window.URL && window.URL.createObjectURL) {
						$preview
							.html(
								'<img src="' +
									window.URL.createObjectURL(readyFile) +
									'" alt="" /><button type="button" class="ppw-img-search-clear" aria-label="清除">×</button>'
							)
							.prop('hidden', false);
					}
					searchByImage(readyFile, 1, false, currentPlatform);
				})
				.catch(function () {
					searchByImage(file, 1, false, currentPlatform);
				});

			inputEl.value = '';
		});

		$wrap.on('click', '.ppw-img-search-clear', function (e) {
			e.preventDefault();
			$wrap.find('.ppw-img-search-preview').prop('hidden', true).empty();
			lastImageFile = null;
			lastSearchMode = 'keyword';
		});
	}

	/**
	 * Compress/normalize image to JPEG ≤720px / ~300KB for OneBound upload_img / item_search_img.
	 * Phone camera photos are large; oversize uploads often only return temp IP URLs that 5000.
	 * @param {File} file
	 * @returns {Promise<File>}
	 */
	function prepareImageFile(file) {
		return new Promise(function (resolve) {
			if (!file || !window.FileReader || !document.createElement('canvas').getContext) {
				resolve(file);
				return;
			}

			var reader = new FileReader();
			reader.onerror = function () {
				resolve(file);
			};
			reader.onload = function () {
				var img = new Image();
				img.onload = function () {
					var maxSide = 720;
					var w = img.width || 1;
					var h = img.height || 1;
					var scale = Math.min(1, maxSide / Math.max(w, h));
					var cw = Math.max(1, Math.round(w * scale));
					var ch = Math.max(1, Math.round(h * scale));
					var canvas = document.createElement('canvas');
					canvas.width = cw;
					canvas.height = ch;
					var ctx = canvas.getContext('2d');
					if (!ctx) {
						resolve(file);
						return;
					}
					ctx.drawImage(img, 0, 0, cw, ch);

					var qualities = [0.78, 0.65, 0.52, 0.4];
					var name = (file.name || 'search').replace(/\.\w+$/, '') + '.jpg';
					var maxBytes = 300 * 1024;

					function tryQuality(i) {
						if (i >= qualities.length) {
							canvas.toBlob(
								function (blob) {
									resolve(blob ? new File([blob], name, { type: 'image/jpeg' }) : file);
								},
								'image/jpeg',
								0.4
							);
							return;
						}
						canvas.toBlob(
							function (blob) {
								if (!blob) {
									resolve(file);
									return;
								}
								if (blob.size <= maxBytes || i === qualities.length - 1) {
									resolve(new File([blob], name, { type: 'image/jpeg' }));
									return;
								}
								tryQuality(i + 1);
							},
							'image/jpeg',
							qualities[i]
						);
					}

					tryQuality(0);
				};
				img.onerror = function () {
					resolve(file);
				};
				img.src = reader.result;
			};
			reader.readAsDataURL(file);
		});
	}

	/**
	 * @param {File} file
	 * @param {number} page
	 * @param {boolean} append
	 * @param {string} [platform]
	 */
	function searchByImage(file, page, append, platform) {
		if (!file) {
			return;
		}

		page = page || 1;
		append = !!append;
		platform = platform || currentPlatform;
		setActivePlatform(platform);

		lastSearchMode = 'image';
		lastImageFile = file;
		lastKeyword = ppwSearch.imgButtonLabel || '以图搜图';

		if (currentRequest) {
			currentRequest.abort();
		}

		if (!append) {
			getResultsSection().addClass('is-visible is-loading');
			getResultsContainer().html(
				'<div class="ppw-jd-loading"><span class="ppw-jd-spinner"></span>' +
					(ppwSearch.imgSearching || ppwSearch.loading) +
					'</div>'
			);
			totalShown = 0;
		} else {
			$('[data-ppw-view-more]')
				.prop('disabled', true)
				.addClass('is-loading')
				.text(ppwSearch.loadingMore);
		}

		var formData = new FormData();
		formData.append('action', 'ppw_img_search');
		formData.append('nonce', ppwSearch.nonce);
		formData.append('platform', currentPlatform);
		formData.append('page', String(page));
		formData.append('image', file, file.name || 'search.jpg');

		currentRequest = $.ajax({
			url: ppwSearch.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			timeout: 120000
		})
			.done(function (response) {
				if (!response.success || !response.data) {
					var failMsg =
						response.data && response.data.message
							? response.data.message
							: ppwSearch.noResults;
					if (!append) {
						getResultsContainer().html(
							'<p class="ppw-jd-empty">' + failMsg + '</p>'
						);
						showSection();
					} else {
						$('[data-ppw-view-more]')
							.prop('disabled', true)
							.removeClass('is-loading')
							.text(ppwSearch.noMore);
					}
					return;
				}

				var data = response.data;
				var count = data.count || 0;
				var plat = data.platform || currentPlatform;
				var titleKeyword = data.keyword || lastKeyword;

				if (append) {
					if (data.cards_html) {
						$('[data-ppw-jd-grid]').append(data.cards_html);
						totalShown += count;
						updateResultsTitle(titleKeyword, totalShown, plat);
					}

					var $more = $('[data-ppw-view-more]');
					if (data.has_more) {
						$more
							.prop('disabled', false)
							.removeClass('is-loading')
							.attr('data-page', data.page)
							.attr('data-keyword', titleKeyword)
							.attr('data-platform', plat)
							.attr('data-mode', 'image')
							.text(ppwSearch.viewMore);
					} else {
						$more
							.prop('disabled', true)
							.removeClass('is-loading')
							.text(ppwSearch.noMore);
						setTimeout(function () {
							$more.closest('.ppw-jd-view-more-wrap').fadeOut(200);
						}, 800);
					}
				} else if (data.html) {
					getResultsContainer().html(data.html);
					$('[data-ppw-view-more]').attr('data-mode', 'image');
					totalShown = count;
					showSection();
					scrollToResults();
				} else {
					getResultsContainer().html(
						'<p class="ppw-jd-empty">' + ppwSearch.noResults + '</p>'
					);
					showSection();
				}
			})
			.fail(function (xhr) {
				if (xhr.statusText === 'abort') {
					return;
				}
				var msg = ppwSearch.error;
				if (xhr.statusText === 'timeout') {
					msg = '图片搜索超时，请换一张较小的 JPG/PNG 图片后重试。';
				} else if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					msg = xhr.responseJSON.data.message;
				}
				if (!append) {
					getResultsContainer().html('<p class="ppw-jd-error">' + msg + '</p>');
					showSection();
				} else {
					$('[data-ppw-view-more]')
						.prop('disabled', false)
						.removeClass('is-loading')
						.text(ppwSearch.viewMore);
					window.alert(msg);
				}
			})
			.always(function () {
				getResultsSection().removeClass('is-loading');
				currentRequest = null;
			});
	}

	function bindPlatformSwitcher() {
		$(document).on('click', '[data-ppw-platform]', function (e) {
			e.preventDefault();
			var platform = $(this).data('ppw-platform');
			if (!platform || platform === currentPlatform) {
				return;
			}
			setActivePlatform(platform);
			showSection();
			if (lastSearchMode === 'image' && lastImageFile) {
				searchByImage(lastImageFile, 1, false, currentPlatform);
				return;
			}
			if (lastKeyword && looksLikeProductUrl(lastKeyword)) {
				getResultsContainer().html(
					'<p class="ppw-jd-empty">' +
						'当前为' +
						platformLabel(currentPlatform) +
						'，可输入关键词搜索，或粘贴对应平台商品链接。' +
						'</p>'
				);
				return;
			}
			if (lastKeyword && lastKeyword.length >= 2 && lastSearchMode !== 'image') {
				searchProducts(lastKeyword, 1, false, currentPlatform);
			} else {
				getResultsContainer().html(
					'<p class="ppw-jd-empty">' +
						'当前为' +
						platformLabel(currentPlatform) +
						'商品，请输入关键词或粘贴商品链接，也可使用图片搜索。' +
						'</p>'
				);
				updateUrl('', currentPlatform);
			}
		});
	}

	function bindProductCards() {
		$(document).on('click', '.ppw-jd-card-link', function (e) {
			e.preventDefault();
			loadProductDetail($(this));
		});

		$(document).on('click', '[data-ppw-close-detail]', function () {
			closeDetailModal();
		});

		$(document).on('click', '.ppw-jd-detail-thumb', function () {
			var image = $(this).data('image');
			if (image) {
				$('.ppw-jd-detail-main-image img').attr('src', image);
			}
		});

		$(document).on('keyup', function (e) {
			if (e.key === 'Escape') {
				closeDetailModal();
			}
		});
	}

	function bindViewMore() {
		$(document).on('click', '[data-ppw-view-more]', function (e) {
			e.preventDefault();
			var $btn = $(this);
			if ($btn.prop('disabled') || $btn.hasClass('is-loading')) {
				return;
			}

			var keyword = $btn.attr('data-keyword') || lastKeyword || '';
			var page = parseInt($btn.attr('data-page'), 10) || 1;
			var platform = $btn.attr('data-platform') || currentPlatform;
			var mode = $btn.attr('data-mode') || lastSearchMode || 'keyword';

			if (mode === 'image' && lastImageFile) {
				searchByImage(lastImageFile, page + 1, true, platform);
				return;
			}

			searchProducts(keyword, page + 1, true, platform);
		});
	}

	$(document).ready(function () {
		var urlPlatform = '';
		try {
			urlPlatform = new URL(window.location.href).searchParams.get('ppw_platform') || '';
		} catch (err) {
			urlPlatform = '';
		}
		setActivePlatform(urlPlatform || currentPlatform);
		bindHomepageSearch();
		bindPlatformSwitcher();
		bindProductCards();
		bindViewMore();

		if (ppwSearch.keyword) {
			searchProducts(ppwSearch.keyword, 1, false, currentPlatform);
		} else {
			showSection();
		}
	});
})(jQuery);
