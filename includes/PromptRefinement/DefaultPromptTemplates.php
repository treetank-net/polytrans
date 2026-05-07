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
        return "You are a prompt evaluator. Start with `Score: N/100`. Do not return JSON or improved prompt text. Explain briefly what helped, what hurt, and what prompt-level change would most improve future outputs.";
    }

    public static function assistantEvaluator(): string
    {
        return "Evaluate this assistant output against the purpose and criteria.\n" .
            "Purpose: {{ prompt_objective }}\n" .
            "Criteria: {{ criteria }}\n\n" .
            "Give useful evidence for an adjuster that may add, remove, shorten, merge, or revert prompt text. Prefer shorter clearer prompts when they still satisfy purpose and criteria.\n\n" .
            "System prompt:\n{{ interpolated_system_prompt }}\n\n" .
            "User message:\n{{ interpolated_user_message }}\n\n" .
            "{% if include_expected_output_schema %}Expected output schema:\n{{ expected_output_schema }}\n\n{% endif %}" .
            "Output:\n{{ assistant_output }}";
    }

    public static function assistantAdjusterSystem(): string
    {
        return self::promptAdjusterSystem();
    }

    public static function assistantAdjuster(): string
    {
        return "Improve this prompt pack.\n" .
            "Purpose: {{ prompt_objective }}\n" .
            "Criteria: {{ criteria }}\n\n" .
            "Use the evaluations, full context, and refinement history. You may shorten, add, remove, merge, reprioritize, or revert, especially when the current score regressed. Preserve Twig syntax and variable references exactly.\n\n" .
            "Return XML-style blocks, no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "Optional: <change_summary>one short sentence</change_summary>\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% endif %}" .
            "History JSON:\n{{ refinement_history_json }}\n\n" .
            "Evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function workflowEvaluatorSystem(): string
    {
        return "You are a workflow prompt evaluator. Start with `Score: N/100`. Do not return JSON or improved prompt text. Explain briefly whether the selected step helped the whole workflow and what target-step prompt change would most help.";
    }

    public static function workflowEvaluator(): string
    {
        return "Evaluate the selected assistant step by the final workflow outcome.\n" .
            "Workflow purpose: {{ workflow_purpose }}\n" .
            "Selected step purpose: {{ prompt_objective }}\n" .
            "Criteria: {{ criteria }}\n\n" .
            "Use the full context. Separate target-step prompt issues from other-step/tooling issues. Give evidence useful to an adjuster; shorter clearer prompts are preferred when they still satisfy purpose and criteria.\n\n" .
            "Workflow: {{ workflow_name }} ({{ workflow_id }})\n" .
            "Workflow success: {{ workflow_success }}\n" .
            "Source language: {{ source_language }}\n" .
            "Target language: {{ target_language }}\n" .
            "Target step: {{ target_step_name }} ({{ target_step_id }})\n\n" .
            "Workflow structure JSON:\n{{ workflow_structure_json }}\n\n" .
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
        return "Improve only the selected target-step prompt pack.\n" .
            "Workflow purpose: {{ workflow_purpose }}\n" .
            "Selected step purpose: {{ prompt_objective }}\n" .
            "Criteria: {{ criteria }}\n\n" .
            "Use the evaluations, workflow context, and refinement history. You may shorten, add, remove, merge, reprioritize, or revert, especially when the current score regressed. Preserve Twig syntax and variable references exactly.\n\n" .
            "Return XML-style blocks, no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "Optional: <change_summary>one short sentence</change_summary>\n\n" .
            "Workflow structure JSON:\n{{ workflow_structure_json }}\n\n" .
            "Target step context JSON:\n{{ target_step_context_json }}\n\n" .
            "Previous steps compact JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps compact JSON:\n{{ following_steps_json }}\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% endif %}" .
            "History JSON:\n{{ refinement_history_json }}\n\n" .
            "Evaluations JSON:\n{{ evaluations_json }}";
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
        return "You are a prompt adjuster. Return only the requested XML-style prompt blocks, no markdown fences. Prefer the smallest effective change; shorter and clearer is usually better. Preserve Twig syntax exactly, including variables like {% verbatim %}{{ content }}{% endverbatim %}.";
    }
}
