=== Meyvora SEO – Smart SEO Toolkit ===

Contributors: kalkiautomation
Tags: seo, meta tags, xml sitemap, schema, redirect
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart SEO toolkit for titles, meta, sitemaps, redirects, schema, Open Graph, and page builder integrations.

== Description ==

Smart SEO toolkit: title/description editor, SEO score, focus keywords, readability, XML sitemap, redirects, schema markup, Open Graph, Twitter Cards, breadcrumbs, bulk editor, AI generation, internal link suggestions, and page builder integrations.

This plugin relies on external services for some optional features (APIs, notifications, and indexing helpers). See the **External Services** section below for each provider, what data is sent, and links to their terms and privacy policies.

== Features ==

* **Real-time SEO analysis** – 20+ checks: focus keyword in title/description/slug/content, keyword density, keyword in first H2, title/description length, content length, H1 count, headings structure, image alt text, internal/external links, paragraph count, sentence length, passive voice, transition words, Flesch Reading Ease, OG image, schema type. Score 0–100 with pass/warning/fail and points. Analysis caching for performance.
* **Readability scoring** – Flesch Reading Ease, average sentence length, passive voice %, transition words %. Separate readability analysis available.
* **Tabbed SEO panel** – General (focus keyword, SEO title, meta description, canonical, secondary keywords, live Google snippet preview), Social (OG/Twitter preview and fields), Advanced (noindex/nofollow/noodp, schema type, breadcrumb title), Score (circular gauge, checklist grouped by problems/warnings/passed).
* **Live snippet preview** – Google-style SERP preview with desktop/mobile toggle; character counters and progress bars for title (30–60) and description (120–160).
* **Block Editor (Gutenberg)** – Sidebar panel with score and checklist; meta box hidden when using block editor. Real-time content subscription for analysis.
* **Classic Editor** – Full meta box with autosave and debounced analysis.
* **Elementor** – Content extracted from Elementor layout for analysis; re-analyze on save; SEO score badge in editor. No runtime dependency.
* **Beaver Builder, Divi, WPBakery** – Content extraction from builder data/shortcodes for accurate analysis.
* **XML Sitemap** – Sitemap index; per-type sitemaps (posts, pages, categories, tags, custom post types); image sitemap; settings for enable/disable, exclude IDs, include noindex option. Ping Google on publish (rate-limited).
* **JSON-LD Schema** – Article, WebPage, BreadcrumbList, Organization, WebSite (with SearchAction), FAQPage, Product (WooCommerce). Settings for organization name, logo, social sameAs. Filter `meyvora_seo_schema_data` for customization.
* **Redirect manager** – 301/302/307/410 redirects; DB table; hit count and last accessed; CSV import/export; cache for performance.
* **404 monitor** – Log 404 URLs with hit count and last seen; view in Redirects tab.
* **Breadcrumbs** – Shortcode `[meyvora_breadcrumbs]`, template tag `meyvora_seo_breadcrumbs()`, schema-ready items. Enable in settings.
* **WooCommerce** – Product post type in meta box and sitemap; Product schema (name, description, image, offers, SKU, availability).
* **Import** – Import from Yoast SEO or Rank Math (post meta mapping); batch processing.
* **Post list** – SEO score column (sortable), focus keyword, optional readability. Bulk “Analyze selected” and score filter (Good/Okay/Poor/No keyword).
* **Admin bar** – SEO score badge on frontend when viewing a singular post/page; link to edit.
* **Per-post-type support** – Filter `meyvora_seo_supported_post_types`; settings for which post types show the SEO panel.
* **Security** – Nonces, capability checks, sanitization and escaping on all inputs/outputs.
* **Free** – No upsells, no account required.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via Plugins > Add New.
2. Activate the plugin via the Plugins screen.
3. Go to Meyvora SEO in the admin menu to configure settings, redirects, and view the SEO Audit.
4. Use the SEO panel on posts and pages (or the Block Editor sidebar) to set focus keyword, title, description, and review the score.

== Source Code ==

The JavaScript and CSS used by Meyvora SEO are maintained and publicly available here:
https://github.com/KalkiAutomation/meyvora-seo

WordPress.org release packages ship these scripts and stylesheets as readable source files (for example under `admin/assets/`, `assets/`, `blocks/`, and `integrations/assets/`). They are the same kinds of sources you find in that repository—they are not an opaque compiled-only artifact in the current workflow.

If you work from Git, clone the repository into `wp-content/plugins/meyvora-seo`, edit assets there, test in WordPress as usual, and open a pull request on GitHub.

== External Services ==

This plugin connects to the following third-party services. By using the relevant features, you agree to their respective terms and privacy policies.

**DataForSEO**
Used for: keyword research, search volume data, competitor page analysis, and ranked keyword data.
Data sent: keyword queries, competitor URLs, your DataForSEO API credentials.
Triggered when: you use the Keyword Research, AI SEO, or Competitor Analysis features.
Terms of Service: https://dataforseo.com/terms-and-conditions
Privacy Policy: https://dataforseo.com/privacy-policy

