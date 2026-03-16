# PolyTrans

Contributors: jmarianski
Tags: translation, multilingual, ai, openai, polylang
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.9.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress translation plugin with bring-your-own AI. Pay only for tokens, not subscriptions.

## Description

Connect your own AI provider (OpenAI, Claude, Gemini) — pay only for tokens consumed, not $200–800+/year in plugin subscriptions. Translation orchestration with post-processing workflows, multi-server architecture, and review management.

**The problem:** WordPress translation plugins like WPML ($299/yr), Weglot ($200–790/yr), and TranslatePress ($99–349/yr) charge flat subscription fees that scale with content volume, not actual usage. With Weglot, you lose all translations if you stop paying.

**PolyTrans takes a different approach:** bring your own AI account. The plugin connects to any AI provider you already use — you pay only for the tokens you actually consume. No vendor lock-in, no recurring license fees, and you always own your translations.

### Why PolyTrans?

| | WPML | Weglot | TranslatePress | PolyTrans |
|---|---|---|---|---|
| **Annual cost** | $299+ | $200–790+ | $99–349+ | Free (pay only AI tokens) |
| **Own your translations** | Yes | No (SaaS-hosted) | Yes | Yes |
| **AI provider choice** | None | Built-in only | Built-in only | OpenAI, Claude, Gemini |
| **Post-processing workflows** | No | No | No | Yes (multi-step AI chains) |
| **Multi-server support** | No | No | No | Yes (sender/translator/receiver) |
| **Open source** | No | No | Freemium | Yes |

### Features

* **Translation Scheduler** — schedule automatic translations to multiple languages from the post editor
* **Multiple AI Providers** — OpenAI, Anthropic Claude, Google Gemini
* **Post-Processing Workflows** — multi-step AI chains for content enhancement after translation
* **Multi-Server Architecture** — distribute translation work across sender/translator/receiver servers
* **Review Workflow** — assign reviewers, email notifications, translation status tracking
* **Tag Translation Management** — manage multilingual taxonomy translations with CSV import/export
* **Polylang Integration** — full compatibility with Polylang for language management
* **REST API** — receive translations from external services via webhook endpoints

### External Services

This plugin connects to external AI translation services **only when explicitly configured by the user**. No data is sent to any external service unless the user provides their own API key and initiates a translation. The plugin does not collect any user data or track usage.

**OpenAI API**
Used when the user selects OpenAI as their translation provider and provides an API key.
Post content (title, body, excerpt, SEO metadata) is sent for translation.

* Service: [https://openai.com](https://openai.com)
* Terms of Use: [https://openai.com/policies/terms-of-use](https://openai.com/policies/terms-of-use)
* Privacy Policy: [https://openai.com/policies/privacy-policy](https://openai.com/policies/privacy-policy)

**Anthropic Claude API**
Used when the user selects Claude as their translation provider and provides an API key.
Post content is sent for translation.

* Service: [https://www.anthropic.com](https://www.anthropic.com)
* Terms of Use: [https://www.anthropic.com/legal/consumer-terms](https://www.anthropic.com/legal/consumer-terms)
* Privacy Policy: [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)

**Google Gemini API**
Used when the user selects Gemini as their translation provider and provides an API key.
Post content is sent for translation.

* Service: [https://ai.google.dev](https://ai.google.dev)
* Terms of Service: [https://ai.google.dev/gemini-api/terms](https://ai.google.dev/gemini-api/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

## Installation

1. Upload the `polytrans` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **PolyTrans > Settings** to configure your translation provider
4. Install and activate [Polylang](https://wordpress.org/plugins/polylang/) (recommended) for full multilingual support

### Requirements

* WordPress 5.0 or higher
* PHP 8.1 or higher
* Polylang plugin (recommended, for language management)
* An API key for your chosen AI provider (OpenAI, Claude, or Gemini)

## Frequently Asked Questions

### Do I need an API key?

Yes. You need to provide your own API key from your chosen AI provider (OpenAI, Claude, or Gemini).

### Does this plugin require Polylang?

Polylang is strongly recommended. Without it, the plugin falls back to a limited hardcoded language list and cannot manage translation relationships between posts.

### How much does it cost to translate content?

The plugin itself is free. You only pay for the tokens consumed by your AI provider. For example, translating a typical blog post with OpenAI GPT-4o costs approximately $0.01–0.05 depending on length.

### Can I use multiple AI providers?

Yes. You can configure different providers for different tasks — for example, OpenAI for translation and Claude for post-processing workflows.

### What is the multi-server architecture?

PolyTrans can distribute translation work across multiple WordPress installations. One server schedules translations (sender), another performs the translation (translator), and a third receives the result (receiver). This is useful for high-volume sites. Single-server mode is the default and works for most use cases.

### Does this plugin work with the block editor (Gutenberg)?

Yes, fully compatible with both the block editor and the classic editor.

### What data is sent to AI providers?

Only the post content you choose to translate: title, body, excerpt, and optionally SEO metadata (Yoast SEO / RankMath fields). No personal user data is sent.

## Screenshots

1. Translation Scheduler in the post editor
2. Translation Settings page with provider configuration
3. Post-Processing Workflows editor
4. AI Assistants management
5. Translation Logs viewer

## Changelog

### 1.9.0
* WordPress Plugin Check compliance: resolved all security issues across 45 files
* Output escaping, input sanitization, SQL prepare, nonce verification, i18n fixes
* Plugin Check result: 0 errors

### 1.8.9
* Detach translation feature for unlinking posts from translation source
* Plugin headers: added GPLv2+ license declaration
* CI/CD: release artifacts now uploaded to GitLab Package Registry

### 1.8.8
* Show/Hide toggle for secret fields in Advanced settings
* Immediate dispatch mode for external translations
* Same-database architecture mode for shared-DB setups

### 1.8.7
* Translation Path Executor for multi-step language routing
* Claude and Gemini AI provider support
* Workflow conditions system

For the full changelog, see [CHANGELOG.md](https://gitlab.com/treetank/polytrans/-/blob/main/CHANGELOG.md).

## Upgrade Notice

### 1.9.0
Security hardening release. All output escaping, input sanitization, and nonce verification issues resolved. Recommended update for all users.

## Plugin Check Notes

This plugin passes WordPress Plugin Check with 0 errors. The remaining warnings are intentional:

* **Direct Database Queries** — Custom tables (`polytrans_logs`, `polytrans_assistants`) require direct `$wpdb` queries. `WP_Query` does not apply to custom tables.
* **Interpolated Table Names** — Table names use `$wpdb->prefix . 'polytrans_*'` which cannot be parameterized in SQL. The prefix comes from `wp-config.php` and is trusted.
* **Template Output** — `TemplateRenderer::render()` returns Twig-rendered HTML which auto-escapes all variables by default.

## License

GPLv2 or later — see [LICENSE](https://gitlab.com/treetank/polytrans/-/blob/main/LICENSE) for details.
