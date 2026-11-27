<?php
if (!defined('ABSPATH')) exit;

$includes = [
    'functions.php',
    'mail.php',
    'emails.php',
    'security-hardening.php',
    'custom-tables.php',
    'rest-endpoints.php',
    'term-thumbnail.php',
    'webp-convert.php',
    'includes/otp.php',
    'includes/abilities.php',
    'includes/class-posts-controller.php',
    'includes/shared-fields.php',
    'includes/groq/class-groq-model-directory.php',
    'includes/groq/class-groq-text-model.php',
    'includes/groq/class-groq-provider.php',
    'includes/openai/class-openai-model-directory.php',
    'includes/openai/class-openai-text-model.php',
    'includes/openai/class-openai-provider.php',
    'includes/groq/bootstrap.php',
    'options-pages-test.php',
];

// CPT modules — cada pasta em includes/ tem cpt.php e fields.php.
// A option painel_active_cpts, quando definida, restringe quais módulos carregam.
// Ex: update_option('painel_active_cpts', ['lead', 'company']) — nomes de pasta em includes/.
// Quando a option não existe (null), todos os módulos carregam (comportamento padrão do RockHub).
$cpt_modules = glob(PAINEL_PLUGIN_DIR . 'includes/*/cpt.php') ?: [];
$field_modules = glob(PAINEL_PLUGIN_DIR . 'includes/*/fields.php') ?: [];

// CPTs gerenciados por plugins externos — excluídos do loader do core.
// catalog, person, company, lead, proposal → painel-entities
// task → sem plugin ativo no momento
$entity_slugs = ['person', 'company', 'catalog', 'lead', 'proposal', 'task'];
$exclude_entities = fn($f) => !in_array(basename(dirname($f)), $entity_slugs, true);
$cpt_modules   = array_filter($cpt_modules, $exclude_entities);
$field_modules = array_filter($field_modules, $exclude_entities);

$active_cpts = get_option('painel_active_cpts', null);
if (is_array($active_cpts)) {
    $filter_fn = fn($f) => in_array(basename(dirname($f)), $active_cpts, true);
    $cpt_modules   = array_filter($cpt_modules, $filter_fn);
    $field_modules = array_filter($field_modules, $filter_fn);
}

foreach ($includes as $file) {
    $path = PAINEL_PLUGIN_DIR . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

foreach (array_merge($cpt_modules, $field_modules) as $module) {
    require_once $module;
}

// Taxonomia de origem das mídias
add_action('init', function () {
    register_taxonomy('media_origin', 'attachment', [
        'label'        => 'Origem',
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => true,
        'hierarchical' => false,
        'rewrite'      => false,
    ]);
}, 5);

// Auto-registra taxonomia de status para CPTs com list_type = 'kanban'
add_action('init', function () {
    foreach (get_post_types([], 'objects') as $obj) {
        if (($obj->list_type ?? 'default') !== 'kanban') continue;
        $tax = 'status_' . $obj->name;
        if (taxonomy_exists($tax)) continue;
        register_taxonomy($tax, [$obj->name], [
            'label'        => 'Status',
            'public'       => false,
            'show_ui'      => false,
            'show_in_rest' => true,
            'hierarchical' => false,
            'rewrite'      => false,
        ]);
    }
}, 20);

// Aplica status padrão (slug de termo) em posts kanban criados sem status via REST
add_action('rest_insert_post', function (WP_Post $post, WP_REST_Request $req, bool $creating) {
    if (!$creating) return;

    $pt = get_post_type_object($post->post_type);
    if (!$pt || ($pt->list_type ?? 'default') !== 'kanban') return;

    $kanban_field = $pt->kanban_field ?? null;
    if (!$kanban_field) return;

    $current = get_post_meta($post->ID, $kanban_field, true);
    if (!empty($current)) return;

    $defaults = (array) get_option('painel_kanban_defaults', []);
    $default  = $defaults[$post->post_type] ?? '';
    if (empty($default)) return;

    update_post_meta($post->ID, $kanban_field, $default);
}, 10, 3);
