# PolyTrans Translation and Usage Context

This context defines how a translation and the AI work that follows it are counted together for cost and performance analysis.

## Translation

**TranslationRun**:
One attempt to produce one target-language version of one source article. It includes every translation hop and every workflow started by that translation's completion.
_Avoid_: translation job, batch, individual call

**TranslationHop**:
One language transition inside a TranslationRun, such as `pl→en` or `en→de`. A relay contains multiple hops but remains one TranslationRun.
_Avoid_: workflow step, whole translation

**PostTranslationWorkflow**:
A workflow started because a TranslationRun completed. It belongs to that run for whole-process accounting, but it is not a TranslationHop.
_Avoid_: translation step

## Usage

**UsageEvent**:
One billable AI model invocation belonging to a TranslationRun or another activity. It is the atomic record from which run totals are built.
_Avoid_: run, process

**RunMetric**:
A measurement projected onto a TranslationRun, such as cost, tokens, source characters, or source words. The total run cost remains the source of truth; metrics are alternate denominators for comparison.
_Avoid_: billing unit

**CostProjection**:
A derived comparison such as cost per 1,000 source characters, words, or tokens. It describes the observed run and does not replace provider billing, which is token-based.
_Avoid_: price per character
