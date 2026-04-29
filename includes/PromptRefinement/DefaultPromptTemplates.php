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
        return 'You are a strict quality evaluator. Be concise and always include one numeric score.';
    }

    public static function assistantEvaluator(): string
    {
        return "You will receive a system prompt, user message and assistant output.\n" .
            "Your task is to evaluate assistant response based on this criteria: {{ criteria }}\n\n" .
            "Be brief and provide:\n" .
            "1) Numeric score (0-100)\n" .
            "2) 2-4 short findings\n" .
            "3) One concrete suggestion\n\n" .
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
            "You will also receive instructions evaluated over several posts based on criteria: {{ criteria }}.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Adjust the prompts to satisfy the criteria better.\n" .
            "Return only valid JSON with these keys:\n" .
            "- system_prompt: improved system prompt string\n" .
            "- user_message_template: improved user message template string\n" .
            "{% if adjust_expected_output_schema %}- expected_output_schema: improved expected output schema as a JSON object or JSON string\n{% endif %}" .
            "Do not use markdown fences. Do not split the answer with separators. Prompt text may contain --- and that must remain literal content.\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function workflowEvaluatorSystem(): string
    {
        return 'You are a strict workflow quality evaluator. Be concise and always include one numeric score.';
    }

    public static function workflowEvaluator(): string
    {
        return "You evaluate one selected managed assistant step inside a larger workflow.\n" .
            "The selected target step is the only prompt that may be adjusted later, but judge it by its contribution to the complete workflow outcome.\n" .
            "Use the workflow context to understand what happened before the target step, what the target step produced, how output actions changed workflow variables, and what later steps did with that output.\n" .
            "Criteria: {{ criteria }}\n\n" .
            "Be brief and provide:\n" .
            "1) Numeric score (0-100)\n" .
            "2) 2-4 findings that separate target-step issues from issues caused by previous or following workflow steps\n" .
            "3) One concrete prompt-change suggestion for the target assistant step only\n\n" .
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
        return "You will receive a non-interpolated system prompt and user message template for one selected managed assistant step inside a larger workflow.\n" .
            "{% if adjust_expected_output_schema %}You will also receive expected output schema for JSON output mode.\n{% endif %}" .
            "The evaluations judge full workflow outcomes over several posts based on criteria: {{ criteria }}.\n\n" .
            "Use workflow context to understand which steps run before the selected target step, which steps run after it, what each step's prompts look like, whether each assistant returns JSON or text, and how output actions write data into workflow variables, post fields or meta.\n" .
            "Adjust only the selected target assistant prompt pack. Do not rewrite prompts for previous or following workflow steps.\n" .
            "If a problem belongs to another workflow step, mention it indirectly only by making the target prompt produce clearer or more useful output for that later step.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return only valid JSON with these keys:\n" .
            "- system_prompt: improved system prompt string\n" .
            "- user_message_template: improved user message template string\n" .
            "{% if adjust_expected_output_schema %}- expected_output_schema: improved expected output schema as a JSON object or JSON string\n{% endif %}" .
            "Do not use markdown fences. Do not split the answer with separators. Prompt text may contain --- and that must remain literal content.\n\n" .
            "Workflow structure JSON, including compact summaries of all steps:\n{{ workflow_structure_json }}\n\n" .
            "Selected target step context JSON:\n{{ target_step_context_json }}\n\n" .
            "Previous steps compact JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps compact JSON:\n{{ following_steps_json }}\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Full-workflow evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function promptAdjusterSystem(): string
    {
        return "You are a prompt optimization assistant. Return only the requested JSON object.\n" .
            "Do not wrap the JSON in markdown fences and do not use section separators.\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags (for example keep {% verbatim %}{{ content }}{% endverbatim %} exactly as provided).\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.";
    }
}
