# Model Capabilities: Temperature vs Reasoning Effort

## Why this exists

Classic chat models take a `temperature` float. Reasoning models do not - they
either reject `temperature` outright (OpenAI `gpt-5*`, `o*`, newer Claude models
where it is deprecated) or require it to be left at its default while they think
(older Claude extended thinking). Instead they expose an "effort" knob, and
**every provider names it differently**:

| Provider | Parameter | Accepted values | Temperature |
|----------|-----------|-----------------|-------------|
| OpenAI `gpt-5`, `-mini`, `-nano` | `reasoning_effort` | `minimal`, `low`, `medium`, `high` | rejected |
| OpenAI `gpt-5.1` | `reasoning_effort` | `none`, `low`, `medium`, `high` | only with effort `none` |
| OpenAI `gpt-5.2` … `gpt-5.5` | `reasoning_effort` | `none`, `low`, `medium`, `high`, `xhigh` | only with effort `none` |
| OpenAI `gpt-5.6` | `reasoning_effort` / `reasoning.effort` | `none` … `xhigh`, plus `max` on `/responses` | only with effort `none` |
| OpenAI `gpt-5.x-pro`, `-codex` | `reasoning.effort` (`/responses` only) | `medium`, `high`, `xhigh` (`gpt-5-pro`: `high` only) | rejected |
| OpenAI `o1`, `o3`, `o3-mini`, `o4-mini` | `reasoning_effort` | `low`, `medium`, `high`, `xhigh` | rejected |
| OpenAI `gpt-4*`, `gpt-3.5*` | - | - | `0.0`-`2.0` |
| Gemini 3.x Flash / Flash Lite | `generationConfig.thinkingConfig.thinkingLevel` | `minimal`, `low`, `medium`, `high` | `0.0`-`2.0` |
| Gemini 3.x Pro | `generationConfig.thinkingConfig.thinkingLevel` | `low`, `medium`, `high` | `0.0`-`2.0` |
| Gemini 2.5 | `generationConfig.thinkingConfig.thinkingBudget` | token budget (`0` disables on Flash; Pro cannot disable) | `0.0`-`2.0` |
| Claude 4.5 / 4.6 | `output_config.effort` | `low`, `medium`, `high` (+ `max` on 4.6) | `0.0`-`1.0` |
| Claude 4.7+ / 5 | `output_config.effort` | `low`, `medium`, `high`, `xhigh`, `max` | rejected (deprecated) |
| Claude Sonnet/Haiku 4.5 and older | `thinking.budget_tokens` | token budget (min `1024`, must be below `max_tokens`) | must not be sent while thinking |

Everything above was verified against the live APIs, which corrected several
assumptions taken from the docs:

- **`max` does not exist in OpenAI Chat Completions.** No model accepts it there;
  the ceiling is `xhigh`. It *does* exist on `/responses` for the `gpt-5.6`
  family, which is why PolyTrans routes that level there - see *The same model can
  differ per surface* and *Choosing the surface per request* below.
- **The o-series does accept `xhigh`** on Chat Completions, contrary to the
  three-level enum the reasoning guide describes (and it loses `xhigh` on
  `/responses`).
- **GPT-5.1+ does accept a temperature** - but only while reasoning is off. With
  any effort other than `none` the API answers *"Only the default (1) value is
  supported"*. See `requires_effort_none` below.
- **Gemini 2.5 rejects `thinkingLevel`** (*"Thinking level is not supported for
  this model"*) and still uses `thinkingBudget`; Gemini 3 accepts both but cannot
  disable thinking at all (`thinkingBudget: 0` is a 400).
- **Claude's effort is `output_config.effort`** - not a top-level field, not part
  of `thinking`, and no beta header is required. `temperature` is rejected as
  deprecated on every model that only supports **adaptive** thinking (Opus
  4.7/4.8, Opus 5, Sonnet 5, Fable 5), while models that still accept an explicit
  thinking budget also still accept a temperature.

## Temperature and effort are not mutually exclusive

Three different relationships exist, so the knowledge base models them
separately rather than assuming "reasoning model ⇒ no temperature":

| Relationship | Modelled as | Example |
|---|---|---|
| Temperature only | `reasoning => null` | `gpt-4o` |
| Effort only | `temperature.supported => false` | `gpt-5`, `o3`, Claude Opus 5 |
| Both, independently | both supported | Gemini, Claude 4.6 |
| Both, mutually exclusive | `temperature.requires_effort_none` + `reasoning.disables_temperature` | `gpt-5.1`+ |

For the last case, asking for a temperature *is* a request to turn reasoning off,
so `prepare_chat_parameters()` sends `reasoning_effort: none` alongside it rather
than relying on the provider's default effort (which differs between GPT-5.4 and
GPT-5.5 and would otherwise produce a 400). Setting an explicit effort above
`none` drops the temperature instead.

