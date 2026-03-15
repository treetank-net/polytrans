# Provider Capabilities Guide

## Overview

PolyTrans distinguishes between three types of provider capabilities:

1. **`translation`** - Direct translation API (e.g., dedicated translation services)
2. **`chat`** - Chat/completion API (all AI models, can be used for managed assistants)
3. **`assistants`** - Dedicated Assistants API (e.g., OpenAI Assistants, Claude Projects, Gemini Tuned Models)

## Capability Types

### `translation`

Providers with `translation` capability offer direct translation APIs that translate content without requiring AI models.

**Characteristics:**
- Fast, direct translation
- No AI model selection needed
- Usually free or very cheap
- Limited customization

**Use Cases:**
- Quick translations
- High-volume translations
- Simple content translation

### `chat`

Providers with `chat` capability offer chat/completion APIs that can be used for:
- Direct translation (via prompts)
- Managed assistants (via system prompts)
- Custom AI workflows

**Examples:**
- DeepSeek - Chat API only (no Assistants API)
- Qwen - Chat API only
- OpenAI - Chat API (in addition to Assistants API)
- Claude - Chat API (in addition to Projects API)

**Characteristics:**
- Flexible, can be used for various tasks
- Requires system/user prompts
- Can be used for managed assistants (with system prompt)
- Cannot load predefined assistants (no Assistants API)

**Use Cases:**
- Managed assistants (with custom system prompts)
- Custom translation workflows
- Content generation
- Post-processing

### `assistants`

Providers with `assistants` capability offer dedicated Assistants APIs that allow:
- Predefined assistants (stored on provider's servers)
- Assistant management (create, update, delete)
- Thread/conversation management

**Examples:**
- OpenAI - Assistants API (`asst_xxx` IDs)
- Claude - Projects API (`project_xxx` IDs)
- Gemini - Tuned Models (`tuned_model_xxx` IDs)

**Characteristics:**
- Predefined assistants can be loaded and used
- Assistants stored on provider's servers
- Thread/conversation management
- Can also be used for managed assistants (via chat API)

**Use Cases:**
- Predefined assistants in workflows
- Predefined assistants in translation paths
- Managed assistants (via chat API with system prompt)

## Provider Examples

### DeepSeek
```php
'capabilities' => ['chat']
```
- Chat API only
- Can be used for managed assistants (with system prompt)
- Cannot load predefined assistants (no Assistants API)

### OpenAI
```php
'capabilities' => ['assistants', 'chat']
```
- Has Assistants API (`asst_xxx`)
- Also has Chat API
- Can load predefined assistants
- Can be used for managed assistants

### Claude
```php
'capabilities' => ['assistants', 'chat']
```
- Has Projects API (`project_xxx`)
- Also has Chat API
- Can load predefined assistants
- Can be used for managed assistants

## Implementation Guidelines

### For Providers with `chat` Only

```php
public function get_provider_manifest(array $settings)
{
    return [
        'provider_id' => 'deepseek',
        'capabilities' => ['chat'],
        'chat_endpoint' => 'https://api.deepseek.com/v1/chat/completions',
        'models_endpoint' => 'https://api.deepseek.com/v1/models',
        // No assistants_endpoint (no Assistants API)
    ];
}

public function load_assistants(array $settings): array
{
    return []; // No predefined assistants, but can be used for managed assistants
}
```

### For Providers with `assistants` + `chat`

```php
public function get_provider_manifest(array $settings)
{
    return [
        'provider_id' => 'openai',
        'capabilities' => ['assistants', 'chat'],
        'assistants_endpoint' => 'https://api.openai.com/v1/assistants',
        'chat_endpoint' => 'https://api.openai.com/v1/chat/completions',
        'models_endpoint' => 'https://api.openai.com/v1/models',
    ];
}

public function load_assistants(array $settings): array
{
    // Load predefined assistants from Assistants API
    // ...
}
```

## How PolyTrans Uses Capabilities

### Translation Paths

- Providers with `translation` capability can be used directly in paths
- Providers with `chat` capability can be used via managed assistants
- Providers with `assistants` capability can be used via predefined assistants or managed assistants

### Managed Assistants

- Can use providers with `chat` capability (via system prompt)
- Can use providers with `assistants` capability (via chat API with system prompt)
- Cannot use providers with only `translation` capability

### Predefined Assistants (Workflow/Paths)

- Only providers with `assistants` capability can load predefined assistants
- Providers with only `chat` capability cannot load predefined assistants
- Providers with only `translation` capability cannot load predefined assistants

## Best Practices

1. **Be honest about capabilities** - Don't claim `assistants` capability if your provider doesn't have an Assistants API
2. **Use `chat` for AI models** - If your provider has chat/completion API, use `chat` capability
3. **Use `assistants` only if available** - Only declare `assistants` if your provider has a dedicated Assistants API
4. **Return empty array for `load_assistants()`** - If provider doesn't have `assistants` capability, return empty array
5. **Document your capabilities** - Clearly document what your provider can and cannot do

## FAQ

**Q: Can a provider with only `chat` capability be used in translation paths?**
A: Yes, via managed assistants (with system prompt). The managed assistant will use the chat API.

**Q: Can a provider with only `chat` capability load predefined assistants?**
A: No, only providers with `assistants` capability can load predefined assistants.

**Q: Can a provider with `assistants` capability also be used for managed assistants?**
A: Yes, it can use the chat API with system prompt for managed assistants.

**Q: What's the difference between `chat` and `assistants`?**
A: `chat` is a general chat/completion API. `assistants` is a dedicated API for managing predefined assistants stored on the provider's servers.

