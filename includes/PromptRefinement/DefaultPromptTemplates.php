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
            "Always start with `Score: N/100`. Do not return JSON. Focus on reusable prompt-level causes, not text-specific rewrites.\n" .
            "Treat the refinement criteria as user intent that may be vague or partially wrong. Diagnose missing instructions, conflicting instructions, instruction overload, downstream contract mismatch, and model noncompliance separately. Do not assume adding another rule is the best fix.";
    }

    public static function assistantEvaluator(): string
    {
        return "You will receive a system prompt, user message and assistant output.\n" .
            "Primary purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}\n\n" .
            "Evaluate whether the assistant still fulfills the primary purpose while also improving toward the refinement criteria. Do not reward a response that satisfies the refinement criteria by abandoning the primary purpose.\n" .
            "If the criteria would encourage prompt bloat, over-constraining, or conflicting requirements, say so and recommend a better interpretation of the criteria. If the assistant violates a rule that already exists in the prompt, do not merely recommend restating that rule more strongly; identify whether the real fix is simplification, clearer priority order, examples, validation outside the prompt, or a contract change.\n\n" .
            "Write a structured diagnostic report. Do not write the final improved prompt. Do not give only one suggestion. Suggestions must generalize across future inputs and must be based on prompt behavior, not this specific text alone.\n\n" .
            "Use exactly these sections:\n" .
            "Score: N/100\n" .
            "Overall judgment:\n" .
            "Constraint diagnosis:\n" .
            "What helped:\n" .
            "What hurt:\n" .
            "Likely prompt-level cause:\n" .
            "Prompt changes likely to improve future outputs:\n" .
            "Risks / safeguards:\n\n" .
            "In `Constraint diagnosis`, explicitly classify the main problem as one or more of: missing instruction, conflicting instructions, instruction overload, downstream contract mismatch, or model noncompliance despite clear instructions.\n" .
            "In `Prompt changes likely to improve future outputs`, prefer the smallest effective prompt change. Include what to remove, merge, simplify, or prioritize when relevant. List multiple reusable mechanisms when relevant, such as clearer success criteria, target-language checks, source-language interference checks, priority order, preservation rules, validation outside the prompt, or examples of acceptable versus unacceptable behavior. Avoid advice that only rewrites this one input. Avoid recommending longer prompts unless the added text resolves a specific failure that cannot be solved by simplifying existing instructions.\n\n" .
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
            "Adjust the prompts to improve the refinement criteria without narrowing the prompt so much that it stops fulfilling the primary purpose.\n" .
            "Treat the criteria as user intent, not as a literal command to add more text. If the criteria is vague, optimize for a shorter, clearer, more reliable prompt that preserves the primary purpose.\n\n" .
            "Use `Refinement history JSON` to compare previous prompt versions and scores. If the current evaluation is worse than an earlier version, consider reverting or partially undoing changes that likely caused the regression.\n\n" .
            "Prefer compact prompt changes. Do not append new checklist sections unless they replace weaker existing wording. If an existing rule was violated, do not merely restate it more strongly; simplify the hierarchy, remove conflicting incentives, add one concrete decision rule, or preserve the existing prompt and explain that validation/workflow mechanics are needed outside the prompt.\n" .
            "Before changing the prompt, decide whether the best fix is: add a missing rule, remove a conflicting rule, make priority order explicit, shorten/merge duplicated rules, add one example, or avoid changing the prompt because the workflow contract is unstable.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return the improved prompt pack using these XML-style wrapper tags, with no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "You may include a short <change_summary> before the prompt blocks. In it, state whether you shortened, merged, reverted, reprioritized, or minimally added instructions. The prompt blocks are authoritative. Do not put the wrapper tag names inside the prompt text itself. Prompt text may contain --- and that must remain literal content.\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Refinement history JSON:\n{{ refinement_history_json }}\n\n" .
            "Evaluations JSON:\n{{ evaluations_json }}";
    }

    public static function workflowEvaluatorSystem(): string
    {
        return "You are a diagnostic workflow evaluator. Write natural-language feedback for a prompt adjuster, not final prompt text.\n" .
            "Always start with `Score: N/100`. Do not return JSON. Focus on reusable target-step prompt causes, not text-specific rewrites.\n" .
            "Treat the refinement criteria as user intent that may be vague or partially wrong. Diagnose missing instructions, conflicting instructions, instruction overload, downstream contract mismatch, and model noncompliance separately. Do not assume adding another rule is the best fix.";
    }

    public static function workflowEvaluator(): string
    {
        return "You evaluate one selected assistant step inside a larger workflow. The selected step may be a managed assistant or a custom inline AI assistant step.\n" .
            "The selected target step is the only prompt that may be adjusted later, but judge it by its contribution to the complete workflow outcome.\n" .
            "Use the workflow context to understand what happened before the target step, what the target step produced, how output actions changed workflow variables, and what later steps did with that output.\n" .
            "Whole-workflow purpose that must remain satisfied: {{ workflow_purpose }}\n" .
            "Selected target-step purpose that must remain satisfied: {{ prompt_objective }}\n" .
            "Refinement criteria to improve: {{ criteria }}\n\n" .
            "Evaluate whether the full workflow still fulfills its whole-workflow purpose and whether the selected step still fulfills its own purpose while also improving toward the refinement criteria. Do not reward changes that satisfy the refinement criteria by breaking either purpose.\n" .
            "If the criteria would encourage prompt bloat, over-constraining, or conflicting requirements, say so and recommend a better interpretation of the criteria. If the selected step violates a rule that already exists in the prompt, do not merely recommend restating that rule more strongly; identify whether the real fix is simplification, clearer priority order, examples, validation outside the prompt, or a workflow contract change.\n\n" .
            "Write a structured diagnostic report. Do not write the final improved prompt. Do not give only one suggestion. Suggestions must generalize across future workflow runs and must be based on target-step prompt behavior, not this specific post alone.\n" .
            "Separate target-step prompt issues from issues caused by previous or following workflow steps. Only recommend changes for the selected target step when the workflow evidence supports that causal link. If a problem belongs elsewhere, say so under `Not caused by target step` and do not turn it into a target-step prompt change.\n\n" .
            "Use exactly these sections:\n" .
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
            "In `Constraint diagnosis`, explicitly classify the main problem as one or more of: missing instruction, conflicting instructions, instruction overload, downstream contract mismatch, or model noncompliance despite clear instructions.\n" .
            "In `Prompt changes likely to improve future workflow outputs`, prefer the smallest effective prompt change. Include what to remove, merge, simplify, or prioritize when relevant. List multiple reusable mechanisms when relevant, such as clearer target-language requirements, better use of prior-step variables, priority order, preservation rules, validation outside the prompt, workflow contract changes, or examples of acceptable versus unacceptable target-step behavior. Avoid recommending longer prompts unless the added text resolves a specific failure that cannot be solved by simplifying existing instructions.\n\n" .
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
            "Treat the criteria as user intent, not as a literal command to add more text. If the criteria is vague, optimize for a shorter, clearer, more reliable target-step prompt that preserves the workflow role.\n\n" .
            "Use workflow context to understand which steps run before the selected target step, which steps run after it, what each step's prompts look like, whether each assistant returns JSON or text, and how output actions write data into workflow variables, post fields or meta.\n" .
            "Adjust only the selected target assistant prompt pack. Do not rewrite prompts for previous or following workflow steps. Do not narrow the selected prompt so much that it stops fulfilling its primary workflow role.\n" .
            "If a problem belongs to another workflow step, mention it indirectly only by making the target prompt produce clearer or more useful output for that later step.\n\n" .
            "Use `Refinement history JSON` to compare previous target-step prompt versions and scores. If the current workflow evaluation is worse than an earlier version, consider reverting or partially undoing target-step prompt changes that likely caused the regression.\n\n" .
            "Prefer compact prompt changes. Do not append new checklist sections unless they replace weaker existing wording. If an existing rule was violated, do not merely restate it more strongly; simplify the hierarchy, remove conflicting incentives, add one concrete decision rule, or preserve the existing prompt and explain that validation/workflow mechanics are needed outside the prompt.\n" .
            "Before changing the prompt, decide whether the best fix is: add a missing rule, remove a conflicting rule, make priority order explicit, shorten/merge duplicated rules, add one example, or avoid changing the prompt because the workflow contract is unstable.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return the improved target-step prompt pack using these XML-style wrapper tags, with no markdown fences:\n" .
            "<system_prompt>\nImproved system prompt text\n</system_prompt>\n\n" .
            "<user_message_template>\nImproved user message template text\n</user_message_template>\n\n" .
            "{% if adjust_expected_output_schema %}<expected_output_schema>\nImproved expected output schema as JSON object text or JSON schema text\n</expected_output_schema>\n\n{% endif %}" .
            "You may include a short <change_summary> before the prompt blocks. In it, state whether you shortened, merged, reverted, reprioritized, or minimally added instructions. The prompt blocks are authoritative. Do not put the wrapper tag names inside the prompt text itself. Prompt text may contain --- and that must remain literal content.\n\n" .
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
            "The criterion must describe an observable quality of the selected step's output or the whole workflow result.\n" .
            "Prefer domain-quality measures such as error coverage, precision, completeness, factuality, linguistic naturalness, consistency, actionability, false positives, or downstream usability.\n" .
            "If the user's criterion is broad, keep the rewritten criterion broad and measurable; do not infer a narrow step contract from workflow prompts.\n" .
            "Use workflow context only to identify the domain and quality target. Do not copy magic phrases, output formats, structural requirements, or exact mechanics from the workflow unless the user's criterion explicitly asks for them.\n" .
            "Do not output meta-advice about prompt length, prompt bloat, priority order, validation, or implementation mechanics unless that is the user's actual quality goal.\n" .
            "Do not solve the workflow task. Do not produce target prompt content, schemas, examples, checklists, implementation details, or task procedures.\n" .
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
            "The rewritten criterion must replace the user's current criteria, not create a second parallel criterion.\n" .
            "Make it directly usable as an evaluator criterion: name the observable result to score and, when useful, the direction of improvement such as more detected issues, fewer false positives, more complete coverage, clearer actionable notes, better linguistic naturalness, stronger factual consistency, or better downstream usability.\n" .
            "If the current user criteria is vague or broad, preserve that breadth while making the measurable quality dimensions explicit. Do not turn a broad goal into a narrow implementation or output-contract requirement.\n" .
            "Ground it in the workflow purpose and selected target-step purpose, but keep it about output quality, not about how to edit the prompt or how the selected step should perform the task.\n" .
            "Workflow prompts may contain formats, magic phrases, labels, and mechanical procedures. Treat those as context for understanding the task, not as criteria to import, unless the user's current criterion explicitly asks to improve contract compliance.\n" .
            "Do not write the improved target prompt. Do not introduce concrete output-contract details unless the user's current criterion is explicitly about contract compliance. Do not mention this instruction.\n" .
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
            "Use XML-style wrapper tags exactly as requested. Do not use markdown fences and do not use section separators.\n" .
            "Prefer the smallest effective prompt change. Shorter and clearer is usually better than longer. Do not add repeated warnings or checklist sections when a priority rule, simplification, example, or partial revert would solve the issue.\n" .
            "If evaluation feedback shows that a rule already existed but was violated, do not merely restate it more strongly. Resolve conflicts, simplify hierarchy, or preserve the prompt and note when external validation/workflow mechanics are required.\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags (for example keep {% verbatim %}{{ content }}{% endverbatim %} exactly as provided).\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.";
    }
}
