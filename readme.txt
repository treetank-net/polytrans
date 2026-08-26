=== TreeTank Translation Workflows ===
Contributors: jmarianski
Tags: translation, multilingual, ai, openai, polylang
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.20.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate posts with your own OpenAI, Claude or Gemini account. Scheduling, AI post-processing workflows and a review flow, built on Polylang.

== Description ==

TreeTank Translation Workflows manages multilingual content by sending your posts to an AI provider you already have an account with. You supply the API key, the provider bills you for the tokens the translation consumed, and the translations live in your own database as ordinary WordPress posts.

The plugin does not translate anything by itself and does not include any translation service. Nothing leaves your site until you have configured a provider and asked for a translation.

**What it does**

* **Translation scheduler** — request translations into several languages straight from the post editor, immediately or in the background.
* **Three providers** — OpenAI, Anthropic Claude and Google Gemini. Each is configured with your own API key.
* **Translation paths** — route a language pair through an intermediate language (for example `pl → en → de`) when that produces better results than translating directly.
* **Post-processing workflows** — chain further AI steps after a translation completes: rewrite an excerpt, generate SEO fields, adjust tone. Each step's output is mapped to a post field you choose.
* **AI cost accounting** — every call is recorded with its token breakdown and an estimated cost, per post, per language, per model and per workflow, on a dashboard and in a post editor panel.
* **Review workflow** — assign a reviewer per language, notify them by email, and track translation status on the original post.
* **Taxonomy translation** — manage translated categories and tags, with CSV import and export.
* **Polylang integration** — languages, translation relationships and term translations are read from Polylang.
* **Multi-server mode** — optionally split the work across WordPress installations you control: one schedules, one translates, one receives the result.

**What you need**

* Polylang (strongly recommended — without it the plugin falls back to a limited language list and cannot link a translation to its original).
* An API key for OpenAI, Anthropic or Google, depending on which provider you choose.

**Trademarks and affiliation**

This plugin is an independent project. It is not affiliated with, endorsed by or sponsored by the Polylang project or WP SYNTEX, nor by OpenAI, Anthropic or Google. Those names are used only to describe which software the plugin reads from and which providers it can be configured to call. All trademarks belong to their respective owners.

== External services ==

This plugin sends content to an external AI provider in order to translate it. No request is made until a site administrator has entered an API key and a translation has been requested, either manually or by a rule that administrator configured. The plugin itself collects no analytics, sends no telemetry and contacts no service of its own.

**What is sent, and when**

When a translation or a post-processing step runs, the plugin sends the content being translated to the provider selected in the settings: post title, body and excerpt, and — only if you enable it — SEO fields from Yoast SEO or RankMath. Nothing else from your database is transmitted; no user accounts, no email addresses, no visitor data.

**OpenAI**

Used when OpenAI is the selected provider. Requests go to api.openai.com.

* Service: https://openai.com
* Terms of use: https://openai.com/policies/terms-of-use
* Privacy policy: https://openai.com/policies/privacy-policy

**Anthropic (Claude)**

Used when Claude is the selected provider. Requests go to api.anthropic.com.

* Service: https://www.anthropic.com
* Terms of use: https://www.anthropic.com/legal/consumer-terms
* Privacy policy: https://www.anthropic.com/legal/privacy

**Google (Gemini)**

Used when Gemini is the selected provider. Requests go to generativelanguage.googleapis.com.

* Service: https://ai.google.dev
* Terms of service: https://ai.google.dev/gemini-api/terms
* Privacy policy: https://policies.google.com/privacy

**OpenRouter (price list only)**

To show an estimated cost per call, the plugin fetches a public model price list from openrouter.ai once and caches it. This request carries no API key, no post content and no site identifier — it is an anonymous read of a public catalogue. No provider publishes prices through its own API, which is why a separate source is used. Cost estimation is the only feature affected if the request fails.

* Service: https://openrouter.ai
* Terms of service: https://openrouter.ai/terms
* Privacy policy: https://openrouter.ai/privacy

**Translation endpoints you configure yourself (multi-server mode)**

If you enable multi-server mode, post content is sent to the WordPress REST endpoints you enter in the settings. Those URLs are entirely yours; no third party is involved. This mode is off by default.

== Installation ==

1. Upload the `treetank-trans` folder to `/wp-content/plugins/`, or install the ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu.
3. Install and activate [Polylang](https://wordpress.org/plugins/polylang/) and define your languages.
4. Go to **TreeTank → Settings**, choose a provider and enter your own API key.
5. Open any post and use the **Translation Scheduler** panel to request a translation.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. The plugin has no translation service of its own. You use your own account with OpenAI, Anthropic or Google, and that provider bills you directly.

= What does a translation cost? =

The plugin is free; you pay only your provider's token charges. A typical blog post costs on the order of a few cents with a small model. The AI Costs dashboard records what each translation actually cost, so you are not estimating from guesswork.

= Is Polylang required? =

It is strongly recommended. Polylang provides the languages and the relationship between an original and its translation. Without it the plugin falls back to a limited built-in language list and cannot link translations to their originals.

= Can I use different providers for different tasks? =

Yes. Translation and each post-processing step can use a different provider and model.

= Does it work with the block editor? =

Yes, with both the block editor and the classic editor.

= What is multi-server mode? =

An optional setup where translation work is distributed across several WordPress installations you control — one schedules the work, one performs it, one receives the result. It is off by default and single-server mode suits most sites.

= Can the REST endpoints accept unauthenticated requests? =

Only if the site owner deliberately enables it in `wp-config.php` with `define('TREETANK_TRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS', true);`. This exists for multi-server setups on an internal network, where the translator and the receiver are only reachable from fixed addresses. Without that constant both endpoints require the shared secret configured under TreeTank → Settings → Advanced, and a request without a valid secret is rejected. Use the IP allow-list on the same screen as a second condition.

= Which data is sent to the AI provider? =

Only the content being translated: title, body, excerpt, and SEO metadata if you enable that. See the External services section above.

== Screenshots ==

1. Translation Scheduler in the post editor
2. Settings page with provider configuration
3. Post-processing workflow editor
4. AI assistant management
5. AI Costs dashboard
6. Translation log viewer

== Changelog ==

= 1.19.2 =
* Maintenance release: hardened the shell-runner CI workspace and added a regression guard for Docker permissions. No runtime behavior changed.

= 1.19.1 =
* Added a translation-run ledger grouping relay hops and workflow calls into one process per article and target language.
* Added source-text metrics and cost-per-1,000-characters projections to the AI Costs dashboard.
* Direct provider calls are now recorded in the usage ledger as well as configured path calls.

= 1.18.0 =
* Relayed translations are recorded per hop, with the language read, the language produced and the language the request was for.
* Fixed translations recording no model, which left every one of them unpriced.
* Fixed translations inheriting the original's translation state, which caused successful translations to be marked failed after 24 hours.

= 1.17.0 =
* Added token and cost accounting for every AI call, with an AI Costs dashboard and a per-post panel.

For the complete history see CHANGELOG.md in the plugin folder.

== Upgrade Notice ==

= 1.19.2 =
Maintenance release; no runtime behavior changed.

= 1.19.1 =
Adds per-process cost reporting. The AI Costs dashboard changed its period parameters; saved links using the old `?days=` parameter fall back to the default period.
