# PolyTrans Configuration

## Translation Providers

### OpenAI
**Settings → Translation Settings** → Select "OpenAI"

1. Get API key from [platform.openai.com](https://platform.openai.com/)
2. Enter API key (starts with `sk-`)
3. Click "Validate" to test API key
4. Select model (e.g., `gpt-4o-mini`)
5. Map assistants to language pairs (optional, for advanced paths):
   - Format: `en_to_es` → `asst_abc123`
   - Create assistants with translation-specific prompts
6. Save Changes

### Claude (Anthropic)
**Settings → Translation Settings** → Select "Claude"

1. Get API key from [console.anthropic.com](https://console.anthropic.com/)
2. Enter API key
3. Click "Validate" to test API key
4. Select model (e.g., `claude-3-5-sonnet-20241022`)
5. Save Changes

**Note**: Claude can be used for managed assistants in workflows. See [AI Assistants Guide](../user-guide/ASSISTANTS.md).

### Gemini (Google)
**Settings → Translation Settings** → Select "Gemini"

1. Get API key from [makersuite.google.com](https://makersuite.google.com/app/apikey)
2. Enter API key
3. Click "Validate" to test API key
4. Select model (e.g., `gemini-pro`)
5. Save Changes

**Note**: Gemini can be used for managed assistants in workflows. See [AI Assistants Guide](../user-guide/ASSISTANTS.md).

## Languages

**With Polylang:** Languages detected automatically from Polylang settings

**Without Polylang:** Configure manually:
- Source languages: `en,es,fr`
- Target languages: `es,fr,de,pl`

**Language codes:** Use ISO 639-1 (en, es, fr, de, pl, it, pt, nl, sv, etc.)

## Translation Settings

### Post Status
Set status for translated posts:
- `publish` - Auto-publish
- `draft` - Require manual publishing  
- `pending` - Pending review
- `same_as_source` - Match original

### Review Process
- Enable review assignments
- Set default reviewers per language
- Email notifications for reviewers

## Notifications

**PolyTrans → Email Settings**

Configure emails for:
- Translation completion
- Review requests
- Translation errors

Variables available:
- `{post_title}` - Post title
- `{source_language}` - Source language
- `{target_language}` - Target language  
- `{post_link}` - Link to post

## Security

### API Access
**Settings → API Settings**

1. Enable REST API access
2. Generate application keys
3. Set permissions:
   - Read-only
   - Translate posts
   - Full access

### Authentication Methods
- Bearer tokens (recommended)
- Custom headers (`X-PolyTrans-Key`)
- Basic auth (not recommended)

## Performance

### Background Processing
- Max concurrent translations: Default 3
- Timeout: 300 seconds
- Retry failed translations: Yes (3 attempts)

### Rate Limiting
- OpenAI: Respects API rate limits automatically
- Claude/Gemini: Respects API rate limits automatically

## Workflows

See [WORKFLOW-TRIGGERING.md](WORKFLOW-TRIGGERING.md) and [WORKFLOW-LOGGING.md](WORKFLOW-LOGGING.md)

## Advanced

### Constants (wp-config.php)
```php
define('POLYTRANS_DEBUG', true);          // Enable debug logging
define('POLYTRANS_MAX_CONCURRENT', 5);    // Max concurrent translations
define('POLYTRANS_TIMEOUT', 600);         // Timeout in seconds
```

### Hooks
```php
// Modify translation before sending
add_filter('polytrans_pre_translate', function($content, $lang) {
    return $content;
}, 10, 2);

// After translation received
add_action('polytrans_translation_complete', function($post_id, $lang) {
    // Custom logic
}, 10, 2);
```

See [Hooks and Filters](../developer/HOOKS_AND_FILTERS.md) for complete hook reference.
