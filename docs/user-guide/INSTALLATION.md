# Installation

## Requirements

- WordPress 5.0+
- PHP 8.1+
- Polylang plugin (recommended)

## Install

1. Upload `polytrans/` to `/wp-content/plugins/`
2. Activate via **Plugins** menu
3. Go to **Settings → Translation Settings**
4. Configure your translation provider

## Quick Setup

### With OpenAI (Recommended)
1. Get API key from [platform.openai.com](https://platform.openai.com/)
2. **Settings → Translation Settings**
3. Provider: Select "OpenAI"
4. Enter API key
5. Click "Validate" to test API key
6. Create OpenAI assistants for each language pair (optional, for advanced paths)
7. Map assistants (e.g., `en_to_es` → `asst_abc123`) (optional)
8. Save Changes

### With Claude (Anthropic)
1. Get API key from [console.anthropic.com](https://console.anthropic.com/)
2. **Settings → Translation Settings**
3. Provider: Select "Claude"
4. Enter API key
5. Click "Validate" to test API key
6. Select model (e.g., `claude-3-5-sonnet-20241022`)
7. Save Changes

**Note**: Claude can be used for managed assistants in workflows. See [AI Assistants Guide](ASSISTANTS.md) for details.

### With Gemini (Google)
1. Get API key from [makersuite.google.com](https://makersuite.google.com/app/apikey)
2. **Settings → Translation Settings**
3. Provider: Select "Gemini"
4. Enter API key
5. Click "Validate" to test API key
6. Select model (e.g., `gemini-pro`)
7. Save Changes

**Note**: Gemini can be used for managed assistants in workflows. See [AI Assistants Guide](ASSISTANTS.md) for details.

## Configure Languages

**With Polylang:**
- Languages auto-detected from Polylang

**Without Polylang:**
- **Settings → Translation Settings**
- Set source/target languages: `en,es,fr,de`

## First Translation

1. Edit any post
2. Scroll to "Translation Scheduler" meta box
3. Select target language(s)
4. Click "Translate"
5. Check **PolyTrans → Translation Logs** for progress

## Troubleshooting

**No meta box showing:**
- Ensure plugin is activated
- Check user has `manage_options` capability
- Clear cache

**Translation fails:**
- Check **PolyTrans → Translation Logs** for errors
- Verify API key (for OpenAI)
- Check language configuration

**Polylang not detected:**
- Ensure Polylang is activated
- Check Polylang languages are configured

See [FAQ.md](FAQ.md) for more help.
