<?php

/**
 * Tests for OpenAI Provider JSON parsing integration
 * 
 * Tests that the OpenAI provider correctly uses the JSON Response Parser
 * for robust translation response handling.
 */

use PolyTrans\PostProcessing\JsonResponseParser;

beforeEach(function () {
    $this->parser = new JsonResponseParser();
});

test('parses clean translation response', function () {
    $response = <<<JSON
{
  "title": "Chargements d'Europe",
  "content": "🚛 Meilleure bourse de fret",
  "excerpt": "Trouvez des chargements",
  "meta": {
    "seo_title": "Bourse de Fret"
  },
  "featured_image": null
}
JSON;

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Chargements d\'Europe')
        ->and($result['data']['content'])->toContain('🚛')
        ->and($result['data']['meta'])->toBeArray()
        ->and($result['data']['featured_image'])->toBeNull();
});

test('parses translation response with AI commentary', function () {
    $response = <<<RESPONSE
I've translated your content from English to French. Here's the result:

```json
{
  "title": "Chargements d'Europe",
  "content": "🚛 **Meilleure** bourse de fret",
  "excerpt": "Trouvez des chargements",
  "meta": {
    "seo_title": "Bourse de Fret",
    "seo_description": "Plateforme de transport"
  },
  "featured_image": null
}
```

The translation preserves all formatting and emojis as requested.
RESPONSE;

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Chargements d\'Europe')
        ->and($result['data']['content'])->toContain('**Meilleure**')
        ->and($result['data']['meta']['seo_title'])->toBe('Bourse de Fret')
        ->and($result['data']['meta']['seo_description'])->toBe('Plateforme de transport');
});

test('handles missing optional fields gracefully', function () {
    $response = <<<JSON
{
  "title": "Chargements d'Europe",
  "content": "🚛 Meilleure bourse de fret",
  "excerpt": "Trouvez des chargements",
  "meta": {}
}
JSON;

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Chargements d\'Europe')
        ->and($result['data']['featured_image'])->toBeNull()
        ->and($result['warnings'])->toHaveCount(2) // featured_image missing + meta type coercion
        ->and($result['warnings'][0])->toContain('Type coercion: meta')
        ->and($result['warnings'][1])->toContain('Missing field: featured_image');
});

test('preserves complex meta structure', function () {
    $response = <<<JSON
{
  "title": "Transport Title",
  "content": "Content here",
  "excerpt": "Excerpt",
  "meta": {
    "seo_title": "SEO Title",
    "seo_description": "SEO Desc",
    "focus_keyword": "transport",
    "custom_field_1": "value1",
    "custom_field_2": "value2",
    "reading_time": "5"
  },
  "featured_image": "https://example.com/image.jpg"
}
JSON;

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['meta'])->toBeArray()
        ->and($result['data']['meta'])->toHaveCount(6)
        ->and($result['data']['meta']['seo_title'])->toBe('SEO Title')
        ->and($result['data']['meta']['custom_field_1'])->toBe('value1')
        ->and($result['data']['meta']['reading_time'])->toBe('5')
        ->and($result['data']['featured_image'])->toBe('https://example.com/image.jpg');
});

test('handles translation with newlines and special characters', function () {
    $response = "```json\n{\n  \"title\": \"Title with 'quotes'\",\n  \"content\": \"Line 1\\n\\nLine 2\\n\\nLine 3\",\n  \"excerpt\": \"Excerpt with \\\"escaped\\\" quotes\",\n  \"meta\": {},\n  \"featured_image\": null\n}\n```";

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toContain('quotes')
        ->and($result['data']['content'])->toContain("\n\n")
        ->and($result['data']['excerpt'])->toContain('escaped');
});

test('fails gracefully with completely invalid response', function () {
    $response = "Sorry, I couldn't translate this content. Please try again.";

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeFalse()
        ->and($result)->toHaveKey('error')
        ->and($result['error'])->toContain('Failed to extract JSON');
});

test('handles extra fields from AI (bonus data)', function () {
    $response = <<<JSON
{
  "title": "Translated Title",
  "content": "Translated Content",
  "excerpt": "Translated Excerpt",
  "meta": {
    "seo_title": "SEO"
  },
  "featured_image": null,
  "translation_quality": "excellent",
  "confidence_score": 0.95,
  "detected_language": "en"
}
JSON;

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object',
        'featured_image' => 'string'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        // Required fields
        ->and($result['data']['title'])->toBe('Translated Title')
        // Bonus fields preserved
        ->and($result['data']['translation_quality'])->toBe('excellent')
        ->and($result['data']['confidence_score'])->toBe(0.95)
        ->and($result['data']['detected_language'])->toBe('en');
});
