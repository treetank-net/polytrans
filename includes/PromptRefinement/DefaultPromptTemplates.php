<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

if (!defined('ABSPATH')) {
    exit;
}

final class DefaultPromptTemplates
{
    public static function assistantEvaluatorSystem(): string
    {
        return "You are a diagnostic prompt evaluator. Return natural-language feedback for a prompt adjuster, not final prompt text.\n" .
            "Start with `Score: N/100`. Do not return JSON.\n" .
            "Explain the evidence and reasoning enough for an adjuster that will not see the full original context. Focus on reusable prompt-level causes, not one-off rewrites.";
    }

    public static function assistantEvaluator(): string
    {
        return "You will receive a system prompt, user message and assistant output.\n" .
            "Primary purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}\n\n" .
            "Evaluate purpose fit, criteria improvement, and prompt-level cause. Do not reward criteria improvement that breaks the primary purpose.\n" .
            "Think through whether the issue is missing instruction, conflicting instructions, prompt bloat, downstream contract mismatch, or model noncompliance despite clear instructions.\n" .
            "Give the adjuster enough concrete evidence to act without seeing the full original context. Recommend the smallest effective prompt change; adding, removing, merging or reverting text are all valid.\n" .
            "Do not write the final improved prompt.\n\n" .
            "Use these sections:\n" .
            "Score: N/100\n" .
            "Overall judgment:\n" .
            "Constraint diagnosis:\n" .
            "What helped:\n" .
            "What hurt:\n" .
            "Likely prompt-level cause:\n" .
            "Prompt changes likely to improve future outputs:\n" .
            "Risks / safeguards:\n\n" .
            "System prompt:\n{{ interpolated_system_prompt }}\n\n" .
            "User message:\n{{ interpolated_user_message }}\n\n" .
            "{% if include_expected_output_schema %}Expected output schema:\n{{ expected_output_schema }}\n\n{% endif %}" .
            "Assistant output:\n{{ assistant_output }}";
    }

    public static function assistantAdjusterSystem(): string
    {
        return self::promptAdjusterSystem();
    }

