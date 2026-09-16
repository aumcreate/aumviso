=== AumViso – llms.txt, Schema & Sitemaps for AI Search & SEO ===
Contributors: aumcreate
Tags: llms.txt, schema, sitemap, ai search, seo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate llms.txt, Schema and XML sitemaps, manage meta tags and internal links, and see which AI crawlers read your site. SEO and AI search in one.

== Description ==

AumViso is a lightweight, developer-friendly SEO and GEO plugin built for modern WordPress sites. It covers the full SEO stack: meta tags, Open Graph, structured data (Schema.org), XML sitemaps, internal linking, and optional AI-assisted content generation.

**Core Features**

* **Meta Tags**: Custom SEO title and meta description per post, with global template fallback. Supports index/noindex, follow/nofollow, and canonical URL overrides.
* **Open Graph & Social**: OG title, description, and image per post. Fallback to featured image automatically.
* **Schema.org Markup**: Automatic structured data for Articles, FAQPage, HowTo, Product, Review, VideoObject, DefinedTerm, BreadcrumbList, and Organization/LocalBusiness.
* **XML Sitemap**: Auto-generated sitemap index with per post-type and taxonomy sub-sitemaps. Supports image sitemaps and lastmod timestamps.
* **SEO Score**: Real-time SEO analysis panel in the post editor. Checks title length, meta description, keyword density, internal links, image alt tags, and more.
* **AI Tools**: Generate meta descriptions, FAQs, and related questions using OpenAI or DeepSeek. Requires your own API key.
* **Internal Links**: Keyword-to-URL mapping with automatic replacement in post content. Supports max links per page and case sensitivity options. Chinese and multilingual keywords supported.
* **Performance**: Optional removal of emoji scripts, oEmbed, XML-RPC, Heartbeat throttling, and JavaScript deferral.

**GEO Console**

* **Setup**: A guided checklist that walks a new site through the settings that matter, in order.
* **Knowledge Builder**: Draft articles, FAQs and glossary entries from a topic, using your own AI key.
* **Automation**: Schedule content generation on an hourly cron, with per-day and per-run caps.
* **Images**: Generate or fetch a featured image automatically. Supports OpenAI, Alibaba Cloud Model Studio, Zhipu AI, Volcengine Ark, any OpenAI-compatible endpoint you supply, plus Unsplash and Pexels stock search.
* **Optimize Existing**: Batch-fill missing meta descriptions and Open Graph data across posts already on the site, in chunks that will not time out.
* **Brand Entity**: Extend the Organization/LocalBusiness schema with founder, sameAs profiles, service areas and knowledge areas.
* **llms.txt**: Serve `/llms.txt` and `/llms-full.txt` so AI crawlers can discover a structured summary of the site.

**GEO Content Types (Custom Post Types)**

* **FAQ**: Structured Q&A posts with FAQPage Schema, related questions (People Also Ask), and AI-assisted question generation.
* **Glossary**: Term definitions with DefinedTerm Schema, short definition for tooltips, full explanation for AI/GEO context, and automatic internal linking.
* **Guide**: Step-by-step how-to content with HowTo Schema, tools, materials, difficulty, and estimated time.

**Shortcodes**

* `[aum_breadcrumb]`: Breadcrumb navigation with BreadcrumbList Schema
* `[aum_faq category="" count="10" style="accordion"]`: FAQ list (accordion or list style)
* `[aum_glossary count="50"]`: Alphabetical glossary index
* `[aum_guide_steps id=""]`: Step cards for a specific Guide post
* `[aum_related count="4" type="all"]`: Related content widget (all / faq / guide / post)
* `[aum_related_questions id=""]`: People Also Ask module for a FAQ post

**External Services**

This plugin can connect to the third-party services listed below. **None of them are contacted unless you enter your own API key for that service and explicitly trigger an action** - there is no background telemetry, and the plugin sends nothing on its own.

*Text generation* - used when you click an AI generate button for a meta description, FAQ, related question, or article. Sends the post title and a content excerpt, plus your prompt.

* OpenAI - https://api.openai.com - [Terms](https://openai.com/policies/row-terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy)
* DeepSeek - https://api.deepseek.com - [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-terms-of-use.html) | [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)

*Image generation* - used when you ask the Images module to create a featured image. Sends a text prompt derived from the post title or the topic you enter.