`PolyTrans\Core\ModelCapabilities` is the single place that knows all of this.

## Canonical levels

PolyTrans stores a **canonical** level, never the provider-native value:

```
none < minimal < low < medium < high < xhigh < max
```

The canonical level is translated to the native representation immediately
before the API call. This keeps configurations portable: switching an assistant
from `gpt-5` to `claude-opus-5` keeps a meaningful setting instead of sending an
invalid value. A level the target model does not support is snapped to the
closest one it does (ties resolve to the cheaper level), so `minimal` on Gemini 3
Pro becomes `low`, `none` on `gpt-5` becomes `minimal`, `max` on `gpt-5.5`
becomes `xhigh`, and `xhigh` on Claude 4.6 becomes `high`.

`none` is special: `ModelCapabilities::is_reasoning_active()` treats it (and a
zero token budget) as "reasoning off", which is what makes a temperature
acceptable again on the models that require it.

Stored values are also read back tolerantly: canonical names, provider-native
enum values and raw token budgets all normalize to a canonical level.

## Where it is applied

- `OpenAIChatClientAdapter`, `ClaudeChatClientAdapter`, `GeminiChatClientAdapter`
  call `ModelCapabilities::prepare_chat_parameters()` before building the request
  body. That is the choke point: any caller that still sends `temperature` to a
  reasoning model has it silently dropped instead of getting a 400.
- Claude writes the dotted parameter path into a nested object, so
  `output_config.effort` becomes `{"output_config": {"effort": "high"}}`. In
  budget mode it additionally grows `max_tokens` past the thinking budget and
  skips `top_p`/`top_k`, which the API rejects while thinking is enabled.
- Assistant editor (`config[reasoning_effort]`, stored in
  `api_parameters.reasoning_effort`) and the workflow AI step
  (`steps[N][reasoning_effort]`) render either a temperature input or an effort
  selector, labelled with the provider's own value names.
- Provider settings tabs render `ReasoningEffortField` next to the default model,
  storing `<provider>_reasoning_effort`. This is the site-wide default for callers
  that pick a model but say nothing about effort - the translation path, the
  description generator, refinement runs. Without it those inherit the provider's
  own default (`medium` on OpenAI reasoning models) with no way to change it.

### Precedence

1. An effort passed explicitly by the caller (assistant, workflow step).
2. `<provider>_reasoning_effort` from settings.
3. The `temperature`-implies-`effort: none` inference, for models that only accept
   a temperature while reasoning is off.
4. Nothing sent - the provider applies its own default.

2 deliberately outranks 3: a temperature may just be a caller's untouched default
(`DescriptionGeneratorService` hardcodes `0.2`), whereas a configured effort is a
choice someone made. The consequence is that configuring a site-wide effort on a
GPT-5.1+ model drops those temperatures instead of honouring them.

## Knowledge sources

The rule table is the offline fallback; whatever a provider reports about itself
wins over pattern matching.

**Anthropic** `GET /v1/models` returns a machine-readable `capabilities` object:

```json
{
  "id": "claude-opus-5",
  "max_tokens": 128000,
  "capabilities": {
    "effort": {"supported": true, "low": {"supported": true}, "max": {"supported": true}},
    "thinking": {"supported": true, "types": {
      "enabled": {"supported": false}, "adaptive": {"supported": true}
    }}
  }
}
```

`ClaudeSettingsProvider::extract_capability_metadata()` normalizes that into the
`store_api_metadata()` schema, so a model shipped after this plugin release gets
the right effort levels without a code change. There is no temperature flag, so
PolyTrans applies the derived rule above (adaptive-only ⇒ no temperature), which
matches all ten models the API currently lists.

**Gemini** `ListModels` reports `temperature`, `maxTemperature` and a `thinking`
flag, harvested the same way by `GeminiSettingsProvider::load_models()` (a
provider reporting `thinking: false` disables the effort control entirely).

**OpenAI** `/v1/models` returns only `id`/`created`/`owned_by`, so OpenAI relies
purely on the static table. The cheapest way to re-verify it is to send a
deliberately invalid value - the API answers with the exact enum it accepts, and
the request is rejected before it costs anything:

