/**
 * Admin JavaScript
 *
 * @package WTranslator
 */

// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar

(function ($) {
	'use strict';

	function hideForeignAdminNotices() {
		$( '#wpbody-content' ).children( '.notice, .error, .updated, .update-nag, .woocommerce-message, .woocommerce-error, .fs-notice, .elementor-message, .ngg_admin_notice' ).each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'wtranslator-notice' ) ) {
				return;
			}
			$el.remove();
		} );

		$( '.wtranslator-admin-wrap' ).children( '.notice, .error, .updated, .update-nag' ).each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'wtranslator-notice' ) ) {
				return;
			}
			$el.remove();
		} );
	}

	$( document ).ready(
		function () {
			hideForeignAdminNotices();
			if ( typeof MutationObserver !== 'undefined' ) {
				var bodyContent = document.getElementById( 'wpbody-content' );
				if ( bodyContent ) {
					var noticeObserver = new MutationObserver( hideForeignAdminNotices );
					noticeObserver.observe( bodyContent, { childList: true } );
				}
			}

			// Select all languages checkbox helper
			var $languageCheckboxes = $( '.wtranslator-language-checkbox input[type="checkbox"]' );

			if ($languageCheckboxes.length > 0) {
				$( '#select-all-languages' ).on(
					'click',
					function () {
						$languageCheckboxes.prop( 'checked', true );
					}
				);

				$( '#deselect-all-languages' ).on(
					'click',
					function () {
						$languageCheckboxes.prop( 'checked', false );
					}
				);
			}

			// Drag and drop functionality for language reordering
			if ($( '#languages-sortable' ).length > 0 && typeof $.fn.sortable !== 'undefined') {
				$( '#languages-sortable' ).sortable(
					{
						handle: '.wtranslator-drag-handle',
						cursor: 'move',
						opacity: 0.6,
						tolerance: 'pointer',
						placeholder: 'wtranslator-language-placeholder',
						update: function (event, ui) {
							// Update hidden input with new order
							var order = [];
							$( '#languages-sortable .wtranslator-language-checkbox' ).each(
								function () {
									order.push( $( this ).data( 'lang-code' ) );
								}
							);
							$( '#language-order' ).val( order.join( ',' ) );
						}
					}
				);
			}

			// Reload preview iframe when settings change
			var $previewIframe = $( '#wtranslator-preview-iframe' );
			var reloadPreview  = function () {
				if ($previewIframe.length) {
					var src = $previewIframe.attr( 'src' );
					// Remove existing timestamp if any
					src = src.split( '&_t=' )[0].split( '?_t=' )[0];
					// Add new timestamp to force reload
					var separator = src.indexOf( '?' ) !== -1 ? '&' : '?';
					$previewIframe.attr( 'src', src + separator + '_t=' + Date.now() );
				}
			};

			// Reload preview on input changes (debounced) - immediate for better UX
			var previewReloadTimeout;
			$( '.wtranslator-settings-form' ).on(
				'change input',
				'select, input[type="checkbox"], input[type="text"], textarea',
				function () {
					clearTimeout( previewReloadTimeout );
					previewReloadTimeout = setTimeout( reloadPreview, 500 );
				}
			);

			// Scroll to top and reload preview after successful save
			if (window.location.search.indexOf( 'settings-updated=true' ) !== -1) {
				$( 'html, body' ).animate( { scrollTop: 0 }, 300 );
				setTimeout( reloadPreview, 300 );
			}

			// Initial load delay to ensure iframe is ready
			setTimeout(
				function () {
					if ($previewIframe.length && ! $previewIframe.attr( 'src' )) {
						var src = $previewIframe.data( 'src' ) || $previewIframe.attr( 'src' );
						if (src) {
							$previewIframe.attr( 'src', src );
						}
					}
				},
				100
			);

			// Form validation and AJAX save (backend handles message)
			$( '.wtranslator-settings-form' ).on(
				'submit',
				function (e) {
					e.preventDefault();
					var $form     = $( this );
					var hasErrors = false;

					// Check if at least one language is selected
					var $checkedLanguages = $( '.wtranslator-language-checkbox input[type="checkbox"]:checked' );
					if ($checkedLanguages.length === 0) {
						alert( 'Please select at least one enabled language.' );
						hasErrors = true;
						return false;
					}

					// Disable submit button to prevent double submission
					var $submitBtn    = $form.find( 'input[type="submit"]' );
					var originalValue = $submitBtn.val();
					$submitBtn.prop( 'disabled', true ).val( 'Saving...' );

					// Remove existing notices
					$( '.wtranslator-admin-wrap .notice' ).remove();

					// Serialize form data
					var formData = $form.serialize();
					formData    += '&action=wtranslator_save_settings';

					// AJAX request to save settings (backend returns message)
					$.ajax(
						{
							url: ajaxurl,
							type: 'POST',
							data: formData,
							success: function (response) {
								if (response.success && response.data && response.data.message) {
									// Show success message from backend - inside container, after h1
									var $successNotice = $( '<div class="notice-success is-dismissible" style="margin-top: 20px; margin-bottom: 20px;"><p>' + response.data.message + '</p></div>' );
									// Insert inside .wtranslator-admin-wrap container, after h1 (ensure it's inside the container)
									var $container = $( '.wtranslator-admin-wrap' );
									var $h1        = $container.find( 'h1.wtranslator-admin-title' ).first();
									if ($h1.length) {
										$h1.after( $successNotice );
									} else {
										// Fallback: insert at the beginning of container
										$container.prepend( $successNotice );
									}

									// Scroll to top to show message
									$( 'html, body' ).animate(
										{
											scrollTop: 0
										},
										300
									);

									// Auto-dismiss after 5 seconds
									setTimeout(
										function () {
											$successNotice.fadeOut(
												300,
												function () {
													$( this ).remove();
												}
											);
										},
										5000
									);
								} else {
									// Show error message - inside container, after h1
									var $errorNotice = $( '<div class="notice-error is-dismissible" style="margin-top: 20px; margin-bottom: 20px;"><p>Failed to save settings. Please try again.</p></div>' );
									// Insert inside .wtranslator-admin-wrap container, after h1
									var $container = $( '.wtranslator-admin-wrap' );
									var $h1        = $container.find( 'h1.wtranslator-admin-title' ).first();
									if ($h1.length) {
										$h1.after( $errorNotice );
									} else {
										// Fallback: insert at the beginning of container
										$container.prepend( $errorNotice );
									}
								}

								// Re-enable submit button
								$submitBtn.prop( 'disabled', false ).val( originalValue );
							},
							error: function () {
								// Show error message - inside container, after h1
								var $errorNotice = $( '<div class="notice-error is-dismissible" style="margin-top: 20px; margin-bottom: 20px;"><p>Failed to save settings. Please try again.</p></div>' );
								// Insert inside .wtranslator-admin-wrap container, after h1
								var $container = $( '.wtranslator-admin-wrap' );
								var $h1        = $container.find( 'h1.wtranslator-admin-title' ).first();
								if ($h1.length) {
									$h1.after( $errorNotice );
								} else {
									// Fallback: insert at the beginning of container
									$container.prepend( $errorNotice );
								}

								// Re-enable submit button
								$submitBtn.prop( 'disabled', false ).val( originalValue );
							}
						}
					);

					return false;
				}
			);
		}
	);

})( jQuery );