* OpenAI Images - https://api.openai.com - [Terms](https://openai.com/policies/row-terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy)
* Alibaba Cloud Model Studio (DashScope) - https://dashscope.aliyuncs.com - [Agreement](https://help.aliyun.com/zh/model-studio/support/agreement) | [Legal](https://www.alibabacloud.com/help/en/legal)
* Zhipu AI (BigModel) - https://open.bigmodel.cn - [Terms](https://open.bigmodel.cn/dev/howuse/rulesofuse) | [Privacy](https://www.zhipuai.cn/privacy)
* Volcengine Ark - https://ark.cn-beijing.volces.com - [Docs](https://www.volcengine.com/docs/82379) | [Privacy](https://www.volcengine.com/legal/privacy)
* Any OpenAI-compatible endpoint you enter yourself. Nothing is sent there unless you configure that URL, and its operator's terms are the ones that apply.

*Stock photo search* - used when you choose a stock source for a featured image. Sends only the search keywords.

* Unsplash - https://api.unsplash.com - [Terms](https://unsplash.com/terms) | [Privacy](https://unsplash.com/privacy)
* Pexels - https://api.pexels.com - [Terms](https://www.pexels.com/terms-of-service/) | [Privacy](https://www.pexels.com/privacy-policy/)

Every core SEO feature - meta tags, Schema, sitemaps, internal links, breadcrumbs, the content types - works fully offline with no key and no external connection.

= Source code =

The released source is on GitHub at https://github.com/aumcreate/aumviso — bug reports and pull requests are welcome there.

== Installation ==

1. Upload the `aumviso` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **AumViso > Settings** to configure your site information and preferences
4. Go to **AumViso > Meta Settings** to enable SEO fields for your post types

== Frequently Asked Questions ==

= What is llms.txt and do I need it? =

llms.txt is a plain Markdown text file at the root of your site — yoursite.com/llms.txt — that gives AI assistants a curated map of what the site is about and which pages matter, the way robots.txt and sitemap.xml do for search engines. It is a proposed standard, not something any AI vendor requires yet, and it costs nothing: AumViso builds it from content you already have, so there is no reason not to have one.

= Does AumViso work alongside Yoast SEO, Rank Math or All in One SEO? =

Yes, they can be active together. For llms.txt specifically, here is exactly what happens, based on how each plugin serves the file:

* **Yoast SEO and All in One SEO** write a real llms.txt file into your site's root folder (both features are off by default). A file on disk is served by the web server before WordPress runs, so once either has written one, that file is what visitors and crawlers get and AumViso's is not used. To switch to AumViso's, turn the feature off in that plugin and delete the file.
* **Rank Math** serves llms.txt from WordPress, as AumViso does, on the same hook. AumViso registers earlier in the load order, so with both enabled it is AumViso's llms.txt that is served.

AumViso currently has no switch to hand llms.txt over to another plugin; one is planned. Ask on the support forum if you need it sooner.

= Does llms.txt update automatically when I publish new content? =

Yes. The file is built from your published pages, posts and products and cached; saving any of them clears the cache, so the next request for /llms.txt reflects the change. Nothing is written to disk and there is nothing to regenerate by hand.

= Does this plugin conflict with Yoast SEO or RankMath? =

Yes, running multiple SEO plugins simultaneously will cause duplicate meta tags and Schema output. Disable other SEO plugins before activating AumViso.

= Is an AI API key required? =

No. All core SEO features work without an API key. The AI Tools tab in the post editor requires an OpenAI or DeepSeek key, which you configure in Settings > AI Tools.

= Does the plugin slow down my site? =

No. Meta tags and Schema are output only when needed. The performance settings page lets you further optimize by removing unused WordPress features.

= Where is the XML sitemap? =

After activation, your sitemap index is available at `yourdomain.com/?aumviso-sitemap=index` or `yourdomain.com/sitemap_index.xml` if pretty permalinks are enabled.

= How do I link FAQ posts to articles? =

In the post editor, go to the **Advanced** tab in the AumViso meta box. Under **Linked FAQs**, check the FAQ posts you want to associate. Their content will be injected as FAQPage Schema on that article.

== Screenshots ==

