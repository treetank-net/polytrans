# FAQ & Troubleshooting

## General

**Q: What is PolyTrans?**  
A: WordPress plugin for automated multilingual translation with AI providers (OpenAI, Claude, Gemini).

**Q: Do I need Polylang?**  
A: Recommended but not required. Core features work without it.

**Q: Is it free?**  
A: Plugin is free. You pay only for the AI tokens consumed by your chosen provider.

## Installation

**Q: No meta box showing in post editor?**  
A: Check plugin is activated, user has `manage_options` capability, clear cache.

**Q: Can't see PolyTrans menu?**  
A: Ensure user role has `manage_options` capability.

## Translation

**Q: Translation fails immediately?**  
A: Check Translation Logs for errors. Common causes:
- Invalid API key (OpenAI)
- Language not configured
- Post doesn't exist

**Q: Translation takes forever?**  
A: Background processing can take 1-5 minutes. Check logs for progress.

**Q: How to translate existing posts?**  
A: Edit post → Translation Scheduler meta box → Select languages → Translate.

**Q: Can I retry failed translations?**  
A: Yes, click "Add More Languages" button in meta box, select failed language again.

**Q: Translated post not linked in Polylang?**  
A: PolyTrans automatically creates Polylang relationships. If missing, check logs for errors.

## OpenAI

**Q: What's an "assistant"?**  
A: OpenAI assistant is a configured AI with specific instructions for translation.

**Q: How to create assistants?**  
A: [platform.openai.com](https://platform.openai.com/) → Assistants → Create → Add translation instructions.

**Q: One assistant per language pair?**  
A: Yes. `en_to_es` needs different assistant than `en_to_fr`.

**Q: OpenAI errors?**  
A: Check API key, verify credits balance, ensure assistants exist.

## Workflows

**Q: What are workflows?**  
A: Post-processing automation (SEO optimization, formatting, etc.) run after translation.

**Q: When do workflows run?**  
A: Auto-trigger on translation complete, or manual execution.

**Q: Workflow failed?**  
A: Check logs (PolyTrans → Post-Processing → Logs). Common issues:
- Invalid OpenAI assistant
- Missing context variables
- Syntax errors in configuration

**Q: How to test workflow?**  
A: Edit workflow → Click "Test Workflow" → Enter sample content.

## Features

**Q: Can I translate media/images?**  
A: Featured images are duplicated with translated metadata (alt text, caption, etc.).

**Q: What about categories/tags?**  
A: Taxonomies are mapped via Polylang if translations exist, or copied as-is.

**Q: Can I customize translations before publishing?**  
A: Yes, enable "Review" option. Translated posts save as drafts.

**Q: Bulk translation?**  
A: Not built-in. Use "Regional" scope to translate one post to multiple languages at once.

## Troubleshooting

**Translation stuck "In Progress":**
- Wait 5 minutes (background processing)
- Check logs for errors
- Retry if timeout occurred

**Missing features:**
- Ensure latest version
- Check for plugin conflicts
- Verify all settings configured

**Performance issues:**
- Reduce concurrent translations (default: 3)
- Check server resources
- Enable caching

**API errors:**
- Verify API keys
- Check rate limits
- Review API provider status

## Getting Help

1. Check **Translation Logs** for error messages
2. Review [CONFIGURATION.md](../admin/CONFIGURATION.md)
3. See [ARCHITECTURE.md](../developer/ARCHITECTURE.md) for technical details
4. Check GitHub issues
