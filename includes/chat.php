<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('rh/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => 'painel_rest_chat',
        'permission_callback' => 'painel_auth',
    ]);
});

function painel_rest_chat(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $message = trim($r->get_param('message') ?? '');
    $context = trim($r->get_param('context') ?? '');
    $history = (array) ($r->get_param('history') ?? []);

    if (empty($message)) {
        return new WP_Error('empty_message', 'Mensagem vazia.', ['status' => 400]);
    }

    $provider = getenv('AI_PROVIDER') ?: 'groq';

    $providers = [
        'groq'   => ['url' => 'https://api.groq.com/openai/v1/chat/completions', 'key_env' => 'GROQ_API_KEY',   'model' => 'llama-3.3-70b-versatile'],
        'openai' => ['url' => 'https://api.openai.com/v1/chat/completions',      'key_env' => 'OPENAI_API_KEY', 'model' => 'gpt-4o-mini'],
    ];

    if (!isset($providers[$provider])) {
        return new WP_Error('no_provider', 'Provedor de IA não configurado.', ['status' => 503]);
    }

    $cfg = $providers[$provider];
    $key = getenv($cfg['key_env']);

    if (empty($key)) {
        return new WP_Error('no_key', 'Chave de IA não configurada.', ['status' => 503]);
    }

    $system = 'Você é um assistente de análise de marketing digital. Responda em português, de forma direta e objetiva.';
    if (!empty($context)) {
        $system .= "\n\nDados atuais do dashboard:\n" . $context;
    }

    $messages = [['role' => 'system', 'content' => $system]];

    foreach ($history as $h) {
        $role    = in_array($h['role'] ?? '', ['user', 'assistant'], true) ? $h['role'] : 'user';
        $content = trim($h['content'] ?? '');
        if ($content) {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }

    $messages[] = ['role' => 'user', 'content' => $message];

    $resp = wp_remote_post($cfg['url'], [
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode([
            'model'       => $cfg['model'],
            'messages'    => $messages,
            'max_tokens'  => 1024,
            'temperature' => 0.7,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        return new WP_Error('ai_error', 'Erro ao comunicar com a IA.', ['status' => 502]);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code !== 200) {
        return new WP_Error('ai_error', $body['error']['message'] ?? 'Erro na IA.', ['status' => $code]);
    }

    return new WP_REST_Response(['reply' => $body['choices'][0]['message']['content'] ?? ''], 200);
}
