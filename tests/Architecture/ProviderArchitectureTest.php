<?php

declare(strict_types=1);

/**
 * Architecture Tests: Provider System
 *
 * Ensures translation provider system is swappable
 */

arch('translation providers do not cross-depend')
    ->expect('PolyTrans\\Providers\\OpenAI')
    ->not()->toUse('PolyTrans\\Providers\\Google')
    ->and('PolyTrans\\Providers\\Google')
    ->not()->toUse('PolyTrans\\Providers\\OpenAI');

arch('providers are in dedicated namespace')
    ->expect('PolyTrans\\Providers')
    ->toOnlyUse([
        'PolyTrans\\Providers',
        'PolyTrans\\Core',
        'PolyTrans\\Assistants',
        'PolyTrans\\PostProcessing',
        'WP_Error',
        'WP_REST_Response',
        'WP_REST_Request',
        '__',
        'absint',
        'add_action',
        'admin_url',
        'current_user_can',
        'esc_attr',
        'esc_attr__',
        'esc_html',
        'esc_html__',
        'esc_html_e',
        'esc_url',
        'get_option',
        'get_transient',
        'is_wp_error',
        'sanitize_text_field',
        'selected',
        'set_transient',
        'wp_add_inline_script',
        'wp_add_inline_style',
        'wp_die',
        'wp_json_encode',
        'wp_remote_get',
        'wp_remote_post',
        'wp_remote_retrieve_body',
        'wp_remote_retrieve_header',
        'wp_remote_retrieve_response_code',
        'wp_send_json_error',
        'wp_send_json_success',
        'wp_unslash',
        'wp_verify_nonce',
        // Standard PHP/vendor
        'Psr',
        'GuzzleHttp',
        'OpenAI',
    ]);
