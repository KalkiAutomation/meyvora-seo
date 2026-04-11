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

== External services ==

Optional integrations send data to third parties only when you enable a feature and provide credentials (or accept an OAuth prompt):

* **Google (Search Console, Analytics Data API, PageSpeed Insights)** – used for GSC/GA4 reporting and Core Web Vitals checks. See [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy).
* **AI providers (OpenAI-compatible or custom URL)** – meta suggestions and content helpers; API keys stay in the WordPress database and requests are made server-side from your site.
* **DataForSEO** (optional) – keyword and SERP data when you add an API key in settings.

Core SEO features (meta tags, sitemap, redirects, schema, analysis) run entirely on your server without a vendor account.

== Changelog ==

= 2.0.0 =
* Major release: meta box overhaul with tabbed UI (General, Social, Advanced, Score), live snippet preview, character counters, secondary keywords.
* New analysis checks: keyword density, keyword in first H2, paragraph count, sentence length, passive voice, transition words, Flesch Reading Ease, OG image set, schema set. Redistributed weights; total score remains 100.
* Readability module: Flesch, passive voice %, transition words %, average sentence length. Analysis result caching with cache invalidation on save.
* Block Editor: Gutenberg sidebar panel; hide classic meta box when block editor active.
* Elementor: editor script for re-analyze on save and score badge; AJAX endpoint for analysis.
* Beaver Builder, Divi, WPBakery integrations: content extraction for analysis.
* XML Sitemap: full implementation (index, posts, pages, categories, tags, CPTs, images); settings; ping on publish.
* Schema module: Article, WebPage, BreadcrumbList, Organization, WebSite, Product (WooCommerce). Settings for organization and sameAs.
* Redirect manager: DB tables, template_redirect, admin Redirects page with add/list/delete, CSV import/export, 404 monitor.
* Breadcrumbs module: shortcode, template tag, schema; optional CSS.
* WooCommerce: product in supported post types and sitemap; Product schema.
* Admin bar: SEO score on frontend for singular posts/pages.
* Filterable post types; get_public_post_types(); product support when WooCommerce active.
* Upgrade routine: create redirect tables and flush rewrite rules when upgrading from &lt; 2.0.0.
* Helper: meyvora_seo_clear_analysis_cache( $post_id ).

== Upgrade Notice ==

= 2.0.0 =
Upgrade to get tabbed meta box, new analysis checks, readability, XML sitemap, schema, redirect manager, breadcrumbs, Block Editor sidebar, and WooCommerce support. Redirect and 404 tables are created automatically.
