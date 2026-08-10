# PolyTrans

WordPress translation management that runs on your own AI provider account. You supply the
API key, your provider bills you for the tokens a translation consumed, and the translations
stay in your database as ordinary WordPress posts.

> The readme published to the WordPress.org plugin directory is [`readme.txt`](readme.txt).
> That file owns the plugin headers (version, tested-up-to, stable tag); this one is the
> repository readme and deliberately carries none, so the two cannot drift apart.

## Description

The plugin has no translation service of its own. It orchestrates: it decides what to send,
to which provider, through which chain of languages, what to do with the result, and what
the whole thing cost. Nothing leaves the site until a provider is configured and a
translation is requested.

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
Post content (title, body, excerpt, SEO metadata) is sent for translation.

* Service: [https://www.anthropic.com](https://www.anthropic.com)
* Terms of Use: [https://www.anthropic.com/legal/consumer-terms](https://www.anthropic.com/legal/consumer-terms)
* Privacy Policy: [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)

**Google Gemini API**
Used when the user selects Gemini as their translation provider and provides an API key.
Post content (title, body, excerpt, SEO metadata) is sent for translation.

* Service: [https://ai.google.dev](https://ai.google.dev)
* Terms of Service: [https://ai.google.dev/gemini-api/terms](https://ai.google.dev/gemini-api/terms)
* Privacy Policy: [https://policies.google.com/privacy](https://policies.google.com/privacy)

**User-Configured Translation Endpoints (Multi-Server Mode)**
When multi-server mode is enabled by the administrator, post content (title, body, excerpt, metadata, featured image) is sent to user-specified WordPress REST API endpoints for distributed translation processing. The destination URLs are entirely controlled by the site administrator. No data is sent to any third-party service — communication occurs only between WordPress installations configured by the user.

## Installation

1. Upload the `polytrans` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **PolyTrans > Settings** to configure your translation provider
4. Install and activate [Polylang](https://wordpress.org/plugins/polylang/) (recommended) for full multilingual support

### Requirements

* WordPress 6.0 or higher
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

[CHANGELOG.md](CHANGELOG.md) is the full history. The directory-facing excerpt lives in
[`readme.txt`](readme.txt); this file keeps no copy of it, to avoid a third version to
forget about.

## Plugin Check Notes

How to reproduce the Plugin Check run locally: [`docs/development/plugin-check.md`](docs/development/plugin-check.md).
The remaining warnings are intentional:

* **Direct Database Queries** — the plugin owns four custom tables (`polytrans_logs`,
  `polytrans_workflows`, `polytrans_assistants`, `polytrans_usage`). `WP_Query` does not
  apply to custom tables, so `$wpdb` is used directly.
* **Interpolated Table Names** — table names are built from `$wpdb->prefix`, which comes from
  `wp-config.php` and never from a request, and a table name cannot be a prepared value.
* **Template Output** — `TemplateRenderer::render()` returns HTML that the Twig templates
  escape explicitly, per output, using the `esc_html`/`esc_attr`/`esc_url` functions exposed
  to the templates. Twig's `autoescape` is deliberately off: the same package also renders
  AI prompts, which must not be HTML-escaped.

## License

GPLv2 or later — see [LICENSE](https://gitlab.com/treetank/polytrans/-/blob/main/LICENSE) for details.
