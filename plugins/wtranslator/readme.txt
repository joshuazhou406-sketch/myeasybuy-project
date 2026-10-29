=== WTranslator ===
Contributors: wtranslator
Tags: translation, multilingual, language switcher, translate, languages
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.0.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate your site into 248+ languages with a language switcher. Free visitor-side translation via Google Translate; paid plans add SEO-ready pages.

== Description ==

WTranslator helps you make your website multilingual with a simple setup. The **free version** adds a language switcher and translates page content in the visitor's browser using **Google Translate**. Paid plans on [wtranslator.com](https://wtranslator.com/) can create crawlable, indexable, SEO-ready translated pages.

You can add a customizable language switcher, choose the languages you want to offer, and start translating without writing code. Upgrade later if you need multilingual SEO, translated URLs, and cloud-hosted translated pages.

Unlike server-side translation tools that store everything on your site, the free version keeps translation work in the visitor's browser. Paid plans can cache and deliver translated pages through the WTranslator cloud so your original site stays lightweight.

Paid plans include search-engine-friendly language URLs, translated URL slugs, hreflang tags, translated metadata, multilingual indexing, an in-context translation editor, translation memory, WooCommerce support, and language hosting. WTranslator does not limit the number of translated words or translated pageviews on paid plans.

Please use [WTranslator Support](https://wordpress.org/support/plugin/wtranslator) for questions and support requests. If you need extra help, email support@wtranslator.com. For quick answers, see our [FAQ and contact page](https://wtranslator.com/contact/).

**Free features**

* Instant visitor-side translation with Google Translate
* 248+ supported languages
* Unlimited words and pageviews in the free version
* Translate posts, pages, categories, tags, menus, and widgets
* Language switcher widget with dropdown, flags, or flags plus name
* Floating language selector
* Optional automatic browser-language switching
* Right-to-left language support
* Native language names and regional flags
* Gutenberg and WooCommerce storefront translation
* Community forum support

### Premium features

Paid WTranslator Cloud plans can include:

* Neural AI translation for stored, SEO-ready pages
* Search-engine indexing of translated pages
* SEO-friendly language URLs and slug translation (for example, example.com/about-us to a translated slug)
* Automatic hreflang tags
* Manual translation correction and an in-context editor
* Translation memory and a Translation Delivery Network
* Subdirectory, subdomain, and country-domain language hosting
* WooCommerce product and schema translation
* Usage and traffic analytics
* Compatibility with popular page builders and SEO tools
* Priority email and live-chat support

**How to set up WTranslator:**

https://www.youtube.com/watch?v=QVEtcXz6maE

**Free features walkthrough:**

https://youtu.be/SSNg7u4d4QM?si=C6K9np9dljLp84G7

== Installation ==

1. Install WTranslator from your dashboard (Plugins → Add New → Search "WTranslator")
2. Activate the plugin
3. Go to Settings → WTranslator
4. Choose which languages you want to offer
5. Pick your language switcher style (dropdown or flags)
6. Save. Visitors will see the language switcher and can choose a language.

== Frequently Asked Questions ==

= Do I need to translate my content manually? =

No. The free version uses Google Translate to translate page content when a visitor chooses another language. Activate the plugin, choose your languages, and content is translated for visitors in their browser.

= How is this different from AI translation? =

The free version uses Google Translate in the visitor's browser. It does not send your page content to WTranslator servers. Paid WTranslator Cloud plans can use AI translation to create stored, SEO-ready translated pages. Those cloud features are optional and are not required for the free plugin to work.

= How many languages can I offer? =

You can enable as many languages as you want from the list of 248+ supported languages, including English, Spanish, French, German, Portuguese, Chinese, Japanese, Arabic, Russian, and many more.

= Will my visitors' language choice be remembered? =

Yes. When a visitor selects a language, WTranslator remembers their choice so they do not have to select it again on their next visit.

= Does this slow down my website? =

The plugin adds a small script and style bundle. Page HTML is still served by WordPress as usual. Translation work happens in the visitor's browser when they choose another language.

= Can visitors switch back to the original language? =

Yes. The language switcher always shows all available languages, so visitors can switch back and forth.

= Do I need technical knowledge to use this? =

No. Install the plugin, choose your languages, and the language switcher appears on your site.

= Does the plugin send data to WTranslator? =

No. The free plugin does not send site or admin usage data to WTranslator servers. Visitor-side translation uses Google Translate in the browser only.

== External services ==

This plugin uses an external service as described below.

= Google Translate =

This plugin loads the Google Translate Element script from translate.google.com so visitors can view your site in another language. That is how the free visitor-side translation works.

When a frontend page is shown with the language switcher, the plugin loads `https://translate.google.com/translate_a/element.js`. When a visitor selects a language (or auto-detect is enabled), page text is sent to Google so it can be translated in the browser. No page content is sent to WTranslator for this translation.

This service is provided by Google: [Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).

== Screenshots ==

1. Frontend language switcher dropdown with country flags
2. WTranslator settings page with widget options, language grid, and Pro upgrade card
3. Translation analytics dashboard with requests map, graphs, and top languages
4. Language selection grid with native names, flags, and drag-and-drop order
5. Widget preview and advanced options, including auto language detection
6. Translate your site into 248+ languages with a global language picker

== Changelog ==

= 1.0.19 =
* Activation no longer overwrites existing WTranslator settings on deactivate/reactivate.
* Renamed the plugin display name to WTranslator.

= 1.0.18 =
* Updated "Tested up to" to WordPress 7.1.

= 1.0.17 =
* Removed optional usage sharing and all calls to app.wtranslator.com (including revoke on decline/uninstall).
* Declared Requires PHP: 7.0 for null-coalescing operator compatibility.
* Updated External services documentation to list only Google Translate.

= 1.0.16 =
* Renamed the plugin display name to WTranslator Translation.
* Clarified that the free version uses Google Translate, while paid plans can use AI translation.
* Documented Google Translate and optional WTranslator usage sharing, including terms and privacy links.

= 1.0.15 =
* Updated language count from 100+ to 248+ in the FAQ and screenshot captions.

= 1.0.14 =
* Added the free-feature walkthrough video to the plugin details page.

= 1.0.13 =
* Free version no longer translates the page title or meta description (Pro SEO feature).
* Keeps original SEO title/description while still translating page body content.

= 1.0.12 =
* Updated the setup video on the plugin details page.

= 1.0.11 =
* Remove the External services block from the plugin listing.

= 1.0.10 =
* Remove screenshot caption text from the description. Screenshot images stay in the Screenshots gallery.

= 1.0.9 =
* Show screenshots in the plugin description, above External services.

= 1.0.8 =
* Added plugin screenshots for the language switcher, settings page, language picker, and analytics dashboard.

= 1.0.7 =
* Hide notices from other plugins and themes on the WTranslator settings page.
* Add Settings and Unlock PRO items to the admin menu.
* Point the Buy Now button and Unlock PRO menu to the WTranslator pricing page.

= 1.0.6 =
* Added a Pro upgrade card on the settings page with a Buy Now link to wtranslator.com.
* Replaced the animated admin menu icon with a static translation icon.

= 1.0.5 =
* Expanded the language list to Google Translate’s full 249 languages.
* Improved RTL handling for additional language codes and script variants.
* Hide the Google Translate banner/popup while keeping the custom language switcher.

= 1.0.4 =
* Updated plugin name, directory tags, and Details description.
* Added plugin translations for 70 languages.
* Added animated plugin icon and updated the WordPress.org banner.
* Embedded the setup video on the plugin details page.

= 1.0.3 =
* Improved privacy settings layout and opt-in consent notice button contrast.

= 1.0.2 =
* Optional opt-in usage sharing (off by default) for compatibility improvements.
* Privacy controls added under Settings → WTranslator.
* WordPress.org-friendly disclosure for optional external service.

= 1.0.1 =
* Maintenance update and compatibility improvements.
* Improved language URL handling to keep frontend URLs clean.

= 1.0.0 =
* Initial release
* Support for 100+ languages
* Browser-side translation via Google Translate Element
* Beautiful language switcher (dropdown, flags, flags + name)
* Optional auto-detection of visitor language from browser headers

== Upgrade Notice ==

= 1.0.19 =
Preserves existing settings on reactivation and updates the display name to WTranslator.

= 1.0.18 =
Updates "Tested up to" compatibility to WordPress 7.1.

= 1.0.17 =
Removes all usage-sharing callbacks to WTranslator servers and declares Requires PHP 7.0.

= 1.0.16 =
Updates the display name, clarifies Google Translate vs paid AI translation, and documents external services.

= 1.0.15 =
Updated language count to 248+ in the plugin details FAQ.

= 1.0.14 =
Added the free-feature walkthrough video to the plugin details page.

= 1.0.13 =
Free version keeps the original page title and meta description.

= 1.0.12 =
Updated the setup video on the plugin details page.

= 1.0.11 =
Removes the External services block from the plugin listing.

= 1.0.10 =
Removes screenshot caption text from the plugin description.

= 1.0.9 =
Screenshots now appear in the description, above External services.

= 1.0.8 =
Adds plugin screenshots of the language switcher, settings, and analytics dashboard.

= 1.0.7 =
Hides other plugin notices on the settings page and adds an Unlock PRO menu link.

= 1.0.6 =
Adds a Pro upgrade card on the settings page and a static admin menu icon.

= 1.0.5 =
Adds the full Google Translate language list, better RTL support, and hides the Google Translate banner.

= 1.0.4 =
Updated listing details, 70 language translations, animated icon, and banner.

= 1.0.3 =
UI polish for the optional usage-sharing notice and privacy settings.

= 1.0.2 =
Optional privacy-friendly usage sharing (off by default). Translation still works without it.

= 1.0.1 =
Maintenance release with language URL and compatibility improvements.

= 1.0.0 =
Welcome to WTranslator! Make your website speak every language with just a few clicks.