    public static function assistantAdjuster(): string
    {
        return "You will receive a non-interpolated system prompt and user message template.\n" .
            "{% if adjust_expected_output_schema %}You will also receive expected output schema for JSON output mode.\n{% endif %}" .
            "Primary purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}.\n" .
            "Evaluations judge outputs against the primary purpose and refinement criteria.\n\n" .
            "Feel free to reason through the evaluations. Some comments may be text-specific; infer the reusable prompt-level problem before editing.\n" .
            "Use `Refinement history JSON` to compare previous prompt versions and scores. If the current evaluation is worse than an earlier version, consider reverting or partially undoing changes that likely caused the regression.\n\n" .
            "Adjust only the selected prompt pack. Keep the primary purpose. Prefer compact changes; adding as well as removing sentences is valid as long as it follows criteria.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return the improved prompt pack using these XML-style wrapper tags, with no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "You may include a <change_summary> before the prompt blocks. In it, state whether you shortened, merged, reverted, reprioritized, or minimally added instructions. The prompt blocks are authoritative. Do not put the wrapper tag names inside the prompt text itself.\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Refinement history JSON:\n{{ refinement_history_json }}\n\n" .
            "Evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function workflowEvaluatorSystem(): string
    {
        return "You are a diagnostic workflow evaluator. Return natural-language feedback for a prompt adjuster, not final prompt text.\n" .
            "Start with `Score: N/100`. Do not return JSON.\n" .
            "Explain the evidence, causality and reasoning enough for an adjuster that will not see the full post context. Focus on reusable target-step prompt causes.";
    }

    public static function workflowEvaluator(): string
    {
        return "You evaluate one selected assistant step inside a larger workflow. The selected step may be a managed assistant or a custom inline AI assistant step.\n" .
            "The selected target step is the only prompt that may be adjusted later, but judge it by its contribution to the complete workflow outcome.\n" .
            "Whole-workflow purpose that must remain satisfied: {{ workflow_purpose }}\n" .
            "Selected target-step purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}\n\n" .
            "Evaluate purpose fit, criteria improvement, target-step contribution, and final workflow outcome. Do not reward criteria improvement that breaks either purpose.\n" .
            "Use workflow context to infer what happened before the target step, what the target step produced, and how later steps used it.\n" .
            "Think through whether the issue is missing instruction, conflicting instructions, prompt bloat, downstream contract mismatch, or model noncompliance despite clear instructions.\n" .
            "Separate target-step prompt issues from previous/following step issues. Recommend target-step changes only when evidence supports that causal link.\n" .
            "Give the adjuster enough concrete evidence to act without seeing the full post context. Recommend the smallest effective prompt change; adding, removing, merging or reverting text are all valid.\n" .
            "Do not write the final improved prompt.\n\n" .
            "Use these sections:\n" .
            "Score: N/100\n" .
            "Overall judgment:\n" .
            "Causality:\n" .
            "Constraint diagnosis:\n" .
            "What helped:\n" .
            "What hurt:\n" .
            "Likely target-step prompt-level cause:\n" .
            "Prompt changes likely to improve future workflow outputs:\n" .
            "Risks / safeguards:\n" .
            "Not caused by target step:\n\n" .
            "Workflow: {{ workflow_name }} ({{ workflow_id }})\n" .
            "Workflow success: {{ workflow_success }}\n" .
            "Source language: {{ source_language }}\n" .
            "Target language: {{ target_language }}\n" .
            "Target step: {{ target_step_name }} ({{ target_step_id }})\n\n" .
            "Workflow structure JSON, including compact summaries of all steps:\n{{ workflow_structure_json }}\n\n" .
            "Target step context JSON:\n{{ target_step_context_json }}\n\n" .
            "Previous steps compact JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps compact JSON:\n{{ following_steps_json }}\n\n" .
            "Target step interpolated system prompt:\n{{ target_interpolated_system_prompt }}\n\n" .
            "Target step interpolated user message:\n{{ target_interpolated_user_message }}\n\n" .
            "{% if include_expected_output_schema %}Target expected output schema:\n{{ expected_output_schema }}\n\n{% endif %}" .
            "Target step assistant output:\n{{ target_assistant_output }}\n\n" .
            "Final workflow output JSON:\n{{ final_output_json }}\n\n" .
            "Workflow step summary JSON:\n{{ workflow_result_json }}";
    }

    public static function workflowAdjusterSystem(): string
    {
        return self::promptAdjusterSystem();
    }

    public static function workflowAdjuster(): string
    {
        return "You will receive a non-interpolated system prompt and user message template for one selected assistant step inside a larger workflow. The selected step may be a managed assistant or a custom inline AI assistant step.\n" .
            "{% if adjust_expected_output_schema %}You will also receive expected output schema for JSON output mode.\n{% endif %}" .
            "Whole-workflow purpose that must remain satisfied: {{ workflow_purpose }}\n" .
            "Selected target-step purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}.\n" .
            "The evaluations judge full workflow outcomes over several posts against the whole-workflow purpose, selected-step purpose, and refinement criteria.\n\n" .
            "Feel free to reason through the evaluations. Some comments may be text-specific; infer the reusable prompt-level problem before editing.\n\n" .
            "Use workflow context to understand which steps run before the selected target step, which steps run after it, what each step's prompts look like, whether each assistant returns JSON or text, and how output actions write data into workflow variables, post fields or meta.\n" .
            "Adjust only the selected target assistant prompt pack. Do not rewrite prompts for previous or following workflow steps. Do not narrow the selected prompt so much that it stops fulfilling its primary workflow role.\n" .
            "If a problem belongs to another workflow step, mention it indirectly only by making the target prompt produce clearer or more useful output for that later step.\n\n" .
            "Use `Refinement history JSON` to compare previous target-step prompt versions and scores. If the current workflow evaluation is worse than an earlier version, consider reverting or partially undoing target-step prompt changes that likely caused the regression.\n\n" .
            "Prefer compact prompt changes. Adding as well as removing sentences is valid as long as it follows criteria.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return the improved target-step prompt pack using these XML-style wrapper tags, with no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "You may include a <change_summary> before the prompt blocks. In it, state whether you shortened, merged, reverted, reprioritized, or minimally added instructions. The prompt blocks are authoritative. Do not put the wrapper tag names inside the prompt text itself.\n\n" .
            "Workflow structure JSON, including compact summaries of all steps:\n{{ workflow_structure_json }}\n\n" .
            "Selected target step context JSON:\n{{ target_step_context_json }}\n\n" .
            "Previous steps compact JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps compact JSON:\n{{ following_steps_json }}\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Refinement history JSON:\n{{ refinement_history_json }}\n\n" .
            "Full-workflow evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function descriptionGeneratorSystem(): string
    {
        return "You write concise admin-facing descriptions for AI prompts and workflows.\n" .
            "Return only valid JSON with a single key: description.\n" .
            "The description must be short, concrete, and useful as a primary-purpose alignment goal during prompt refinement.\n" .
            "Do not use markdown fences. Do not mention implementation details that are not present in the input.";
    }

    public static function criteriaGeneratorSystem(): string
    {
        return "You rewrite workflow refinement criteria into one concise evaluation criterion.\n" .
            "Return only valid JSON with a single key: criteria.\n" .
            "Describe an observable output quality: coverage, precision, completeness, factuality, naturalness, consistency, actionability, false positives, or downstream usability.\n" .
            "If the user's criterion is broad, keep it broad and measurable. Use workflow context only for domain and quality target.\n" .
            "Do not import magic phrases, output formats, step mechanics, target prompt text, schemas, examples, checklists, or implementation details unless explicitly requested.\n" .
            "Keep it to 1-2 sentences and at most 55 words.";
    }

    public static function assistantDescriptionGenerator(): string
    {
        return "Create a concise description for this managed assistant.\n" .
            "The description will be used as the assistant's original purpose during prompt refinement, so preserve what the assistant is mainly supposed to do.\n\n" .
            "Assistant name: {{ assistant_name }}\n" .
            "Current description: {{ assistant_description }}\n" .
            "Provider: {{ assistant_provider }}\n" .
            "Model: {{ assistant_model }}\n" .
            "Response format: {{ response_format }}\n\n" .
            "System prompt:\n{{ system_prompt }}\n\n" .
            "User message template:\n{{ user_message_template }}\n\n" .
            "{% if expected_output_schema %}Expected output schema:\n{{ expected_output_schema }}\n\n{% endif %}" .
            "Return JSON like: {\"description\":\"One concise sentence describing the assistant's purpose.\"}";
    }

    public static function workflowDescriptionGenerator(): string
    {
        return "Create a concise description for this workflow as a whole.\n" .
            "Describe what the workflow achieves, not every internal detail. Use one or two sentences.\n\n" .
            "Workflow name: {{ workflow_name }}\n" .
            "Current workflow description: {{ workflow_description }}\n" .
            "Workflow target language: {{ workflow_language }}\n\n" .
            "Workflow steps JSON:\n{{ workflow_steps_json }}\n\n" .
            "Return JSON like: {\"description\":\"One or two concise sentences describing what this workflow does.\"}";
    }

    public static function workflowStepDescriptionGenerator(): string
    {
        return "Create a concise description for the selected workflow step in the context of the whole workflow.\n" .
            "The description will be used as the step's original purpose during prompt refinement, so focus on the step's role and expected contribution.\n\n" .
            "Workflow name: {{ workflow_name }}\n" .
            "Workflow description: {{ workflow_description }}\n" .
            "Selected step name: {{ target_step_name }}\n" .
            "Selected step ID: {{ target_step_id }}\n" .
            "Selected step type: {{ target_step_type }}\n" .
            "Current selected step description: {{ target_step_description }}\n\n" .
            "Previous steps JSON:\n{{ previous_steps_json }}\n\n" .
            "Selected step JSON:\n{{ target_step_json }}\n\n" .
            "Following steps JSON:\n{{ following_steps_json }}\n\n" .
            "Return JSON like: {\"description\":\"One concise sentence describing what this step must do.\"}";
    }

    public static function workflowCriteriaGenerator(): string
    {
        return "Rewrite the user's workflow refinement criteria into one concise, measurable criterion for evaluator and adjuster prompts.\n" .
            "Return only valid JSON with a single key: criteria.\n" .
            "Replace the current criteria, do not add a second one. Name the observable result to score and direction of improvement.\n" .
            "If the user criteria is vague or broad, preserve the breadth and make quality dimensions measurable. Do not turn it into a narrow implementation or output-contract requirement.\n" .
            "Ground it in workflow and target-step purpose, but keep it about output quality, not how to edit the prompt or execute the step.\n" .
            "Workflow prompts may contain formats, magic phrases and procedures. Treat them as context, not criteria to import, unless the user explicitly asks for contract compliance.\n" .
            "Keep the criteria to 1-2 sentences and at most 55 words.\n\n" .
            "Current user criteria:\n{{ current_criteria }}\n\n" .
            "Whole-workflow purpose:\n{{ workflow_purpose }}\n\n" .
            "Selected target-step purpose:\n{{ prompt_objective }}\n\n" .
            "Workflow name: {{ workflow_name }}\n" .
            "Selected target step: {{ target_step_name }} ({{ target_step_id }})\n\n" .
            "Workflow steps JSON:\n{{ workflow_steps_json }}\n\n" .
            "Selected target step JSON:\n{{ target_step_json }}\n\n" .
            "Previous steps JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps JSON:\n{{ following_steps_json }}\n\n" .
            "Return JSON like: {\"criteria\":\"One concise replacement criterion.\"}";
    }

    public static function promptAdjusterSystem(): string
    {
        return "You are a prompt optimization assistant. Return only the requested prompt pack blocks.\n" .
            "Use XML-style wrapper tags exactly as requested. Do not use markdown fences or section separators.\n" .
            "Prefer the smallest effective prompt change; shorter and clearer is usually better than longer.\n" .
            "Reason through whether to add, remove, merge, revert or reprioritize instructions. Do not merely restate an already-violated rule more strongly.\n" .
            "Maintain Twig syntax exactly, including variables like {% verbatim %}{{ content }}{% endverbatim %}. Keep variable references; do not inline or truncate their values.";
    }
}
