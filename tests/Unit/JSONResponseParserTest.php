<?php

/**
 * Tests for PolyTrans_JSON_Response_Parser
 * 
 * Tests robust JSON extraction and schema-based validation/coercion
 */

use PolyTrans\PostProcessing\JsonResponseParser;

beforeEach(function () {
    $this->parser = new JsonResponseParser();
});

// ============================================================================
// JSON Extraction Tests
// ============================================================================

test('extracts clean JSON', function () {
    $response = '{"analysis": "Good post", "score": 8}';
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['analysis'])->toBe('Good post')
        ->and($result['score'])->toBe(8);
});

test('extracts JSON from ```json``` code block', function () {
    $response = "Here's your result:\n```json\n{\"analysis\": \"Good post\", \"score\": 8}\n```\nHope this helps!";
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['analysis'])->toBe('Good post')
        ->and($result['score'])->toBe(8);
});

test('extracts JSON from generic ``` code block', function () {
    $response = "```\n{\"analysis\": \"Good post\", \"score\": 8}\n```";
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['analysis'])->toBe('Good post');
});

test('extracts JSON embedded in text', function () {
    $response = "Sure! Here's the analysis: {\"analysis\": \"Good post\", \"score\": 8} Let me know!";
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['analysis'])->toBe('Good post');
});

test('extracts nested JSON objects', function () {
    $response = '{"meta": {"seo": {"title": "SEO Title", "description": "SEO Desc"}}, "score": 9}';
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['meta'])->toBeArray()
        ->and($result['meta']['seo']['title'])->toBe('SEO Title');
});

test('extracts JSON with arrays', function () {
    $response = '{"suggestions": ["Add images", "Fix typo", "Improve SEO"], "score": 7}';
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['suggestions'])->toBeArray()
        ->and($result['suggestions'])->toHaveCount(3)
        ->and($result['suggestions'][0])->toBe('Add images');
});

test('returns null for invalid JSON', function () {
    $response = "This is just plain text, no JSON here!";
    $result = $this->parser->extract_json($response);

    expect($result)->toBeNull();
});

test('extracts first valid JSON from multiple objects', function () {
    $response = '{"first": "object"} and {"second": "object"}';
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('first');
});

test('extracts JSON with escaped quotes', function () {
    $response = '{"analysis": "Post says \"hello world\"", "score": 8}';
    $result = $this->parser->extract_json($response);

    expect($result)->toBeArray()
        ->and($result['analysis'])->toContain('hello world');
});

// ============================================================================
// Schema Validation Tests
// ============================================================================

test('validates schema with perfect match', function () {
    $response = '{"analysis": "Good post", "score": 8, "suggestions": ["tip1", "tip2"]}';
    $schema = [
        'analysis' => 'string',
        'score' => 'number',
        'suggestions' => 'array'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['analysis'])->toBe('Good post')
        ->and($result['data']['score'])->toBe(8)
        ->and($result['data']['suggestions'])->toBeArray()
        ->and($result['warnings'])->toBeEmpty();
});

test('sets missing fields to null', function () {
    $response = '{"analysis": "Good post"}';
    $schema = [
        'analysis' => 'string',
        'score' => 'number',
        'suggestions' => 'array'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['analysis'])->toBe('Good post')
        ->and($result['data']['score'])->toBeNull()
        ->and($result['data']['suggestions'])->toBeNull()
        ->and($result['warnings'])->toHaveCount(2);
});

test('coerces string to number', function () {
    $response = '{"score": "8"}';
    $schema = ['score' => 'number'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['score'])->toBeInt()
        ->and($result['data']['score'])->toBe(8)
        ->and($result['warnings'])->toHaveCount(1);
});

test('coerces float string to number', function () {
    $response = '{"score": "8.5"}';
    $schema = ['score' => 'number'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['score'])->toBeFloat()
        ->and($result['data']['score'])->toBe(8.5);
});

test('wraps single value in array', function () {
    $response = '{"suggestions": "Add images"}';
    $schema = ['suggestions' => 'array'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['suggestions'])->toBeArray()
        ->and($result['data']['suggestions'])->toBe(['Add images'])
        ->and($result['warnings'])->toHaveCount(1);
});

