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
        return "You are a diagnostic prompt evaluator. Write natural-language feedback for a prompt adjuster, not final prompt text.\n" .
            "Always start with `Score: N/100`. Do not return JSON. Focus on reusable prompt-level causes, not text-specific rewrites.";
    }

    public static function assistantEvaluator(): string
    {
        return "You will receive a system prompt, user message and assistant output.\n" .
            "Primary purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}\n\n" .
            "Evaluate whether the assistant still fulfills the primary purpose while also improving toward the refinement criteria. Do not reward a response that satisfies the refinement criteria by abandoning the primary purpose.\n\n" .
            "Write a structured diagnostic report. Do not write the final improved prompt. Do not give only one suggestion. Suggestions must generalize across future inputs and must be based on prompt behavior, not this specific text alone.\n\n" .
            "Use exactly these sections:\n" .
            "Score: N/100\n" .
            "Overall judgment:\n" .
            "What helped:\n" .
            "What hurt:\n" .
            "Likely prompt-level cause:\n" .
            "Prompt changes likely to improve future outputs:\n" .
            "Risks / safeguards:\n\n" .
            "In `Prompt changes likely to improve future outputs`, list multiple reusable mechanisms when relevant, such as clearer success criteria, stronger output-contract reminders, target-language checks, source-language interference checks, prioritization rules, preservation rules, or examples of acceptable versus unacceptable behavior. Avoid advice that only rewrites this one input.\n\n" .
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
            "Adjust the prompts to improve the refinement criteria without narrowing the prompt so much that it stops fulfilling the primary purpose.\n\n" .
            "Use `Refinement history JSON` to compare previous prompt versions and scores. If the current evaluation is worse than an earlier version, consider reverting or partially undoing changes that likely caused the regression.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return the improved prompt pack using these XML-style wrapper tags, with no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "You may include a short <change_summary> before the prompt blocks, but the prompt blocks are authoritative. Do not put the wrapper tag names inside the prompt text itself. Prompt text may contain --- and that must remain literal content.\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Refinement history JSON:\n{{ refinement_history_json }}\n\n" .
            "Evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function workflowEvaluatorSystem(): string
    {
        return "You are a diagnostic workflow evaluator. Write natural-language feedback for a prompt adjuster, not final prompt text.\n" .
            "Always start with `Score: N/100`. Do not return JSON. Focus on reusable target-step prompt causes, not text-specific rewrites.";
    }

    public static function workflowEvaluator(): string
    {
        return "You evaluate one selected assistant step inside a larger workflow. The selected step may be a managed assistant or a custom inline AI assistant step.\n" .
            "The selected target step is the only prompt that may be adjusted later, but judge it by its contribution to the complete workflow outcome.\n" .
            "Use the workflow context to understand what happened before the target step, what the target step produced, how output actions changed workflow variables, and what later steps did with that output.\n" .
            "Whole-workflow purpose that must remain satisfied: {{ workflow_purpose }}\n" .
            "Selected target-step purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}\n\n" .
            "Evaluate whether the full workflow still fulfills its whole-workflow purpose and whether the selected step still fulfills its own purpose while also improving toward the refinement criteria. Do not reward changes that satisfy the refinement criteria by breaking either purpose.\n\n" .
            "Write a structured diagnostic report. Do not write the final improved prompt. Do not give only one suggestion. Suggestions must generalize across future workflow runs and must be based on target-step prompt behavior, not this specific post alone.\n" .
            "Separate target-step prompt issues from issues caused by previous or following workflow steps. Only recommend changes for the selected target step when the workflow evidence supports that causal link. If a problem belongs elsewhere, say so under `Not caused by target step` and do not turn it into a target-step prompt change.\n\n" .
            "Use exactly these sections:\n" .
            "Score: N/100\n" .
            "Overall judgment:\n" .
            "Causality:\n" .
            "What helped:\n" .
            "What hurt:\n" .
            "Likely target-step prompt-level cause:\n" .
            "Prompt changes likely to improve future workflow outputs:\n" .
            "Risks / safeguards:\n" .
            "Not caused by target step:\n\n" .
            "In `Prompt changes likely to improve future workflow outputs`, list multiple reusable mechanisms when relevant, such as clearer target-language requirements, stricter input/output contracts for later steps, better use of prior-step variables, stronger preservation rules, failure-mode checks, or examples of acceptable versus unacceptable target-step behavior.\n\n" .
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
            "Use workflow context to understand which steps run before the selected target step, which steps run after it, what each step's prompts look like, whether each assistant returns JSON or text, and how output actions write data into workflow variables, post fields or meta.\n" .
            "Adjust only the selected target assistant prompt pack. Do not rewrite prompts for previous or following workflow steps. Do not narrow the selected prompt so much that it stops fulfilling its primary workflow role.\n" .
            "If a problem belongs to another workflow step, mention it indirectly only by making the target prompt produce clearer or more useful output for that later step.\n\n" .
            "Use `Refinement history JSON` to compare previous target-step prompt versions and scores. If the current workflow evaluation is worse than an earlier version, consider reverting or partially undoing target-step prompt changes that likely caused the regression.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return the improved target-step prompt pack using these XML-style wrapper tags, with no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "You may include a short <change_summary> before the prompt blocks, but the prompt blocks are authoritative. Do not put the wrapper tag names inside the prompt text itself. Prompt text may contain --- and that must remain literal content.\n\n" .
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

    public static function promptAdjusterSystem(): string
    {
        return "You are a prompt optimization assistant. Return only the requested prompt pack blocks.\n" .
            "Use XML-style wrapper tags exactly as requested. Do not use markdown fences and do not use section separators.\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags (for example keep {% verbatim %}{{ content }}{% endverbatim %} exactly as provided).\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.";
    }
}