```bash
curl -s https://api.openai.com/v1/chat/completions -H "Authorization: Bearer $KEY" \
  -H 'content-type: application/json' \
  -d '{"model":"gpt-5.5","reasoning_effort":"bogus","max_completion_tokens":16,
       "messages":[{"role":"user","content":"hi"}]}'
# Supported values are: 'none', 'low', 'medium', 'high', and 'xhigh'.
```

Note that OpenAI validates value *ranges* before per-model support, so probing
`temperature` needs an in-range value (`0.5`, not `5`) to tell "unsupported for
this model" apart from "out of range". Gemini validates the proto enum first, so
its levels have to be probed one at a time - but it does report per-model
support before the quota check, which keeps probing free even on an exhausted
key.

## Extending / correcting the table

Two filters, no code changes needed:

```php
// Add or replace whole rules (first matching pattern per provider wins).
add_filter('polytrans_model_capability_rules', function (array $rules) {
    $rules['deepseek'] = [[
        'id' => 'deepseek-reasoner',
        'match' => ['/^deepseek-reasoner/'],
        'label' => 'DeepSeek Reasoner',
        'temperature' => ['supported' => false],
        'reasoning' => null,
    ]];

    return $rules;
});

// Adjust a single resolved model (e.g. once a provider ships a new effort enum).
add_filter('polytrans_model_capabilities', function (array $capabilities, $provider, $model) {
    if ($provider === 'openai' && strpos($model, 'gpt-5.6') === 0) {
        $capabilities['reasoning'] = [
            'mode' => \PolyTrans\Core\ModelCapabilities::MODE_EFFORT,
            'param' => 'reasoning_effort',
            'default' => 'medium',
            'levels' => [
                'low' => ['native' => 'low', 'label' => 'Low (low)'],
                'medium' => ['native' => 'medium', 'label' => 'Medium (medium)'],
                'high' => ['native' => 'high', 'label' => 'High (high)'],
            ],
        ];
    }

    return $capabilities;
}, 10, 3);
```

Unknown models and unknown providers fall back to "classic temperature model"
(`0.0-2.0`, default `0.7`), so third-party providers keep working unchanged.

## API surface

```php
use PolyTrans\Core\ModelCapabilities;

ModelCapabilities::supports_temperature('openai', 'gpt-5');        // false
ModelCapabilities::supports_temperature('claude', 'claude-opus-5'); // false (deprecated)
ModelCapabilities::supports_reasoning_effort('openai', 'gpt-5');   // true
ModelCapabilities::get_effort_levels('gemini', 'gemini-3-pro');    // [['value'=>'low','native'=>'low','label'=>'Low (low)'], ...]
ModelCapabilities::get_default_effort('claude', 'claude-opus-5');  // 'high'
ModelCapabilities::normalize_effort('claude', 'claude-sonnet-4-5', 2048);   // 'low'
ModelCapabilities::resolve_temperature('openai', 'gpt-4o', 5);     // 2.0
ModelCapabilities::resolve_reasoning('claude', 'claude-opus-5', 'max');
// ['mode'=>'effort','param'=>'output_config.effort','canonical'=>'max','value'=>'max', ...]

ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5', ['temperature' => 0.2, 'reasoning_effort' => 'high']);
// ['parameters' => [], 'reasoning' => ['param' => 'reasoning_effort', 'value' => 'high', ...], 'capabilities' => [...]]

// GPT-5.1+: a temperature turns reasoning off explicitly instead of 400-ing.
ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.4', ['temperature' => 0.5]);
// ['parameters' => ['temperature' => 0.5], 'reasoning' => ['value' => 'none', ...], ...]

ModelCapabilities::is_reasoning_active(['mode' => 'effort', 'canonical' => 'none']); // false

// Compact payload for admin JS (models sharing a profile share one entry).
ModelCapabilities::get_capabilities_payload('openai', $grouped_models);
```

`ModelCapabilities::describe($provider, $model)` returns the one-line summary
shown under the control in the admin UI.

## API surfaces

A model listed by `/v1/models` is not necessarily usable. Probing every model
OpenAI returns against both endpoints gives three groups:

| Group | Surface | Examples |
|---|---|---|
| `-pro`, `-codex` | `/responses` only | `gpt-5.5-pro`, `gpt-5.3-codex`, `o1-pro`, `o3-pro` |
| search-grounded, legacy GPT-3.5 | `/chat/completions` only | `gpt-4o-search-preview`, `gpt-5-search-api`, `gpt-3.5-turbo-16k` |
| not text generation | neither | TTS, transcribe, realtime, image, audio, embeddings, moderation, `*-instruct` |
| everything else | both | `gpt-5.4`, `gpt-5.6-sol`, `gpt-4o`, `o3` |