test('coerces string to boolean', function () {
    $response = '{"approved": "true", "rejected": "false", "pending": "yes"}';
    $schema = [
        'approved' => 'boolean',
        'rejected' => 'boolean',
        'pending' => 'boolean'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['approved'])->toBeTrue()
        ->and($result['data']['rejected'])->toBeFalse()
        ->and($result['data']['pending'])->toBeTrue();
});

test('preserves extra fields not in schema', function () {
    $response = '{"analysis": "Good", "extra_field": "Bonus", "another": 123}';
    $schema = ['analysis' => 'string'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['analysis'])->toBe('Good')
        ->and($result['data']['extra_field'])->toBe('Bonus')
        ->and($result['data']['another'])->toBe(123);
});

test('validates nested objects', function () {
    $response = '{"meta": {"title": "Title", "score": "9"}}';
    $schema = ['meta' => 'object'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['meta'])->toBeArray()
        ->and($result['data']['meta']['title'])->toBe('Title');
});

test('fails gracefully with invalid JSON', function () {
    $response = 'Not JSON at all!';
    $schema = ['analysis' => 'string'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeFalse()
        ->and($result)->toHaveKey('error')
        ->and($result['error'])->toContain('Failed to extract JSON');
});

test('returns all data with empty schema', function () {
    $response = '{"any": "field", "works": true, "count": 42}';
    $schema = [];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['any'])->toBe('field')
        ->and($result['data']['works'])->toBeTrue()
        ->and($result['data']['count'])->toBe(42)
        ->and($result['warnings'])->toBeEmpty();
});

test('handles real-world AI commentary', function () {
    $response = "Certainly! I've analyzed the post:\n\n```json\n{\n  \"analysis\": \"Well-structured\",\n  \"score\": 8.5,\n  \"suggestions\": [\n    \"Add more images\",\n    \"Improve meta\"\n  ],\n  \"seo_ready\": true\n}\n```\n\nLet me know if you need clarifications!";
    
    $schema = [
        'analysis' => 'string',
        'score' => 'number',
        'suggestions' => 'array',
        'seo_ready' => 'boolean'
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['analysis'])->toBe('Well-structured')
        ->and($result['data']['score'])->toBe(8.5)
        ->and($result['data']['suggestions'])->toHaveCount(2)
        ->and($result['data']['seo_ready'])->toBeTrue();
});

test('sets impossible type coercion to null', function () {
    $response = '{"score": "not a number"}';
    $schema = ['score' => 'number'];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['score'])->toBeNull()
        ->and($result['warnings'])->toHaveCount(1);
});

