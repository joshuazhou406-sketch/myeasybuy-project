<?php
// phpcs:ignoreFile
/**
 * Admin Settings Page
 * Matches the design language of translate-app (dark theme, teal accents)
 * Includes all translation options with simple design
 *
 * @package WTranslator
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName,Squiz.Commenting.InlineComment.InvalidEndChar,Squiz.Commenting.ClassComment.Missing,Generic.CodeAnalysis.EmptyStatement.DetectedCatch

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTranslator_Admin_Settings {

	/**
	 * Render settings page
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wtranslator' ) );
		}

		// Ensure we get the freshest data from the database, bypassing any object cache
		wp_cache_delete( 'wtranslator_settings', 'options' );

		$settings = get_option( 'wtranslator_settings', array() );

		// Ensure Language Manager is loaded
		if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		}

		$language_manager  = new WTranslator_Language_Manager();
		$all_languages     = $language_manager->get_all_languages();
		$enabled_languages = ! empty( $settings['enabled_languages'] ) ? $settings['enabled_languages'] : array( 'en' );

		$pro_features = array(
			__( 'Neural AI translation', 'wtranslator' ),
			__( 'Search-engine indexing', 'wtranslator' ),
			__( 'SEO-friendly URLs', 'wtranslator' ),
			__( 'URL/slug translation (example.com/about-us → example.es/sobre-nosotros)', 'wtranslator' ),
			__( 'Automatic hreflang tags', 'wtranslator' ),
			__( 'Manual translation correction', 'wtranslator' ),
			__( 'Inline/in-context translation editor', 'wtranslator' ),
			__( 'Translation memory/cache', 'wtranslator' ),
			__( 'Translation Delivery Network/Proxy', 'wtranslator' ),
			__( 'Subdomain language hosting', 'wtranslator' ),
			__( '248+ Country-domain language hosting', 'wtranslator' ),
			__( '248+ Subdirectory language URLs', 'wtranslator' ),
			__( 'WooCommerce support', 'wtranslator' ),
			__( 'WooCommerce product translation', 'wtranslator' ),
			__( 'WooCommerce schema translation', 'wtranslator' ),
			__( 'Usage and traffic analytics', 'wtranslator' ),
			__( 'User analytics dashboard', 'wtranslator' ),
			__( 'Elementor translation compatibility', 'wtranslator' ),
			__( 'WPBakery translation compatibility', 'wtranslator' ),
			__( 'Divi translation compatibility', 'wtranslator' ),
			__( 'Yoast, RankMath & AIOSEO SEO plugin compatibility', 'wtranslator' ),
			__( 'Metadata translation', 'wtranslator' ),
			__( 'Sitemap translation', 'wtranslator' ),
			__( 'AMP translation', 'wtranslator' ),
			__( 'Cloud-managed updates', 'wtranslator' ),
			__( 'Traffic and AdSense growth tools (all popular ads platforms)', 'wtranslator' ),
			__( 'Community support', 'wtranslator' ),
			__( 'Priority email support', 'wtranslator' ),
			__( 'Live-chat support', 'wtranslator' ),
			__( 'Dedicated account manager', 'wtranslator' ),
		);

		$pro_buy_url = defined( 'WTRANSLATOR_PRO_UPGRADE_URL' ) ? WTRANSLATOR_PRO_UPGRADE_URL : 'https://wtranslator.com/pricing/?utm_source=Plugin&utm_medium=upgrade-dashboard-notifications-menu&utm_campaign=WP';

		?>
		<div class="wrap wtranslator-admin-wrap">
			<h1 class="wtranslator-admin-title">
				<span class="dashicons dashicons-translation wtranslator-icon" aria-hidden="true"></span>
				<?php esc_html_e( 'WTranslator Settings', 'wtranslator' ); ?>
			</h1>
			
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL flag set by wp_safe_redirect after save; no form data is processed here.
			if ( isset( $_GET['settings-updated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible wtranslator-notice" id="wtranslator-settings-saved-notice">
					<p><?php esc_html_e( 'Settings saved successfully!', 'wtranslator' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wtranslator-pro-upsell">
				<div class="wtranslator-pro-upsell-inner">
					<div class="wtranslator-pro-upsell-heading">
						<div class="wtranslator-pro-upsell-icon" aria-hidden="true">
							<span class="dashicons dashicons-translation"></span>
						</div>
						<h2 class="wtranslator-pro-upsell-title"><?php esc_html_e( 'Take Translation to the Next Level!', 'wtranslator' ); ?></h2>
					</div>
					<p class="wtranslator-pro-upsell-subtitle"><?php esc_html_e( 'Unlock WTranslator Pro — SEO-ready multilingual pages, AI translation, and premium support.', 'wtranslator' ); ?></p>
					<ul class="wtranslator-pro-upsell-features">
						<?php foreach ( $pro_features as $feature ) : ?>
							<li>
								<span class="wtranslator-pro-check" aria-hidden="true">
									<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
										<polyline points="20 6 9 17 4 12"></polyline>
									</svg>
								</span>
								<span><?php echo esc_html( $feature ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="wtranslator-pro-upsell-footer">
					<a class="wtranslator-pro-buy-btn" href="<?php echo esc_url( $pro_buy_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'BUY NOW', 'wtranslator' ); ?>
					</a>
				</div>
			</div>
			
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wtranslator' ) ); ?>" class="wtranslator-settings-form">
				<?php wp_nonce_field( 'wtranslator_settings_nonce', '_wpnonce', true, true ); ?>
				
				<div class="wtranslator-main-layout">
					
					<!-- Left Column -->
					<div class="wtranslator-left-column">
					
					<!-- Widget Options (overflow visible so dropdown preview is not clipped) -->
					<div class="wtranslator-settings-card wtranslator-widget-options-card">
						<div class="wtranslator-card-header">
							<h2><?php esc_html_e( 'Widget Options', 'wtranslator' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Configure how the language switcher appears on your site', 'wtranslator' ); ?></p>
						</div>
						<div class="wtranslator-card-body">
							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="widget_look"><?php esc_html_e( 'Widget Look', 'wtranslator' ); ?></label>
									</th>
									<td>
										<select id="widget_look" name="wtranslator_settings[widget_look]">
											<option value="dropdown" <?php selected( ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : 'dropdown', 'dropdown' ); ?>>
												<?php esc_html_e( 'Dropdown', 'wtranslator' ); ?>
											</option>
											<option value="float" <?php selected( ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : '', 'float' ); ?>>
												<?php esc_html_e( 'Float', 'wtranslator' ); ?>
											</option>
											<option value="flags" <?php selected( ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : '', 'flags' ); ?>>
												<?php esc_html_e( 'Flags Only', 'wtranslator' ); ?>
											</option>
											<option value="flags_name" <?php selected( ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : '', 'flags_name' ); ?>>
												<?php esc_html_e( 'Flags + Name', 'wtranslator' ); ?>
											</option>
										</select>
										<p class="description"><?php esc_html_e( 'How the language switcher should appear', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="translate_from"><?php esc_html_e( 'Translate From', 'wtranslator' ); ?></label>
									</th>
									<td>
										<select id="translate_from" name="wtranslator_settings[translate_from]">
											<?php foreach ( $all_languages as $code => $lang ) : ?>
												<option value="<?php echo esc_attr( $code ); ?>" <?php selected( ! empty( $settings['translate_from'] ) ? $settings['translate_from'] : 'en', $code ); ?>>
													<?php echo esc_html( $lang['native'] . ' (' . $lang['name'] . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Source language for translation', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="open_direction"><?php esc_html_e( 'Open Direction', 'wtranslator' ); ?></label>
									</th>
									<td>
										<select id="open_direction" name="wtranslator_settings[open_direction]">
											<option value="up" <?php selected( ! empty( $settings['open_direction'] ) ? $settings['open_direction'] : 'up', 'up' ); ?>>
												<?php esc_html_e( 'Up', 'wtranslator' ); ?>
											</option>
											<option value="down" <?php selected( ! empty( $settings['open_direction'] ) ? $settings['open_direction'] : 'up', 'down' ); ?>>
												<?php esc_html_e( 'Down', 'wtranslator' ); ?>
											</option>
										</select>
										<p class="description"><?php esc_html_e( 'Direction the dropdown opens', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="floating_language_selector"><?php esc_html_e( 'Show Floating Language Selector', 'wtranslator' ); ?></label>
									</th>
									<td>
										<select id="floating_language_selector" name="wtranslator_settings[floating_language_selector]">
											<option value="no" <?php selected( ! empty( $settings['floating_language_selector'] ) ? $settings['floating_language_selector'] : 'no', 'no' ); ?>>
												<?php esc_html_e( 'No', 'wtranslator' ); ?>
											</option>
											<option value="bottom_left" <?php selected( ! empty( $settings['floating_language_selector'] ) ? $settings['floating_language_selector'] : '', 'bottom_left' ); ?>>
												<?php esc_html_e( 'Bottom left', 'wtranslator' ); ?>
											</option>
											<option value="bottom_right" <?php selected( ! empty( $settings['floating_language_selector'] ) ? $settings['floating_language_selector'] : '', 'bottom_right' ); ?>>
												<?php esc_html_e( 'Bottom right', 'wtranslator' ); ?>
											</option>
											<option value="top_left" <?php selected( ! empty( $settings['floating_language_selector'] ) ? $settings['floating_language_selector'] : '', 'top_left' ); ?>>
												<?php esc_html_e( 'Top left', 'wtranslator' ); ?>
											</option>
											<option value="top_right" <?php selected( ! empty( $settings['floating_language_selector'] ) ? $settings['floating_language_selector'] : '', 'top_right' ); ?>>
												<?php esc_html_e( 'Top right', 'wtranslator' ); ?>
											</option>
										</select>
										<p class="description"><?php esc_html_e( 'Show floating language selector option is the easiest and suitable for most websites.', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="show_in_menu"><?php esc_html_e( 'Show in Menu', 'wtranslator' ); ?></label>
									</th>
									<td>
										<select id="show_in_menu" name="wtranslator_settings[show_in_menu]">
											<option value="" <?php selected( ! empty( $settings['show_in_menu'] ) ? $settings['show_in_menu'] : '', '' ); ?>>
												<?php esc_html_e( '- None -', 'wtranslator' ); ?>
											</option>
											<?php
											$menus = get_registered_nav_menus();
											if ( ! empty( $menus ) ) {
												foreach ( $menus as $location => $description ) :
													?>
												<option value="<?php echo esc_attr( $location ); ?>" <?php selected( ! empty( $settings['show_in_menu'] ) ? $settings['show_in_menu'] : '', $location ); ?>>
													<?php echo esc_html( $description ); ?>
												</option>
													<?php
												endforeach;
											}
											?>
										</select>
										<p class="description"><?php esc_html_e( 'Add language switcher to a navigation menu', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="wrapper_selector"><?php esc_html_e( 'Wrapper Selector CSS', 'wtranslator' ); ?></label>
									</th>
									<td>
										<input type="text" 
												id="wrapper_selector" 
												name="wtranslator_settings[wrapper_selector]" 
												value="<?php echo esc_attr( ! empty( $settings['wrapper_selector'] ) ? $settings['wrapper_selector'] : '.wtranslator-wrapper' ); ?>" 
												class="regular-text code">
										<p class="description"><?php esc_html_e( 'CSS selector for custom wrapper element', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Preview', 'wtranslator' ); ?></th>
									<td class="wtranslator-preview-cell">
										<div class="wtranslator-widget-preview-inline" style="max-width: 320px; overflow: visible;">
											<?php
											if ( class_exists( 'WTranslator' ) ) {
                                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget HTML from plugin
												echo WTranslator::get_instance()->get_widget_code( array( 'position' => 'inline' ) );
											}
											?>
											<p class="description" style="margin: 8px 0 0; font-size: 12px;"><?php esc_html_e( 'Same switcher appears on your site. Updates when you save.', 'wtranslator' ); ?></p>
										</div>
									</td>
								</tr>
							</table>
						</div>
					</div>
					
					<!-- Advanced Options -->
					<div class="wtranslator-settings-card">
						<div class="wtranslator-card-header">
							<h2><?php esc_html_e( 'Advanced Options', 'wtranslator' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Additional configuration options', 'wtranslator' ); ?>
							</p>
						</div>
						<div class="wtranslator-card-body">
							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="native_language_names">
											<input type="checkbox" 
													id="native_language_names" 
													name="wtranslator_settings[native_language_names]" 
													value="1"
													<?php checked( ! empty( $settings['native_language_names'] ) ); ?>>
											<?php esc_html_e( 'Native Language Names', 'wtranslator' ); ?>
										</label>
									</th>
									<td>
										<p class="description"><?php esc_html_e( 'Show language names in their native form', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="auto_detect">
											<input type="checkbox" 
													id="auto_detect" 
													name="wtranslator_settings[auto_detect]" 
													value="1"
													<?php checked( ! empty( $settings['auto_detect'] ) ); ?>>
											<?php esc_html_e( 'Auto switch to visitor language', 'wtranslator' ); ?>
										</label>
									</th>
									<td>
										<p class="description"><?php esc_html_e( 'On first visit (no language cookie yet), suggests a language from the browser’s Accept-Language header, or from the Cloudflare country header when your site uses Cloudflare. No third-party geo APIs are used.', 'wtranslator' ); ?></p>
									</td>
								</tr>
								<tr>
									<td colspan="2" style="padding: 16px; background: #f0f9ff; border-left: 4px solid #0d9488; border-radius: 4px;">
										<p style="margin: 0 0 8px 0;"><strong><?php esc_html_e( 'Sub-directory URL & Sub-domain URL', 'wtranslator' ); ?></strong></p>
										<p style="margin: 0 0 12px 0; color: #475569;">
											<?php esc_html_e( 'Sub-directory URL (e.g. example.com/ar/) and Sub-domain URL (e.g. es.example.com) are available in WTranslator Cloud — manage from one dashboard.', 'wtranslator' ); ?>
										</p>
										<a href="<?php echo esc_url( 'https://wtranslator.com' ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary button-small">
											<?php esc_html_e( 'WTranslator Cloud', 'wtranslator' ); ?> →
										</a>
									</td>
								</tr>
							</table>
						</div>
					</div>
					
					</div>
					<!-- End Left Column -->
					
					<!-- Right Column - Language Selection -->
					<div class="wtranslator-right-column">
					<div class="wtranslator-settings-card wtranslator-language-card">
						<div class="wtranslator-card-header">
							<h2><?php esc_html_e( 'Language Selection', 'wtranslator' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Select which languages to enable', 'wtranslator' ); ?></p>
						</div>
						<div class="wtranslator-card-body">
							<div style="margin-bottom: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
								<button type="button" id="select-all-languages" class="button button-small"><?php esc_html_e( 'Check All', 'wtranslator' ); ?></button>
								<button type="button" id="deselect-all-languages" class="button button-small"><?php esc_html_e( 'Uncheck All', 'wtranslator' ); ?></button>
							</div>
							<div class="wtranslator-languages-grid" id="languages-sortable">
								<?php
								// Get language order from settings or use default order
								$language_order = ! empty( $settings['language_order'] ) ? $settings['language_order'] : array_keys( $all_languages );

								// Merge order with all languages to ensure all are included
								$ordered_languages = array();
								foreach ( $language_order as $code ) {
									if ( isset( $all_languages[ $code ] ) ) {
										$ordered_languages[ $code ] = $all_languages[ $code ];
									}
								}
								// Add any missing languages
								foreach ( $all_languages as $code => $lang ) {
									if ( ! isset( $ordered_languages[ $code ] ) ) {
										$ordered_languages[ $code ] = $lang;
									}
								}

								foreach ( $ordered_languages as $code => $lang ) :
									?>
									<label class="wtranslator-language-checkbox" data-lang-code="<?php echo esc_attr( $code ); ?>">
										<input type="checkbox" 
												name="wtranslator_settings[enabled_languages][]" 
												value="<?php echo esc_attr( $code ); ?>"
												<?php checked( in_array( $code, $enabled_languages, true ) ); ?>>
										<span class="wtranslator-flag"><?php echo esc_html( $lang['flag'] ); ?></span>
										<span class="wtranslator-lang-label">
											<strong><?php echo esc_html( $lang['native'] ); ?></strong>
											<small><?php echo esc_html( $lang['name'] ); ?></small>
										</span>
										<span class="wtranslator-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'wtranslator' ); ?>">☰</span>
									</label>
								<?php endforeach; ?>
							</div>
							<input type="hidden" id="language-order" name="wtranslator_settings[language_order]" value="<?php echo esc_attr( implode( ',', array_keys( $ordered_languages ) ) ); ?>">
							<p class="description" style="margin-top: 12px;">
								<?php esc_html_e( 'HINT: To reorder the languages, simply drag and drop them in the list above.', 'wtranslator' ); ?>
							</p>
						</div>
					</div>
					</div>
					<!-- End Right Column -->
					
				</div>
				<!-- End Main Layout -->

				<p class="submit">
					<input type="submit"
							name="wtranslator_save_settings"
							class="button button-primary button-large"
							value="<?php esc_attr_e( 'Save Changes', 'wtranslator' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wtranslator' ) );
		}

		// Verify nonce
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wtranslator_settings_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'wtranslator' ) );
		}

		try {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is unslashed, individual fields sanitized in sanitize_settings()
			$settings = isset( $_POST['wtranslator_settings'] ) ? wp_unslash( $_POST['wtranslator_settings'] ) : array();

			// Define fields that are checkboxes and might be missing if unchecked
			$checkbox_fields = array(
				'native_language_names',
				'auto_detect',
			);

			foreach ( $checkbox_fields as $field ) {
				if ( ! isset( $settings[ $field ] ) ) {
					$settings[ $field ] = 0;
				}
			}

			// Special case for enabled_languages which is an array of checkboxes
			if ( ! isset( $settings['enabled_languages'] ) ) {
				$settings['enabled_languages'] = array();
			}

			// Sanitize settings
			$plugin    = WTranslator::get_instance();
			$sanitized = $plugin->sanitize_settings( $settings );

			// Merge with existing settings to preserve any fields not in the form
			$existing_settings = get_option( 'wtranslator_settings', array() );
			$sanitized         = array_merge( $existing_settings, $sanitized );

			// Save settings
			update_option( 'wtranslator_settings', $sanitized );

			$redirect_url = admin_url( 'admin.php?page=wtranslator&settings-updated=true' );

		} catch ( Exception $e ) {
			// Error occurred but continue with redirect
			$redirect_url = admin_url( 'admin.php?page=wtranslator&settings-updated=true' );
		}

		if ( empty( $redirect_url ) ) {
			$redirect_url = admin_url( 'admin.php?page=wtranslator&settings-updated=true' );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