**OpenAI**
Used for: AI-powered SEO content suggestions and analysis (including optional image alt-text generation when configured).
Data sent: page content, keywords, and prompts you provide to the AI features.
Triggered when: you use any AI content feature in the plugin.
Terms of Service: https://openai.com/policies/terms-of-use
Privacy Policy: https://openai.com/policies/privacy-policy

If you choose a **custom OpenAI-compatible API endpoint** in settings, requests are sent to the host and URL you configure; that provider’s terms and privacy policy apply instead of (or in addition to) OpenAI’s.

**IndexNow**
Used for: notifying search engines of new or updated content for faster indexing.
Data sent: URLs of published or updated posts/pages on your site.
Triggered when: you publish or update a post/page (only if IndexNow is enabled in settings).
Terms of Service: https://www.indexnow.org/faq
Privacy Policy: https://www.indexnow.org/faq

**Google Search Console (GSC)**
Used for: retrieving keyword and performance data for your site.
Data sent: OAuth credentials and your site's Search Console property URL.
Triggered when: you connect your Google account in the GSC settings.
Terms of Service: https://developers.google.com/terms
Privacy Policy: https://policies.google.com/privacy

**Google Sitemaps Ping**
Used for: notifying Google of updated sitemaps.
Data sent: your sitemap URL.
Triggered when: you publish or update content (only if sitemap ping is enabled in settings).
Terms of Service: https://policies.google.com/terms
Privacy Policy: https://policies.google.com/privacy

**Google PageSpeed Insights API**
Used for: Core Web Vitals and PageSpeed performance data in the admin.
Data sent: the page URL being tested and, if you add one, your Google PageSpeed API key.
Triggered when: you run a Core Web Vitals / PageSpeed check from the plugin.
Terms of Service: https://developers.google.com/terms
Privacy Policy: https://policies.google.com/privacy

**Slack (Incoming Webhooks)**
Used for: sending notification messages to a Slack workspace you configure.
Data sent: message payloads to your webhook URL (commonly on hooks.slack.com).
Triggered when: you enable Slack notifications and an event sends a message, or you send a test from settings.
Terms of Service: https://slack.com/terms-of-service
Privacy Policy: https://slack.com/trust/privacy/privacy-policy

**OpenStreetMap Nominatim (geocoding)**
Used for: turning your local business address into latitude and longitude for Local SEO / schema fields.
Data sent: the address text you enter (request is made from your browser to Nominatim).
Triggered when: you use the “lookup coordinates from address” control in Meyvora SEO settings.
Terms of Service / usage policy: https://operations.osmfoundation.org/policies/nominatim/
Privacy Policy: https://wiki.openstreetmap.org/wiki/Privacy_policy

**User-specified URLs (HTTP requests)**
Used for: loading a competitor page for analysis, checking whether outbound links respond, and similar tools.
Data sent: standard HTTP requests only to URLs you enter or that appear in your content (for example competitor pages or link targets). No fixed third-party vendor is used for these requests beyond the site you target.
Triggered when: you use competitor analysis, the link checker, or related features against those URLs.
Terms of Service: varies by each destination website.
Privacy Policy: varies by each destination website.

Visitors’ browsers may also load **Google Analytics 4 / gtag.js** from Google when you enable GA4 measurement ID output in settings; see Google’s terms and privacy policy linked above.

Core SEO features (meta tags, sitemap generation, redirects, schema, on-server analysis without APIs) run on your WordPress installation without contacting these services unless you enable the relevant options.

== Changelog ==

= 1.0.0 =
* Initial WordPress.org release: tabbed SEO panel (General, Social, Advanced, Score), live snippet preview, character counters, secondary keywords.
* SEO analysis: 20+ checks including keyword density, first H2, readability (Flesch, passive voice, transition words), OG/schema checks; score 0–100 with caching.
* Readability module with cache invalidation on save.
* Block Editor sidebar panel; classic meta box when the block editor is not used.
* Page builder content extraction for Elementor, Beaver Builder, Divi, and WPBakery.
* XML sitemap (index, posts, pages, taxonomies, CPTs, images), settings, optional ping on publish.
* JSON-LD schema (Article, WebPage, BreadcrumbList, Organization, WebSite, FAQ, Product for WooCommerce), organization settings.
* Redirect manager (DB-backed), CSV import/export, 404 monitor.
* Breadcrumbs shortcode, template tag, and schema.
* WooCommerce product SEO and Product schema where applicable.
* Admin bar SEO score on singular content; filterable post types.
* On activation/update, required database tables are created and rewrite rules are flushed when needed.
* Developer helper: `meyvora_seo_clear_analysis_cache( $post_id )`.

== Upgrade Notice ==

= 1.0.0 =
First public release. After activation, visit the plugin settings and run through the SEO panel on your content. Redirect and related tables are created automatically when you use those features.
