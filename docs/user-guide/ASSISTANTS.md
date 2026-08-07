# AI Assistants Management System - User Guide

**Phase 1: Complete Implementation**

## Overview

The AI Assistants Management System provides centralized management of AI assistants for content processing, translation enhancement, and workflow automation. Configure assistants once and reuse them across multiple workflows.

## Table of Contents

1. [Features](#features)
2. [Getting Started](#getting-started)
3. [Creating an Assistant](#creating-an-assistant)
4. [Using Assistants in Workflows](#using-assistants-in-workflows)
5. [Testing Assistants](#testing-assistants)
6. [Best Practices](#best-practices)
7. [Troubleshooting](#troubleshooting)

## Features

### ✨ Centralized Management
- Configure assistants once in **PolyTrans > AI Assistants**
- Reuse the same assistant across multiple workflows
- Update assistant prompts without editing workflows

### 🤖 Multi-Provider Support
- **OpenAI** (GPT-4, GPT-3.5-turbo, etc.) - fully implemented
- **Claude** (Anthropic) - placeholder for future implementation
- **Gemini** (Google) - placeholder for future implementation

### 📝 Twig Template Engine
- Powerful variable interpolation: `{{ title }}`, `{{ content }}`, `{{ original.title }}`
- Conditional logic: `{% if translated.title %}...{% endif %}`
- Loops: `{% for item in items %}...{% endfor %}`
- Filters: `{{ content|length }}`, `{{ title|upper }}`

### 🎯 Response Formats
- **Text**: Simple text responses
- **JSON**: Structured data with validation

### 🧪 Testing
- Test assistants with sample variables before using in production
- See actual API responses and token usage
- Validate JSON responses

## Getting Started

### Prerequisites

1. **OpenAI API Key** (for OpenAI assistants)
   - Configure in **PolyTrans > Settings > OpenAI Configuration**
   - Get your API key from [OpenAI Platform](https://platform.openai.com/api-keys)

2. **Basic Understanding of Twig Syntax**
   - Variables: `{{ variable_name }}`
   - Conditionals: `{% if condition %}...{% endif %}`
   - See [Twig Documentation](https://twig.symfony.com/doc/3.x/)

### Accessing the Admin UI

1. Go to **WordPress Admin > PolyTrans > AI Assistants**
2. You'll see a list of all configured assistants (empty on first visit)
3. Click **Add New** to create your first assistant

## Creating an Assistant

### Step 1: Basic Information

1. **Name** (required)
   - Descriptive name for the assistant
   - Example: "Content Quality Reviewer", "SEO Optimizer", "Translation Enhancer"

2. **Provider** (required)
   - Choose: OpenAI, Claude, or Gemini
   - Currently only OpenAI is fully implemented

3. **Model** (required)
   - For OpenAI: gpt-4, gpt-4-turbo-preview, gpt-3.5-turbo, etc.
   - Example: `gpt-4` (most capable), `gpt-3.5-turbo` (faster, cheaper)

### Step 2: Prompt Template

Write your assistant's prompt using Twig syntax. All workflow context variables are available.

**Example 1: Content Quality Reviewer**

```twig
You are a content quality expert. Review the following translated article and provide feedback.

**Original Title:** {{ original.title }}
**Translated Title:** {{ translated.title }}

**Original Content:**
{{ original.content }}

**Translated Content:**
{{ translated.content }}

**Target Language:** {{ target_language }}

Please analyze:
1. Translation accuracy
2. Grammar and style
3. SEO optimization
4. Readability

Provide your response in JSON format:
{
  "quality_score": 0-100,
  "issues": ["issue1", "issue2"],
  "recommendations": ["rec1", "rec2"],
  "approved": true/false
}
```

**Example 2: SEO Optimizer**

```twig
Optimize the following content for SEO in {{ target_language }}.

**Title:** {{ title }}
**Content:** {{ content }}
**Target Keywords:** {{ meta.target_keywords|default('') }}

Provide optimized version with:
- SEO-friendly title (max 60 chars)
- Meta description (max 160 chars)
- Keyword-rich content
- Internal linking suggestions
```

### Step 3: Response Format

- **Text**: For simple text responses (default)
- **JSON**: For structured data (automatically validated)

### Step 4: Configuration

- **Temperature** (range depends on the model)
  - `0.0`: Focused, deterministic responses
  - `0.7`: Balanced (default)
  - Upper bound is `2.0` for OpenAI/Gemini, `1.0` for Claude

- **Reasoning Effort** (shown instead of, or next to, Temperature)
  - Reasoning models (OpenAI `gpt-5`/`o`-series, Claude 4.7 and newer) do not
    accept a temperature. PolyTrans hides the temperature field for them and
    shows an effort selector instead. Gemini and Claude 4.5/4.6 accept both, so
    both controls are shown there.
  - On OpenAI `gpt-5.1` and newer the two are mutually exclusive: a temperature
    only applies when effort is set to `none`. If you set a temperature without
    choosing an effort, PolyTrans turns reasoning off for you rather than letting
    the request fail.
  - The options are labelled with the value the provider itself expects, e.g.
    `Medium (medium)` for OpenAI `reasoning_effort`, `High (high)` for Gemini
    `thinkingLevel` and Claude `output_config.effort`, or
    `Medium (8,192 thinking tokens)` for an older Claude thinking budget.
  - **Provider default** leaves the parameter out of the request entirely.
  - The control switches automatically when you pick a different model, and the
    setting is kept when you switch between models that both support effort.

- **Max Tokens** (1 - 32000)
  - Maximum length of response
  - Default: 2000
  - Adjust based on expected response length

### Step 5: Save

Click **Save Assistant** to create the assistant.

## Using Assistants in Workflows

### Step 1: Create or Edit Workflow

1. Go to **PolyTrans > Post-Processing**
2. Create new workflow or edit existing one

### Step 2: Add Managed Assistant Step

1. Click **Add Step**
2. Set **Step Type** to **✨ Managed AI Assistant**
3. Select your assistant from the dropdown
4. Configure output actions (optional)

### Step 3: Configure Output Actions

Output actions determine what happens with the assistant's response.

**Example: Update Post Status Based on Quality Score**

```json
{
  "action": "update_post_status",
  "condition": "{{ quality_score >= 80 }}",
  "status": "publish"
}
```

**Example: Update Meta Field**

```json
{
  "action": "update_meta",
  "meta_key": "seo_score",
  "meta_value": "{{ seo_score }}"
}
```

## Testing Assistants

### Before Production Use

1. Go to **PolyTrans > AI Assistants**
2. Edit the assistant you want to test
3. Click **Test Assistant** button
4. Review the test results:
   - Response data
   - Provider and model used
   - Tokens consumed

### Test Variables

The test uses sample variables:
- `title`: "Sample Article Title"
- `content`: "This is sample content for testing the assistant."
- `excerpt`: "Sample excerpt"
- `language`: "en"

### Interpreting Results

✅ **Success**: Response received, JSON validated (if applicable)
❌ **Failure**: Error message with details (rate limit, timeout, etc.)

## Best Practices

### 1. Prompt Design

- **Be Specific**: Clearly define what you want the assistant to do
- **Provide Context**: Include relevant information (language, purpose, constraints)
- **Use Examples**: Show expected output format
- **Handle Edge Cases**: Account for missing data, empty fields

### 2. Variable Usage

- **Check Availability**: Use `{{ variable|default('fallback') }}` for optional fields
- **Conditional Logic**: `{% if variable %}...{% endif %}` to handle missing data
- **Nested Access**: `{{ original.meta.seo_title }}` for nested data

### 3. Response Formats

- **Text Format**: Use for simple responses, summaries, recommendations
- **JSON Format**: Use for structured data, multiple fields, conditional logic

### 4. Performance

- **Temperature**: Lower for consistent results, higher for creative tasks
- **Reasoning Effort**: Higher effort costs more tokens and latency - use `low`/`minimal` for mechanical tasks (formatting, extraction), `high` for analysis, and `xhigh`/`max` only where a model offers them and the task really needs it
- **Max Tokens**: Set appropriately to avoid unnecessary costs
- **Model Selection**: Use gpt-3.5-turbo for simple tasks, gpt-4 for complex analysis

### 5. Error Handling

- **Test First**: Always test assistants before using in production workflows
- **Monitor Logs**: Check **PolyTrans > Logs** for errors
- **Graceful Degradation**: Design workflows to handle assistant failures

## Troubleshooting

### Assistant Not Appearing in Workflow Dropdown

**Cause**: Assistant may not be saved or there's a JavaScript error

**Solution**:
1. Verify assistant is saved in **AI Assistants** menu
2. Check browser console for JavaScript errors
3. Refresh the workflow editor page

### "Rate Limit Exceeded" Error

**Cause**: Too many API requests to OpenAI

**Solution**:
1. Wait a few minutes before retrying
2. Upgrade your OpenAI plan for higher rate limits
3. Use gpt-3.5-turbo instead of gpt-4 (higher rate limits)

### "Insufficient Quota" Error

**Cause**: OpenAI account has no credits

**Solution**:
1. Add credits to your OpenAI account
2. Check billing at [OpenAI Platform](https://platform.openai.com/account/billing)

### JSON Parsing Failed

**Cause**: Assistant returned invalid JSON

**Solution**:
1. Make prompt more explicit about JSON format
2. Add example JSON in prompt
3. Use lower temperature (0.3-0.5) for more consistent output
4. Add instruction: "Respond ONLY with valid JSON, no additional text"

### Variables Not Interpolating

**Cause**: Incorrect Twig syntax or variable doesn't exist

**Solution**:
1. Check Twig syntax: `{{ variable }}` not `{variable}`
2. Use `{{ variable|default('') }}` for optional variables
3. Check available variables in workflow context
4. Test with simple variables first: `{{ title }}`

### Timeout Errors

**Cause**: Assistant taking too long to respond

**Solution**:
1. Reduce max_tokens to speed up response
2. Simplify prompt to reduce processing time
3. Use faster model (gpt-3.5-turbo instead of gpt-4)

## Advanced Usage

### Conditional Prompts

```twig
{% if target_language == 'de' %}
Bitte übersetze den folgenden Text ins Deutsche.
{% else %}
Please translate the following text to {{ target_language }}.
{% endif %}

**Content:** {{ content }}
```

### Looping Through Data

```twig
Review the following articles:

{% for article in recent_articles %}
- {{ article.title }} ({{ article.date }})
{% endfor %}

Provide recommendations for the current article based on these recent publications.
```

### Complex JSON Responses

```json
{
  "quality": {
    "score": 85,
    "factors": {
      "grammar": 90,
      "readability": 80,
      "seo": 85
    }
  },
  "recommendations": [
    {
      "type": "grammar",
      "severity": "low",
      "suggestion": "Fix comma placement in paragraph 3"
    }
  ],
  "approved": true
}
```

## Next Steps

- **Explore Workflows**: Learn more about workflow automation in [Workflow User Guide](WORKFLOW_USER_GUIDE.md)
- **Variable Reference**: See all available variables in [Variable Reference](VARIABLE_REFERENCE.md)
- **Twig Documentation**: Deep dive into Twig syntax at [twig.symfony.com](https://twig.symfony.com/doc/3.x/)

## Support

For issues, questions, or feature requests, please contact support or check the plugin documentation.

---

**Phase 1 Complete** ✅
- Backend Infrastructure
- Admin UI
- Workflow Integration
- Testing Functionality

**Coming in Phase 2:**
- Translation Integration
- Claude and Gemini Provider Implementation
- Advanced Analytics
- Assistant Templates Library