1. The generated /llms.txt as an AI assistant sees it: site name, description, and every page, post, FAQ and glossary entry
2. llms.txt settings: what to include and the site description, served live with no file written
3. Overview: a health check of the site's SEO and GEO content at a glance
4. SEO Score panel in the post editor, with the focus keyword and a live checklist
5. Meta settings: title and description templates, and which content gets SEO fields
6. Schema settings and Organization structured data
7. Internal Links manager: keyword to URL mapping applied automatically in content
8. FAQ content type with the Related Questions (GEO) panel

== Changelog ==

= 2.0.8 =
* Listing title, tags and summary now name what people search for — llms.txt first. No functional change to the plugin.
* A line at the foot of the settings screen linking to the rest of the AumCreate ecosystem.

= 2.0.7 =
* Added: SEO title, meta description and index/noindex fields on the term edit screen for every taxonomy the plugin handles. The front end has read these from term meta all along; until now nothing could write them, so a term renamed after an import kept its old description in search results with nowhere to change it.

= 2.0.6 =
* Renamed for the plugin directory: the listing title now says what the plugin does. No functional change.

= 2.0.5 =
* Fixed: `{post_title}` and `{post_excerpt}` were printed literally in the title tag and the meta description on every context with no single post behind it — post-type archives, the blog page, search results, and date and author archives. Both appear in the shipped default templates, so a visitor's browser tab read `{post_title} | Site Name` and search engines were served the same. Those placeholders now resolve for those contexts.
* Any placeholder this version does not recognise is now removed from the output rather than printed verbatim, and the leftover separator is tidied up with it.
* Fixed: a title template that resolves to nothing no longer replaces the document title with an empty one. WordPress's own title, which is already correct and translated for every archive, is used instead.

= 2.0.4 =
* Crawler visibility is now built in: a Crawlers tab shows which search engines and AI crawlers read your site, how often, and whether each one really is who it claims to be. Thirty-five crawlers are recognised, and identity is checked with forward-confirmed reverse DNS rather than trusting the user agent.
* Added the search engines that own their markets outside the English-speaking web — Baidu, Yandex, Sogou, 360, Shenma, Huawei's PetalBot and Naver. A site in China or Korea previously saw an empty report while its main crawler visited daily.
* Page builders can now be scored properly. A new `aumviso_analyzable_content` filter lets a builder hand over the text it actually renders, so a page whose `post_content` is empty is no longer reported as having no content at all.
* One settings screen instead of two. The advanced console had its own menu entry pointing at the same URL, which meant both interfaces rendered on the same page, one below the other.
* Meta, Schema, Sitemap, Robots.txt and Internal Links are one Technical SEO tab with a single Save button, rather than five tabs a site owner is unlikely to ever need to open.
* Fixed the LocalBusiness address, phone and coordinates being cleared when saving Schema settings on a site whose organisation type does not show those fields.
* Fixed the AI key and model fields appearing even when the WordPress AI Client is selected, directly under a note saying no key is needed.
* Fixed `/sitemap.xml` returning 404 immediately after activation. The rewrite rules are now flushed once the rules exist rather than during activation, when they do not.

= 2.0.3 =
* Fixed a PHP warning printed into the editor when a post's stored FAQ, guide or glossary meta was not in the expected shape. Those fields are now checked for shape rather than emptiness.

= 2.0.2 =
* Added the missing terms-of-use link for DeepSeek, so every third-party service now lists both its terms and its privacy policy.
* Replaced placeholder and example URLs that were not resolvable addresses.

= 2.0.1 =
* AI requests now prefer the WordPress AI Client when a provider is connected at site level; the direct providers remain available for networks the official connectors cannot reach.
* Renamed every post type, taxonomy, meta key and shortcode to a fully prefixed name, with a one-time migration of existing content. Public URLs are unchanged.
* Removed the activation step that wrote a robots.txt file into the site root; robots.txt is served through the WordPress filter, which that file was silently overriding.
* Moved the last inline script into an enqueued file.

= 2.0.0 =
* Added the GEO Console: Setup, Knowledge Builder, Automation, Images, Optimize Existing, Brand Entity and llms.txt.
* Every feature in the plugin is free. There is no activation step and no paid tier.
* Added a Settings link to the plugin row on the Plugins screen.
* Documented every third-party service the plugin can contact.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.5 =
Fixes {post_title} and {post_excerpt} being printed literally in the title tag and meta description on archives, search, and date and author pages. Affects the shipped default templates, so most sites are showing it.

= 2.0.0 =
Adds the GEO Console and makes every feature free. No activation required.