test('validates nested schema with specific fields', function () {
    $response = <<<JSON
{
  "title": "Test Post",
  "content": "Post content here",
  "meta": {
    "seo_title": "SEO Title",
    "seo_description": "SEO Description",
    "focus_keyword": "keyword"
  }
}
JSON;

    $schema = [
        'title' => 'string',
        'content' => 'string',
        'meta' => [
            'seo_title' => 'string',
            'seo_description' => 'string',
            'focus_keyword' => 'string'
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Test Post')
        ->and($result['data']['meta'])->toBeArray()
        ->and($result['data']['meta']['seo_title'])->toBe('SEO Title')
        ->and($result['data']['meta']['seo_description'])->toBe('SEO Description')
        ->and($result['data']['meta']['focus_keyword'])->toBe('keyword')
        ->and($result['warnings'])->toBeEmpty();
});

test('handles missing nested fields', function () {
    $response = <<<JSON
{
  "title": "Test Post",
  "meta": {
    "seo_title": "SEO Title"
  }
}
JSON;

    $schema = [
        'title' => 'string',
        'meta' => [
            'seo_title' => 'string',
            'seo_description' => 'string',
            'focus_keyword' => 'string'
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Test Post')
        ->and($result['data']['meta']['seo_title'])->toBe('SEO Title')
        ->and($result['data']['meta']['seo_description'])->toBeNull()
        ->and($result['data']['meta']['focus_keyword'])->toBeNull()
        ->and($result['warnings'])->toHaveCount(2) // 2 missing nested fields
        ->and($result['warnings'][0])->toContain('Missing nested field: meta.seo_description')
        ->and($result['warnings'][1])->toContain('Missing nested field: meta.focus_keyword');
});

test('preserves extra nested fields not in schema', function () {
    $response = <<<JSON
{
  "title": "Test Post",
  "meta": {
    "seo_title": "SEO Title",
    "custom_field": "Custom Value",
    "another_field": 123
  }
}
JSON;

    $schema = [
        'title' => 'string',
        'meta' => [
            'seo_title' => 'string'
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['meta']['seo_title'])->toBe('SEO Title')
        ->and($result['data']['meta']['custom_field'])->toBe('Custom Value')
        ->and($result['data']['meta']['another_field'])->toBe(123);
});

test('coerces nested field types', function () {
    $response = <<<JSON
{
  "title": "Test Post",
  "meta": {
    "reading_time": "5",
    "word_count": "1234"
  }
}
JSON;

    $schema = [
        'title' => 'string',
        'meta' => [
            'reading_time' => 'number',
            'word_count' => 'number'
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['meta']['reading_time'])->toBe(5)
        ->and($result['data']['meta']['word_count'])->toBe(1234)
        ->and($result['warnings'])->toHaveCount(2) // 2 type coercions
        ->and($result['warnings'][0])->toContain('Type coercion: meta.reading_time')
        ->and($result['warnings'][1])->toContain('Type coercion: meta.word_count');
});

// ============================================================================
// Object Schema Format with Auto-Mapping Tests
// ============================================================================

test('parses object schema format with target mappings', function () {
    $response = <<<JSON
{
  "title": "Test Post Title",
  "content": "Post content here",
  "excerpt": "Post excerpt"
}
JSON;

    $schema = [
        'title' => [
            'type' => 'string',
            'target' => 'post.title',
            'required' => true
        ],
        'content' => [
            'type' => 'string',
            'target' => 'post.content'
        ],
        'excerpt' => [
            'type' => 'string',
            'target' => 'post.excerpt'
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Test Post Title')
        ->and($result['data']['content'])->toBe('Post content here')
        ->and($result['data']['excerpt'])->toBe('Post excerpt')
        ->and($result['mappings'])->toBeArray()
        ->and($result['mappings']['title']['target'])->toBe('post.title')
        ->and($result['mappings']['title']['required'])->toBeTrue()
        ->and($result['mappings']['content']['target'])->toBe('post.content')
        ->and($result['mappings']['excerpt']['target'])->toBe('post.excerpt');
});

test('parses nested object schema with target mappings', function () {
    $response = <<<JSON
{
  "title": "Test Post",
  "meta": {
    "seo_title": "SEO Title",
    "seo_description": "SEO Description"
  }
}
JSON;

    $schema = [
        'title' => [
            'type' => 'string',
            'target' => 'post.title'
        ],
        'meta' => [
            'seo_title' => [
                'type' => 'string',
                'target' => 'meta.seo_title',
                'required' => true
            ],
            'seo_description' => [
                'type' => 'string',
                'target' => 'meta.seo_description'
            ]
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Test Post')
        ->and($result['data']['meta']['seo_title'])->toBe('SEO Title')
        ->and($result['data']['meta']['seo_description'])->toBe('SEO Description')
        ->and($result['mappings'])->toBeArray()
        ->and($result['mappings']['title']['target'])->toBe('post.title')
        ->and($result['mappings']['meta.seo_title']['target'])->toBe('meta.seo_title')
        ->and($result['mappings']['meta.seo_title']['required'])->toBeTrue()
        ->and($result['mappings']['meta.seo_description']['target'])->toBe('meta.seo_description');
});

test('supports mixed simple and object schema format', function () {
    $response = <<<JSON
{
  "title": "Test Post",
  "custom_field": "Custom Value",
  "meta": {
    "seo_title": "SEO Title"
  }
}
JSON;

    $schema = [
        'title' => [
            'type' => 'string',
            'target' => 'post.title'
        ],
        'custom_field' => 'string', // Simple format (no auto-mapping)
        'meta' => [
            'seo_title' => [
                'type' => 'string',
                'target' => 'meta.seo_title'
            ]
        ]
    ];

    $result = $this->parser->parse_with_schema($response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Test Post')
        ->and($result['data']['custom_field'])->toBe('Custom Value')
        ->and($result['mappings'])->toHaveKey('title')
        ->and($result['mappings'])->not->toHaveKey('custom_field') // No mapping for simple format
        ->and($result['mappings'])->toHaveKey('meta.seo_title');
});

// ============================================================================
// Real-World Use Cases
// ============================================================================

test('use case 1: simple translation with KEY (EN→FR logistics)', function () {
    // Simulates a simple translation assistant that returns KEY + translated text
    $ai_response = "Sure! Here's the translation:\n\n```json\n{\n  \"KEY\": \"1\",\n  \"text\": \"🚛 Des chargements de **toute l'Europe** en un seul endroit !\"\n}\n```\n\nLet me know if you need anything else!";
    
    $schema = [
        'KEY' => 'string',
        'text' => 'string'
    ];

    $result = $this->parser->parse_with_schema($ai_response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['KEY'])->toBe('1')
        ->and($result['data']['text'])->toContain('🚛')
        ->and($result['data']['text'])->toContain('**toute l\'Europe**')
        ->and($result['warnings'])->toBeEmpty();
});

test('use case 2: batch translation with multiple items', function () {
    // Simulates a batch translation assistant that returns array of items
    $ai_response = <<<JSON
Here are your translations:

```json
{
  "items": [
    {
      "key": "title",
      "content": "Chargements d'Europe"
    },
    {
      "key": "content",
      "content": "🚛 **Meilleure** bourse de fret !"
    },
    {
      "key": "excerpt",
      "content": "Trouvez des chargements"
    },
    {
      "key": "meta_seo_title",
      "content": "Bourse de Fret"
    }
  ]
}
```

All done!
JSON;
    
    $schema = [
        'items' => 'array'
    ];

    $result = $this->parser->parse_with_schema($ai_response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['items'])->toBeArray()
        ->and($result['data']['items'])->toHaveCount(4)
        ->and($result['data']['items'][0]['key'])->toBe('title')
        ->and($result['data']['items'][0]['content'])->toBe('Chargements d\'Europe')
        ->and($result['data']['items'][1]['content'])->toContain('🚛')
        ->and($result['data']['items'][1]['content'])->toContain('**Meilleure**')
        ->and($result['warnings'])->toBeEmpty();
});

test('use case 3: full post structure translation (WordPress content)', function () {
    // Simulates a full WordPress post translation with nested meta
    $ai_response = "I've translated your content from English to French:\n\n```json\n{\n  \"title\": \"Chargements de toute l'Europe\",\n  \"content\": \"🚛 **Trans.eu** est la bourse de fret la plus moderne !\\n\\nVoici ce que vous obtenez :\\n\\n📱 Application mobile\\n💬 Messagerie instantanée\\n🌍 Couverture européenne\",\n  \"excerpt\": \"Trouvez des chargements partout en Europe\",\n  \"meta\": {\n    \"seo_title\": \"Bourse de Fret Européenne | Trans.eu\",\n    \"seo_description\": \"Plateforme de transport routier avec des milliers de chargements\",\n    \"focus_keyword\": \"bourse de fret\",\n    \"reading_time\": \"3\"\n  }\n}\n```\n\nThe translation preserves all emojis, markdown formatting, and line breaks as requested.";
    
    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'meta' => 'object'
    ];

    $result = $this->parser->parse_with_schema($ai_response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['title'])->toBe('Chargements de toute l\'Europe')
        ->and($result['data']['content'])->toContain('🚛')
        ->and($result['data']['content'])->toContain('**Trans.eu**')
        ->and($result['data']['content'])->toContain("\n\n") // Real newlines, not literal \n
        ->and($result['data']['excerpt'])->toContain('Europe')
        ->and($result['data']['meta'])->toBeArray()
        ->and($result['data']['meta']['seo_title'])->toContain('Trans.eu')
        ->and($result['data']['meta']['seo_description'])->toContain('transport')
        ->and($result['data']['meta']['focus_keyword'])->toBe('bourse de fret')
        ->and($result['data']['meta']['reading_time'])->toBe('3')
        // meta is array in PHP (JSON object → PHP array), so we get a type coercion warning
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain('Type coercion: meta');
});

test('use case 4: content analysis with scores and suggestions', function () {
    // Simulates a content quality assistant that analyzes and scores content
    $ai_response = <<<RESPONSE
I've analyzed your post. Here's my assessment:

```json
{
  "overall_score": 8.5,
  "seo_score": 9,
  "readability_score": 7,
  "suggestions": [
    "Add more internal links",
    "Include relevant images",
    "Optimize meta description length"
  ],
  "keyword_density": {
    "transport": 5,
    "logistics": 3,
    "freight": 8
  },
  "approved": true,
  "issues_found": 2
}
```

Overall, it's a strong piece of content with minor improvements needed.
RESPONSE;
    
    $schema = [
        'overall_score' => 'number',
        'seo_score' => 'number',
        'readability_score' => 'number',
        'suggestions' => 'array',
        'keyword_density' => 'object',
        'approved' => 'boolean',
        'issues_found' => 'number'
    ];

    $result = $this->parser->parse_with_schema($ai_response, $schema);

    expect($result['success'])->toBeTrue()
        ->and($result['data']['overall_score'])->toBe(8.5)
        ->and($result['data']['seo_score'])->toBe(9)
        ->and($result['data']['readability_score'])->toBe(7)
        ->and($result['data']['suggestions'])->toBeArray()
        ->and($result['data']['suggestions'])->toHaveCount(3)
        ->and($result['data']['suggestions'][0])->toContain('internal links')
        ->and($result['data']['keyword_density'])->toBeArray()
        ->and($result['data']['keyword_density']['transport'])->toBe(5)
        ->and($result['data']['keyword_density']['freight'])->toBe(8)
        ->and($result['data']['approved'])->toBeTrue()
        ->and($result['data']['issues_found'])->toBe(2)
        // keyword_density is array in PHP (JSON object → PHP array), so we get a type coercion warning
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain('Type coercion: keyword_density');
});

test('use case 5: partial response with missing fields and type coercion', function () {
    // Simulates AI that forgets some fields or returns wrong types
    $ai_response = <<<RESPONSE
Here's what I found:

```json
{
  "title": "Translated Title",
  "content": "Some content here",
  "seo_score": "8",
  "approved": "yes",
  "suggestions": "Add more keywords"
}
```

Note: I couldn't analyze the excerpt as it was too short.
RESPONSE;
    
    $schema = [
        'title' => 'string',
        'content' => 'string',
        'excerpt' => 'string',
        'seo_score' => 'number',
        'readability_score' => 'number',
        'approved' => 'boolean',
        'suggestions' => 'array'
    ];

    $result = $this->parser->parse_with_schema($ai_response, $schema);

    expect($result['success'])->toBeTrue()
        // Fields that exist
        ->and($result['data']['title'])->toBe('Translated Title')
        ->and($result['data']['content'])->toBe('Some content here')
        // Missing fields → null
        ->and($result['data']['excerpt'])->toBeNull()
        ->and($result['data']['readability_score'])->toBeNull()
        // Type coercion: string "8" → number 8
        ->and($result['data']['seo_score'])->toBe(8)
        // Type coercion: string "yes" → boolean true
        ->and($result['data']['approved'])->toBeTrue()
        // Type coercion: string → array
        ->and($result['data']['suggestions'])->toBeArray()
        ->and($result['data']['suggestions'])->toBe(['Add more keywords'])
        // Should have warnings for missing fields and type coercions
        ->and($result['warnings'])->toHaveCount(5); // 2 missing + 3 coercions
});
