<?php
/**
 * Plugin Name: Painel Core
 * Plugin URI:  https://github.com/rockhub
 * Description: CPTs, tabelas customizadas e REST API do painel headless.
 * Version:     1.0.0
 * Author:      rockhub
 * Text Domain: painel-core
 */

if (!defined('ABSPATH')) exit;

define('PAINEL_PLUGIN_FILE', __FILE__);
define('PAINEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAINEL_VERSION', '1.2.0');
define('RHUB_DB_PREFIX', 'rhub_');

// Habilita a lixeira e evita que seja esvaziada automaticamente (mantendo por 100 anos)
if (!defined('EMPTY_TRASH_DAYS')) {
    define('EMPTY_TRASH_DAYS', 36500);
}

require_once PAINEL_PLUGIN_DIR . 'loader.php';

// Estende o access token JWT para 8 horas (padrão do plugin é 10 minutos)
add_filter('jwt_auth_expire', fn($expire, $issued_at) => $issued_at + HOUR_IN_SECONDS * 8, 10, 2);

// Adiciona margem de tolerância (leeway) de 120s para evitar falhas de autenticação decorrentes de dessincronização de relógio (ex: localhost/Docker)
add_action('plugins_loaded', function () {
    if (class_exists('Firebase\JWT\JWT')) {
        Firebase\JWT\JWT::$leeway = 120;
    }
});

// Redireciona todo o tráfego do frontend do WordPress para o frontend Angular (Headless)
add_action('template_redirect', function () {
    if (!is_admin() && !defined('REST_REQUEST') && !in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php'])) {
        $frontend_url = getenv('ALLOWED_ORIGIN') ?: 'http://localhost:4200';
        wp_redirect($frontend_url, 301);
        exit;
    }
});

// Adiciona o campo customizado 'menu_type' na API de Post Types (/wp-json/wp/v2/types)
add_action('rest_api_init', function () {
    register_rest_field('type', 'menu_type', [
        'get_callback' => function ($post_type_object) {
            // Se o post type possuir o parâmetro menu_type definido, retorna ele. Caso contrário, retorna null.
            return isset($post_type_object->menu_type) ? $post_type_object->menu_type : null;
        },
        'schema' => [
            'description' => 'Determina em qual seção do menu o post type deve aparecer.',
            'type'        => 'string',
            'enum'        => ['entidade', 'capacidade'],
            'context'     => ['view', 'edit'],
        ],
    ]);
});
