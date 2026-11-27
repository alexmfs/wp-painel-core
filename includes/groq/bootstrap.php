<?php
if (!defined('ABSPATH')) exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AI\Connector_Approval\Approvals_Store;

$_painel_ai_provider_map = [
    'groq'   => [
        'class'           => 'Groq_Provider',
        'name'            => 'Groq',
        'credentials_url' => 'https://console.groq.com/keys',
        'env_key'         => 'GROQ_API_KEY',
    ],
    'openai' => [
        'class'           => 'OpenAI_Provider',
        'name'            => 'OpenAI',
        'credentials_url' => 'https://platform.openai.com/api-keys',
        'env_key'         => 'OPENAI_API_KEY',
    ],
];

add_action('wp_connectors_init', function (WP_Connector_Registry $registry) use ($_painel_ai_provider_map) {
    $provider = getenv('AI_PROVIDER') ?: 'groq';
    if (!isset($_painel_ai_provider_map[$provider])) return;

    $meta = $_painel_ai_provider_map[$provider];
    $registry->register($provider, [
        'name'           => $meta['name'],
        'description'    => __('AI provider for the assistant.', 'painel-core'),
        'type'           => 'ai_provider',
        'authentication' => [
            'method'          => 'api_key',
            'credentials_url' => $meta['credentials_url'],
            'setting_name'    => "connectors_ai_{$provider}_api_key",
        ],
    ]);
}, 20);

add_action('wp_connectors_init', function () use ($_painel_ai_provider_map) {
    $provider = getenv('AI_PROVIDER') ?: 'groq';
    if (!isset($_painel_ai_provider_map[$provider])) return;

    $meta = $_painel_ai_provider_map[$provider];
    $key  = getenv($meta['env_key']);
    if (empty($key)) return;

    if (class_exists(Approvals_Store::class)) {
        $store = new Approvals_Store();
        if (!$store->is_approved('painel-core/plugin.php', $provider)) {
            $store->set_approval('painel-core/plugin.php', $provider, true);
        }
    }

    $registry = AiClient::defaultRegistry();
    if (!$registry->hasProvider($provider)) {
        $registry->registerProvider($meta['class']);
    }
    $registry->setProviderRequestAuthentication(
        $provider,
        new ApiKeyRequestAuthentication($key)
    );
}, 30);