`get_api_surfaces()` encodes this and `is_model_usable()` intersects it with
`get_implemented_surfaces()` (the endpoints PolyTrans has an adapter for), which
keeps unusable models out of the pickers. Both are filterable
(`polytrans_model_api_surfaces`, `polytrans_implemented_api_surfaces`).

Deprecated models are largely **not** detectable this way - `/v1/models` still
lists them with no marker (e.g. `gpt-5-chat-latest`), and only a real request
reveals it. They are left in the list rather than hardcoding one that would go
stale.

The exception is the retired Codex generations (`gpt-5-codex`, `gpt-5.1-codex*`,
`gpt-5.2-codex`): `/chat/completions` calls them deprecated and `/responses`
answers `404 Model not found`, so there is nowhere to send them at all. Since
`gpt-5.3-codex` and later do work, this is matched by name as a snapshot rather
than as a rule about `-codex`.

## The same model can differ per surface

Effort levels are a property of the **(model, endpoint)** pair, not of the model
alone. Probed per level on both endpoints:

| Model | `/chat/completions` | `/responses` |
|---|---|---|
| `gpt-5`, `-mini`, `-nano` | `minimal`, `low`, `medium`, `high` | same |
| `gpt-5.1` | `none`, `low`, `medium`, `high` | same |
| `gpt-5.2` … `gpt-5.5` | `none` … `xhigh` | same |
| `gpt-5.6-sol/terra/luna` | `none` … `xhigh` | `none` … `xhigh`, **plus `max`** |
| `o1`, `o3`, `o3-mini`, `o4-mini` | `low`, `medium`, `high`, **`xhigh`** | `low`, `medium`, `high` |
| `gpt-5-pro` | n/a | `high` only |
| `gpt-5.2-pro`, `5.4-pro`, `5.5-pro` | n/a | `medium`, `high`, `xhigh` |

So `max` *does* exist - but only on `/responses`, and so far only for the
`gpt-5.6` family. Conversely the o-series loses `xhigh` when called through
`/responses`. Note that `/responses` reports the full enum in its error message
regardless of model (`none, minimal, low, medium, high, xhigh, max`), so the
message cannot be used to infer per-model support the way the Chat Completions
message can - each level has to be probed.

Payload shape also differs:

| | `/chat/completions` | `/responses` |
|---|---|---|
| Effort | `reasoning_effort: "high"` | `reasoning: {effort: "high"}` |
| Length control | `max_completion_tokens` | `max_output_tokens` |
| Verbosity | - | `text: {verbosity: low\|medium\|high}` |

Temperature behaves consistently across both: allowed only while reasoning is
off, and `gpt-5.4` + `effort: none` + `temperature` is accepted on either
endpoint.

A caveat on probing `minimal`: on `/responses` the gpt-5 family answers a 500,
not a 400, when the output budget is too small for the model to produce anything
(16 tokens). With a realistic budget the level works. A 500 therefore means
"retry bigger", not "unsupported".

## Choosing the surface per request

Both endpoints have an adapter (`OpenAIChatClientAdapter`,
`OpenAIResponsesClientAdapter`), and `ChatClientFactory` only knows the provider -
not the model - so the chat adapter is the single entry point and delegates when
needed. `ModelCapabilities::resolve_surface()` decides:

1. Only one usable surface (`-pro`, `-codex`) → that one.
2. The requested effort is missing from the default surface but present on
   another (`max` on GPT-5.6) → the surface that has it.
3. Otherwise → `/chat/completions`, the long-serving path.

So one model may use either endpoint depending on the effort selected. That is
deliberate: it delivers `max` without changing the endpoint for any
configuration that was already working. Override with
`polytrans_resolved_api_surface`.

Two consequences worth knowing:

- **Storing vs sending.** A stored effort is validated with
  `normalize_effort_across_surfaces()` (valid if *any* usable surface accepts it),
  while a request validates against the single surface it goes to. Validating
  storage against the default surface alone would downgrade `max` to `xhigh` and
  silently lose it.
- **UI offers the union.** `get_effort_levels_across_surfaces()` feeds the
  pickers, so `max` is offered for GPT-5.6 even though Chat Completions has no
  such level.

`extract_content()` on the chat adapter recognises a `/responses` payload
(`output` items instead of `choices`) and forwards it, because the caller still
holds the chat adapter after a delegated request.
