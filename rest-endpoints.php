<?php
if (!defined('ABSPATH')) exit;

// ── Register custom post status 'history' ─────────────────────────────────────

add_action('init', function () {
    register_post_status('history', [
        'label'                     => 'Histórico',
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => false,
        'show_in_admin_status_list' => false,
        'show_in_rest'              => true,
        'label_count'               => _n_noop('Históricos (%s)', 'Históricos (%s)', 'painel-core'),
    ]);
});

// ── Proposal Versioning Hook ──────────────────────────────────────────────────

add_filter('rest_pre_insert_proposal', function ($prepared_post, $request) {
    // Only intercept updates (where ID exists)
    $post_id = $request->get_param('id');
    if (!$post_id) {
        return $prepared_post;
    }

    $current_post = get_post($post_id);
    if (!$current_post) {
        return $prepared_post;
    }

    // If the database status is currently 'publish', clone the old version before applying new edits
    if ($current_post->post_status === 'publish') {
        painel_clone_proposal_version((int) $post_id);
    }

    return $prepared_post;
}, 10, 2);

function painel_clone_proposal_version(int $post_id): void
{
    global $wpdb;
    $post = get_post($post_id);
    if (!$post) return;

    // 1. Clone the main post row
    $cloned_post_id = wp_insert_post([
        'post_type'    => $post->post_type,
        'post_title'   => $post->post_title,
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
        'post_status'  => 'history',
        'post_author'  => $post->post_author,
        'post_parent'  => $post->post_parent,
        'post_date'    => $post->post_date,
    ]);

    if (is_wp_error($cloned_post_id)) {
        return;
    }

    // 2. Clone post meta keys (CMB2, etc)
    $metas = get_post_meta($post_id);
    if ($metas) {
        foreach ($metas as $key => $values) {
            foreach ($values as $value) {
                $value = maybe_unserialize($value);
                add_post_meta($cloned_post_id, $key, $value);
            }
        }
    }

    // 3. Clone groups and group_items
    $p = RHUB_DB_PREFIX;
    $groups = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$p}groups WHERE post_id = %d",
        $post_id
    ));

    if ($groups) {
        foreach ($groups as $group) {
            $wpdb->insert("{$p}groups", [
                'post_id'    => $cloned_post_id,
                'post_type'  => $group->post_type,
                'title'      => $group->title,
                'sort_order' => $group->sort_order,
            ]);
            $new_group_id = $wpdb->insert_id;

            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$p}group_items WHERE group_id = %d",
                $group->id
            ));

            if ($items) {
                foreach ($items as $item) {
                    $wpdb->insert("{$p}group_items", [
                        'group_id'   => $new_group_id,
                        'ref_id'     => $item->ref_id,
                        'ref_type'   => $item->ref_type,
                        'description'=> $item->description,
                        'quantity'   => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'sort_order' => $item->sort_order,
                        'meta'       => $item->meta,
                    ]);
                }
            }
        }
    }

    // 4. Log in event log
    $current_user = wp_get_current_user();
    $author_name = $current_user->ID ? ($current_user->display_name ?: $current_user->user_login) : 'Sistema';
    painel_log(
        'proposal_version',
        $post_id,
        'proposal',
        [
            'version_post_id' => $cloned_post_id,
            'author_name'     => $author_name,
            'version_date'    => current_time('mysql'),
        ]
    );
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

// ── Proteção: impede exclusão permanente de CPTs com soft_delete_only ───────

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($result !== null) return $result;
    if ($request->get_method() !== 'DELETE') return $result;
    if (!$request->get_param('force')) return $result;

    preg_match('#/wp/v2/([^/]+)/(\d+)#', $request->get_route(), $m);
    if (empty($m[2])) return $result;

    $post = get_post((int) $m[2]);
    if (!$post) return $result;

    $pt_obj = get_post_type_object($post->post_type);
    if (!empty($pt_obj->soft_delete_only)) {
        return new WP_Error(
            'soft_delete_only',
            'Este registro não pode ser excluído permanentemente.',
            ['status' => 403]
        );
    }

    return $result;
}, 10, 3);

// ── Namespace: /wp-json/rh/v1/ ──────────────────────────────────────────────

add_action('rest_api_init', function () {

    // setup
    register_rest_route('rh/v1', '/setup/permalinks', [
        'methods'             => 'POST',
        'callback'            => 'painel_rest_setup_permalinks',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    // config pública (pré-login)
    register_rest_route('rh/v1', '/auth-config', [
        'methods'             => 'GET',
        'callback'            => fn() => new WP_REST_Response(['auth_mode' => painel_auth_mode()], 200),
        'permission_callback' => '__return_true',
    ]);

    // verificação de identidade (re-autenticação para ações sensíveis)
    register_rest_route('rh/v1', '/verify-identity', [
        'methods'             => 'POST',
        'callback'            => 'rh_rest_verify_identity',
        'permission_callback' => 'painel_auth',
    ]);

    // perfil do usuário autenticado — acessível a qualquer role
    register_rest_route('rh/v1', '/me', [
        'methods'             => 'GET',
        'callback'            => function () {
            $user = wp_get_current_user();
            if (!$user->ID) {
                return new WP_Error('not_logged_in', 'Não autenticado.', ['status' => 401]);
            }
            return new WP_REST_Response([
                'id'           => $user->ID,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
            ], 200);
        },
        'permission_callback' => fn() => current_user_can('read'),
    ]);

    // lost password
    register_rest_route('rh/v1', '/lost-password', [
        'methods'             => 'POST',
        'callback'            => 'painel_rest_lost_password',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('rh/v1', '/reset-password', [
        'methods'             => 'POST',
        'callback'            => 'painel_rest_reset_password',
        'permission_callback' => '__return_true',
    ]);

    // relations
    register_rest_route('rh/v1', '/relations', [
        ['methods' => 'GET',  'callback' => 'painel_rest_list_relations',  'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'painel_rest_create_relation', 'permission_callback' => 'painel_auth'],
    ]);

    register_rest_route('rh/v1', '/relations/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'painel_rest_delete_relation',
        'permission_callback' => 'painel_auth',
    ]);

    // bootstrap — config completa em uma requisição
    register_rest_route('rh/v1', '/bootstrap', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_bootstrap',
        'permission_callback' => 'painel_auth',
    ]);

    // lista todos os CPTs visíveis no nav (autenticado, sem exigir edit_posts)
    register_rest_route('rh/v1', '/types', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_list_types',
        'permission_callback' => 'painel_auth',
    ]);

    // tipo: supports + taxonomies de um CPT (público)
    register_rest_route('rh/v1', '/types/(?P<post_type>[a-z_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_get_type',
        'permission_callback' => '__return_true',
    ]);

    // fields (definição dos CPT fields via CMB2)
    register_rest_route('rh/v1', '/fields/(?P<post_type>[a-z_]+)', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_get_fields',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('rh/v1', '/term-fields/(?P<taxonomy>[a-z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_get_term_fields',
        'permission_callback' => '__return_true',
    ]);

    // groups
    register_rest_route('rh/v1', '/groups', [
        ['methods' => 'GET',  'callback' => 'painel_rest_list_groups',  'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'painel_rest_create_group', 'permission_callback' => 'painel_auth'],
    ]);
    register_rest_route('rh/v1', '/groups/(?P<id>\d+)', [
        ['methods' => 'PUT',    'callback' => 'painel_rest_update_group', 'permission_callback' => 'painel_auth'],
        ['methods' => 'DELETE', 'callback' => 'painel_rest_delete_group', 'permission_callback' => 'painel_auth'],
    ]);

    // group-items
    register_rest_route('rh/v1', '/group-items', [
        ['methods' => 'POST', 'callback' => 'painel_rest_create_group_item', 'permission_callback' => 'painel_auth'],
    ]);
    register_rest_route('rh/v1', '/group-items/(?P<id>\d+)', [
        ['methods' => 'PUT',    'callback' => 'painel_rest_update_group_item', 'permission_callback' => 'painel_auth'],
        ['methods' => 'DELETE', 'callback' => 'painel_rest_delete_group_item', 'permission_callback' => 'painel_auth'],
    ]);

    // settings
    register_rest_route('rh/v1', '/settings', [
        ['methods' => 'GET',  'callback' => 'painel_rest_list_settings',  'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'painel_rest_upsert_setting', 'permission_callback' => 'painel_auth'],
    ]);

    // media origin
    register_rest_route('rh/v1', '/media/(?P<id>\d+)/origin', [
        'methods'             => 'POST',
        'callback'            => 'painel_rest_set_media_origin',
        'permission_callback' => 'painel_auth',
    ]);

    register_rest_route('rh/v1', '/media/origins', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_list_media_origins',
        'permission_callback' => 'painel_auth',
    ]);

    // users (CRUD com controle de role)
    register_rest_route('rh/v1', '/users', [
        ['methods' => 'GET',  'callback' => 'rh_rest_list_painel_users',  'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'rh_rest_create_painel_user', 'permission_callback' => 'painel_auth'],
    ]);
    register_rest_route('rh/v1', '/users/(?P<id>\d+)', [
        ['methods' => 'POST',   'callback' => 'rh_rest_update_painel_user', 'permission_callback' => 'painel_auth'],
        ['methods' => 'DELETE', 'callback' => 'rh_rest_delete_painel_user', 'permission_callback' => 'painel_auth'],
    ]);

    // roles
    register_rest_route('rh/v1', '/roles', [
        ['methods' => 'GET',  'callback' => 'painel_rest_list_roles',  'permission_callback' => fn() => current_user_can('list_users')],
        ['methods' => 'POST', 'callback' => 'painel_rest_create_role', 'permission_callback' => fn() => current_user_can('manage_options')],
    ]);
    register_rest_route('rh/v1', '/roles/(?P<slug>[a-z0-9_-]+)', [
        ['methods' => 'PUT',    'callback' => 'painel_rest_update_role', 'permission_callback' => fn() => current_user_can('manage_options')],
        ['methods' => 'DELETE', 'callback' => 'painel_rest_delete_role', 'permission_callback' => fn() => current_user_can('manage_options')],
    ]);
    register_rest_route('rh/v1', '/roles/(?P<slug>[a-z0-9_-]+)/capabilities', [
        ['methods' => 'GET', 'callback' => 'painel_rest_get_role_caps',    'permission_callback' => fn() => current_user_can('manage_options')],
        ['methods' => 'PUT', 'callback' => 'painel_rest_update_role_caps', 'permission_callback' => fn() => current_user_can('manage_options')],
    ]);

    // user groups
    register_rest_route('rh/v1', '/user-groups', [
        ['methods' => 'GET',  'callback' => 'painel_rest_list_user_groups',  'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'painel_rest_create_user_group', 'permission_callback' => 'painel_auth'],
    ]);
    register_rest_route('rh/v1', '/user-groups/(?P<id>\d+)', [
        ['methods' => 'PUT',    'callback' => 'painel_rest_update_user_group', 'permission_callback' => 'painel_auth'],
        ['methods' => 'DELETE', 'callback' => 'painel_rest_delete_user_group', 'permission_callback' => 'painel_auth'],
    ]);
    register_rest_route('rh/v1', '/user-groups/(?P<id>\d+)/members', [
        ['methods' => 'GET',  'callback' => 'painel_rest_list_group_members', 'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'painel_rest_add_group_member',   'permission_callback' => 'painel_auth'],
    ]);
    register_rest_route('rh/v1', '/user-groups/(?P<id>\d+)/members/(?P<user_id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'painel_rest_remove_group_member',
        'permission_callback' => 'painel_auth',
    ]);

    // avatar attachment id on users
    register_rest_field('user', 'avatar_attachment_id', [
        'get_callback'    => fn($u) => (int) get_user_meta($u['id'], '_avatar_attachment_id', true),
        'update_callback' => function ($value, WP_User $user) {
            if ($value) update_user_meta($user->ID, '_avatar_attachment_id', (int) $value);
            else        delete_user_meta($user->ID, '_avatar_attachment_id');
        },
        'schema' => ['type' => 'integer'],
    ]);

    // chat
    register_rest_route('rh/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => 'painel_rest_chat',
        'permission_callback' => 'painel_auth',
    ]);

    // event_log
    register_rest_route('rh/v1', '/event-log', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_list_events',
        'permission_callback' => 'painel_auth',
    ]);

    // config: GET retorna config global, POST salva
    register_rest_route('rh/v1', '/config', [
        [
            'methods'             => 'GET',
            'callback'            => 'painel_rest_get_config',
            'permission_callback' => 'painel_auth',
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'painel_rest_save_config',
            'permission_callback' => fn() => current_user_can('manage_options'),
        ],
    ]);

    // bi and drive endpoints are registered by their own plugins (painel-bi, painel-drive)

    // oauth connections
    register_rest_route('rh/v1', '/connections', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_list_connections',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-omnichannel/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_omnichannel_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-drive/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_drive_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/gmail/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_gmail_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-analytics/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_analytics_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-ads/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_ads_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-calendar/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_calendar_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-meet/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_meet_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google-contacts/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_contacts_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/youtube/auth-url', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_youtube_auth_url',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/connections/google/callback', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_google_callback',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('rh/v1', '/connections/(?P<provider>[a-z_-]+)', [
        'methods'             => 'DELETE',
        'callback'            => 'painel_rest_disconnect_provider',
        'permission_callback' => 'painel_auth',
    ]);

    // plugins
    register_rest_route('rh/v1', '/plugins', [
        [
            'methods'             => 'GET',
            'callback'            => 'painel_rest_list_plugins',
            'permission_callback' => fn() => current_user_can('manage_options'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'painel_rest_toggle_plugin',
            'permission_callback' => fn() => current_user_can('manage_options'),
        ],
    ]);

    // docs
    register_rest_route('rh/v1', '/docs', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_docs',
        'permission_callback' => 'painel_auth',
    ]);

    // subscription
    register_rest_route('rh/v1', '/subscription', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_list_subscription',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
    register_rest_route('rh/v1', '/subscription/(?P<slug>[a-z0-9_-]+)', [
        'methods'             => 'PUT',
        'callback'            => 'painel_rest_update_subscription',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    // options pages
    register_rest_route('rh/v1', '/options-pages', [
        'methods'             => 'GET',
        'callback'            => 'painel_rest_list_options_pages',
        'permission_callback' => 'painel_auth',
    ]);
    register_rest_route('rh/v1', '/options-pages/(?P<key>[a-z0-9_-]+)', [
        ['methods' => 'GET',  'callback' => 'painel_rest_get_options_page',  'permission_callback' => 'painel_auth'],
        ['methods' => 'POST', 'callback' => 'painel_rest_save_options_page', 'permission_callback' => 'painel_auth'],
    ]);
});

// ── Subscription ─────────────────────────────────────────────────────────────

function painel_rest_list_subscription(): WP_REST_Response
{
    $active_plugins = get_option('active_plugins', []);
    $t              = RHUB_DB_PREFIX . 'settings';
    $today          = current_time('Y-m-d');

    global $wpdb;

    $items = [];
    foreach ($active_plugins as $plugin_file) {
        $file_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (!file_exists($file_path)) continue;

        $data = get_file_data($file_path, [
            'Name'        => 'Plugin Name',
            'Description' => 'Description',
            'Painel'      => 'Painel',
            'Price'       => 'Price',
        ]);

        if (empty($data['Painel'])) continue;

        $slug = dirname($plugin_file) ?: sanitize_title($data['Name']);
        $key  = 'sub_' . $slug;
        $raw  = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM {$t} WHERE setting_key = %s", $key));
        $sub  = $raw ? json_decode($raw, true) : [];

        $items[] = [
            'slug'         => $slug,
            'name'         => $data['Name'],
            'description'  => $data['Description'],
            'price'        => $data['Price'] ?: null,
            'start_date'   => $sub['start_date']   ?? $today,
            'renewal_date' => $sub['renewal_date']  ?? null,
        ];
    }

    return new WP_REST_Response($items, 200);
}

function painel_rest_update_subscription(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    global $wpdb;
    $slug = sanitize_text_field($r['slug']);
    $key  = 'sub_' . $slug;
    $t    = RHUB_DB_PREFIX . 'settings';

    $val = wp_json_encode([
        'start_date'   => sanitize_text_field($r['start_date']   ?? ''),
        'renewal_date' => sanitize_text_field($r['renewal_date'] ?? ''),
    ]);

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE setting_key = %s", $key));
    if ($existing) {
        $wpdb->update($t, ['setting_value' => $val, 'updated_at' => current_time('mysql')], ['setting_key' => $key]);
    } else {
        $wpdb->insert($t, ['setting_key' => $key, 'setting_value' => $val, 'updated_at' => current_time('mysql')]);
    }

    return new WP_REST_Response(['slug' => $slug], 200);
}

// ── Docs ─────────────────────────────────────────────────────────────────────

function painel_rest_docs(): WP_REST_Response
{
    $server    = rest_get_server();
    $all_routes = $server->get_routes('rh/v1');

    $namespaces = ['rh/v1' => [], 'painel/v1' => []];
    foreach ($server->get_routes() as $route => $handlers) {
        foreach (array_keys($namespaces) as $ns) {
            if (str_starts_with($route, "/{$ns}")) {
                $namespaces[$ns][] = ['route' => $route, 'handlers' => $handlers];
            }
        }
    }

    $docs = [];
    foreach ($namespaces as $ns => $entries) {
        foreach ($entries as $entry) {
            $route    = $entry['route'];
            $handlers = $entry['handlers'];
            $methods  = [];
            foreach ($handlers as $handler) {
                foreach (array_keys(WP_REST_Server::READABLE === $handler['methods'] ? ['GET' => 1] : array_flip(explode(', ', $handler['methods']))) as $m) {
                    $methods[] = $m;
                }
            }
            $methods = array_unique(array_merge(...array_map(
                fn($h) => array_keys(array_flip(explode(', ', is_array($h['methods']) ? implode(', ', $h['methods']) : $h['methods']))),
                $handlers
            )));
            sort($methods);
            $docs[] = [
                'namespace' => $ns,
                'route'     => str_replace("/{$ns}", '', $route) ?: '/',
                'full'      => $route,
                'methods'   => $methods,
            ];
        }
    }

    usort($docs, fn($a, $b) => strcmp($a['full'], $b['full']));

    return new WP_REST_Response(['endpoints' => $docs, 'generated_at' => current_time('c')], 200);
}

// ── Setup ─────────────────────────────────────────────────────────────────────

function painel_rest_setup_permalinks(): WP_REST_Response
{
    update_option('permalink_structure', '/%postname%/');
    flush_rewrite_rules();
    return new WP_REST_Response(['ok' => true, 'structure' => '/%postname%/'], 200);
}

// ── Permission helper ────────────────────────────────────────────────────────

function painel_get_gallery_urls(int $post_id, string $meta_key): array {
    $files = get_post_meta($post_id, $meta_key, true);
    if (empty($files) || !is_array($files)) return [];
    return array_values($files);
}

function painel_parse_money(string $value): float
{
    // Suporta formato BR (1.234,56) e US (1,234.56) e inteiros
    $v = trim($value);
    if (str_contains($v, ',') && str_contains($v, '.')) {
        // Descobre qual é o separador decimal pelo último
        $v = strpos($v, ',') > strpos($v, '.') ? str_replace('.', '', $v) : str_replace(',', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '.', $v);
    }
    return (float) $v;
}

function painel_auth(): bool
{
    return is_user_logged_in();
}

// ── Media Origin ──────────────────────────────────────────────────────────────

function painel_rest_set_media_origin(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $attachment_id = (int) $r['id'];
    $origin        = sanitize_text_field($r['origin'] ?? '');

    if (!$origin) {
        return new WP_Error('missing_origin', 'Campo origin é obrigatório.', ['status' => 422]);
    }
    if (get_post_type($attachment_id) !== 'attachment') {
        return new WP_Error('not_found', 'Mídia não encontrada.', ['status' => 404]);
    }

    $slug = sanitize_title($origin);
    $term = get_term_by('slug', $slug, 'media_origin');
    if (!$term) {
        $inserted = wp_insert_term($origin, 'media_origin', ['slug' => $slug]);
        if (is_wp_error($inserted)) return $inserted;
        $term_id = $inserted['term_id'];
    } else {
        $term_id = $term->term_id;
    }

    wp_set_object_terms($attachment_id, [$term_id], 'media_origin');
    return new WP_REST_Response(['ok' => true, 'origin' => $origin], 200);
}

function painel_rest_list_media_origins(): WP_REST_Response
{
    $terms = get_terms(['taxonomy' => 'media_origin', 'hide_empty' => false]);
    if (is_wp_error($terms)) return new WP_REST_Response([], 200);
    $result = array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count], $terms);
    return new WP_REST_Response($result, 200);
}

// ── Verify Identity ───────────────────────────────────────────────────────────

function rh_rest_verify_identity(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $user = wp_get_current_user();
    $mode = painel_auth_mode();

    if ($mode === 'password' || $mode === 'password_otp') {
        $password = $r['password'] ?? '';
        if (!$password || !wp_check_password($password, $user->user_pass, $user->ID)) {
            return new WP_Error('invalid_password', 'Senha incorreta.', ['status' => 401]);
        }
    } elseif ($mode === 'otp') {
        $otp_session = sanitize_text_field($r['otp_session'] ?? '');
        $otp_code    = sanitize_text_field($r['otp_code']    ?? '');
        if (!$otp_session || !$otp_code) {
            return new WP_Error('otp_required', 'otp_session e otp_code são obrigatórios.', ['status' => 422]);
        }
        $transient = get_transient('rhub_otp_' . $otp_session);
        if (!$transient || !isset($transient['user_id']) || (int) $transient['user_id'] !== $user->ID) {
            return new WP_Error('invalid_otp', 'Sessão OTP inválida ou expirada.', ['status' => 401]);
        }
        if (!hash_equals((string) $transient['code'], $otp_code)) {
            return new WP_Error('invalid_otp', 'Código OTP incorreto.', ['status' => 401]);
        }
        delete_transient('rhub_otp_' . $otp_session);
    }

    $token = wp_generate_password(40, false);
    set_transient('rhub_confirm_' . $token, $user->ID, 5 * MINUTE_IN_SECONDS);
    return new WP_REST_Response(['token' => $token], 200);
}

function rh_consume_confirm_token(WP_REST_Request $r): bool|WP_Error
{
    $token = sanitize_text_field($r['confirm_token'] ?? '');
    if (!$token) {
        return new WP_Error('confirm_required', 'Confirmação de identidade necessária.', ['status' => 428]);
    }
    $uid = get_transient('rhub_confirm_' . $token);
    if (!$uid || (int) $uid !== get_current_user_id()) {
        return new WP_Error('invalid_confirm', 'Token de confirmação inválido ou expirado.', ['status' => 401]);
    }
    delete_transient('rhub_confirm_' . $token);
    return true;
}

// ── Painel Users ──────────────────────────────────────────────────────────────

function rh_painel_user_allowed_roles(): array
{
    $current = wp_get_current_user();
    if (in_array('administrator', (array) $current->roles)) {
        global $wp_roles;
        return array_keys($wp_roles->roles);
    }
    if (in_array('gestor', (array) $current->roles)) return ['gestor', 'cliente'];
    return ['cliente'];
}

function rh_format_painel_user(WP_User $u): array
{
    $avatar_id  = (int) get_user_meta($u->ID, 'avatar_attachment_id', true);
    $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, '96') : '';
    if (!$avatar_url) $avatar_url = get_avatar_url($u->ID, ['size' => 96]);
    return [
        'id'                   => $u->ID,
        'name'                 => $u->display_name,
        'slug'                 => $u->user_login,
        'email'                => $u->user_email,
        'roles'                => array_values((array) $u->roles),
        'registered_date'      => $u->user_registered,
        'avatar_urls'          => ['96' => $avatar_url],
        'avatar_attachment_id' => $avatar_id ?: null,
    ];
}

function rh_rest_list_painel_users(WP_REST_Request $r): WP_REST_Response
{
    $args = ['number' => 200, 'orderby' => 'display_name', 'order' => 'ASC'];
    if (!empty($r['role']))   $args['role']   = sanitize_key($r['role']);
    if (!empty($r['search'])) $args['search'] = '*' . sanitize_text_field($r['search']) . '*';

    $users = get_users($args);
    $users = apply_filters('rh_visible_users', $users, wp_get_current_user());

    return new WP_REST_Response(array_values(array_map('rh_format_painel_user', $users)), 200);
}

function rh_rest_create_painel_user(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $allowed = rh_painel_user_allowed_roles();
    $role    = sanitize_key($r['role'] ?? 'cliente');

    if (!in_array($role, $allowed, true)) {
        return new WP_Error('forbidden', 'Você não tem permissão para criar usuários com esse role.', ['status' => 403]);
    }

    if ($role !== 'cliente') {
        $check = rh_consume_confirm_token($r);
        if (is_wp_error($check)) return $check;
    }

    $username = sanitize_user($r['username'] ?? '');
    $email    = sanitize_email($r['email']    ?? '');
    $password = $r['password'] ?? '';
    $name     = sanitize_text_field($r['name'] ?? $username);

    if (!$username || !$email || !$password) {
        return new WP_Error('missing_fields', 'username, email e password são obrigatórios.', ['status' => 422]);
    }

    $id = wp_insert_user(['user_login' => $username, 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'role' => $role]);
    if (is_wp_error($id)) return $id;

    return new WP_REST_Response(rh_format_painel_user(get_userdata($id)), 201);
}

function rh_rest_update_painel_user(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $id   = (int) $r['id'];
    $user = get_userdata($id);
    if (!$user) return new WP_Error('not_found', 'Usuário não encontrado.', ['status' => 404]);

    $data = ['ID' => $id];
    if (isset($r['name']))  $data['display_name'] = sanitize_text_field($r['name']);
    if (isset($r['email'])) $data['user_email']   = sanitize_email($r['email']);
    if (!empty($r['password'])) $data['user_pass'] = $r['password'];

    if (isset($r['role'])) {
        $allowed = rh_painel_user_allowed_roles();
        $role    = sanitize_key($r['role']);
        if (!in_array($role, $allowed, true)) {
            return new WP_Error('forbidden', 'Você não tem permissão para definir esse role.', ['status' => 403]);
        }
        if ($role !== 'cliente') {
            $check = rh_consume_confirm_token($r);
            if (is_wp_error($check)) return $check;
        }
        $data['role'] = $role;
    }

    if (!empty($r['avatar_attachment_id'])) {
        update_user_meta($id, 'avatar_attachment_id', (int) $r['avatar_attachment_id']);
    }

    $result = wp_update_user($data);
    if (is_wp_error($result)) return $result;

    return new WP_REST_Response(rh_format_painel_user(get_userdata($id)), 200);
}

function rh_rest_delete_painel_user(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $id       = (int) $r['id'];
    $reassign = (int) ($r['reassign'] ?? get_current_user_id());

    if ($id === get_current_user_id()) {
        return new WP_Error('forbidden', 'Você não pode deletar sua própria conta.', ['status' => 403]);
    }

    $user = get_userdata($id);
    if (!$user) return new WP_Error('not_found', 'Usuário não encontrado.', ['status' => 404]);

    // gestor/cliente não podem deletar admins
    $current_roles = (array) wp_get_current_user()->roles;
    if (!in_array('administrator', $current_roles) && in_array('administrator', (array) $user->roles)) {
        return new WP_Error('forbidden', 'Sem permissão para deletar esse usuário.', ['status' => 403]);
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($id, $reassign);
    return new WP_REST_Response(['deleted' => true], 200);
}

// ── Roles ─────────────────────────────────────────────────────────────────────

function painel_rest_list_roles(): WP_REST_Response
{
    global $wp_roles;
    $roles = [];
    foreach ($wp_roles->roles as $slug => $role) {
        $count = (int) (new WP_User_Query(['role' => $slug, 'fields' => 'ID', 'number' => -1]))->get_total();
        $roles[] = ['slug' => $slug, 'name' => translate_user_role($role['name']), 'user_count' => $count];
    }
    return new WP_REST_Response($roles, 200);
}

function painel_rest_create_role(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $name = sanitize_text_field($r['name'] ?? '');
    $slug = sanitize_key($r['slug'] ?? sanitize_title($name));
    if (!$name || !$slug) {
        return new WP_Error('missing_fields', 'Nome e slug são obrigatórios.', ['status' => 422]);
    }
    if (get_role($slug)) {
        return new WP_Error('role_exists', 'Já existe uma role com esse slug.', ['status' => 409]);
    }
    $role = add_role($slug, $name, []);
    if (!$role) {
        return new WP_Error('create_failed', 'Falha ao criar a role.', ['status' => 500]);
    }
    return new WP_REST_Response(['slug' => $slug, 'name' => $name, 'user_count' => 0], 201);
}

function painel_rest_update_role(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $slug = sanitize_key($r['slug']);
    $name = sanitize_text_field($r['name'] ?? '');
    if (!get_role($slug)) {
        return new WP_Error('not_found', 'Role não encontrada.', ['status' => 404]);
    }
    if (!$name) {
        return new WP_Error('missing_name', 'Nome é obrigatório.', ['status' => 422]);
    }
    global $wp_roles;
    $wp_roles->roles[$slug]['name']   = $name;
    $wp_roles->role_names[$slug]      = $name;
    update_option($wp_roles->role_key, $wp_roles->roles);
    $count = (int) (new WP_User_Query(['role' => $slug, 'fields' => 'ID', 'number' => -1]))->get_total();
    return new WP_REST_Response(['slug' => $slug, 'name' => $name, 'user_count' => $count], 200);
}

function painel_rest_delete_role(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $slug      = sanitize_key($r['slug']);
    $protected = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
    if (in_array($slug, $protected, true)) {
        return new WP_Error('protected_role', 'Roles padrão do WordPress não podem ser excluídas.', ['status' => 403]);
    }
    if (!get_role($slug)) {
        return new WP_Error('not_found', 'Role não encontrada.', ['status' => 404]);
    }
    remove_role($slug);
    return new WP_REST_Response(['ok' => true], 200);
}

// ── User Groups ───────────────────────────────────────────────────────────────

function painel_rest_list_user_groups(): WP_REST_Response
{
    global $wpdb;
    $g = $wpdb->prefix . 'rhub_groups';
    $i = $wpdb->prefix . 'rhub_group_items';
    $rows = $wpdb->get_results(
        "SELECT g.id, g.title, COUNT(i.id) AS member_count
         FROM $g g LEFT JOIN $i i ON i.group_id = g.id AND i.ref_type = 'user'
         WHERE g.post_type = 'user_group'
         GROUP BY g.id ORDER BY g.title ASC"
    );
    return new WP_REST_Response(array_map(fn($r) => [
        'id' => (int)$r->id, 'title' => $r->title, 'member_count' => (int)$r->member_count,
    ], $rows ?? []), 200);
}

function painel_rest_create_user_group(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $title = sanitize_text_field($r['title'] ?? '');
    if (!$title) return new WP_Error('missing_title', 'Título é obrigatório.', ['status' => 422]);
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'rhub_groups', ['post_id' => 0, 'post_type' => 'user_group', 'title' => $title, 'sort_order' => 0]);
    return new WP_REST_Response(['id' => $wpdb->insert_id, 'title' => $title, 'member_count' => 0], 201);
}

function painel_rest_update_user_group(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $id    = (int)$r['id'];
    $title = sanitize_text_field($r['title'] ?? '');
    if (!$title) return new WP_Error('missing_title', 'Título é obrigatório.', ['status' => 422]);
    global $wpdb;
    $t = $wpdb->prefix . 'rhub_groups';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id=%d AND post_type='user_group'", $id)))
        return new WP_Error('not_found', 'Grupo não encontrado.', ['status' => 404]);
    $wpdb->update($t, ['title' => $title], ['id' => $id]);
    return new WP_REST_Response(['id' => $id, 'title' => $title], 200);
}

function painel_rest_delete_user_group(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $id = (int)$r['id'];
    global $wpdb;
    $gt = $wpdb->prefix . 'rhub_groups';
    $it = $wpdb->prefix . 'rhub_group_items';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $gt WHERE id=%d AND post_type='user_group'", $id)))
        return new WP_Error('not_found', 'Grupo não encontrado.', ['status' => 404]);
    $wpdb->delete($it, ['group_id' => $id]);
    $wpdb->delete($gt, ['id' => $id]);
    return new WP_REST_Response(null, 204);
}

function painel_rest_list_group_members(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $id = (int)$r['id'];
    global $wpdb;
    $it = $wpdb->prefix . 'rhub_group_items';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, ref_id FROM $it WHERE group_id=%d AND ref_type='user' ORDER BY id ASC", $id
    ));

    // Prime user cache em uma query só antes do loop
    $user_ids = array_map(fn($row) => (int)$row->ref_id, $rows ?? []);
    if ($user_ids) {
        get_users(['include' => $user_ids, 'fields' => 'all_with_meta']);
    }

    $members = array_map(function($row) {
        $user = get_userdata((int)$row->ref_id);
        return [
            'item_id'    => (int)$row->id,
            'user_id'    => (int)$row->ref_id,
            'name'       => $user ? $user->display_name : '(removido)',
            'email'      => $user ? $user->user_email   : '',
            'avatar_url' => get_avatar_url((int)$row->ref_id, ['size' => 48]),
        ];
    }, $rows ?? []);
    return new WP_REST_Response($members, 200);
}

function painel_rest_add_group_member(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $group_id = (int)$r['id'];
    $user_id  = (int)($r['user_id'] ?? 0);
    if (!$user_id || !get_userdata($user_id))
        return new WP_Error('invalid_user', 'Usuário inválido.', ['status' => 422]);
    global $wpdb;
    $gt = $wpdb->prefix . 'rhub_groups';
    $it = $wpdb->prefix . 'rhub_group_items';
    if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $gt WHERE id=%d AND post_type='user_group'", $group_id)))
        return new WP_Error('not_found', 'Grupo não encontrado.', ['status' => 404]);
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $it WHERE group_id=%d AND ref_id=%d AND ref_type='user'", $group_id, $user_id)))
        return new WP_Error('already_member', 'Usuário já pertence a este grupo.', ['status' => 409]);
    $wpdb->insert($it, ['group_id' => $group_id, 'ref_id' => $user_id, 'ref_type' => 'user', 'quantity' => 1, 'unit_price' => 0, 'sort_order' => 0]);
    $user = get_userdata($user_id);
    return new WP_REST_Response([
        'item_id' => $wpdb->insert_id, 'user_id' => $user_id,
        'name'    => $user->display_name, 'email' => $user->user_email,
        'avatar_url' => get_avatar_url($user_id, ['size' => 48]),
    ], 201);
}

function painel_rest_remove_group_member(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $group_id = (int)$r['id'];
    $user_id  = (int)$r['user_id'];
    global $wpdb;
    $it = $wpdb->prefix . 'rhub_group_items';
    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $it WHERE group_id=%d AND ref_id=%d AND ref_type='user'", $group_id, $user_id
    ));
    if (!$item) return new WP_Error('not_found', 'Membro não encontrado.', ['status' => 404]);
    $wpdb->delete($it, ['id' => $item->id]);
    return new WP_REST_Response(null, 204);
}

function painel_rest_get_role_caps(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $slug = sanitize_key($r['slug']);
    $role = get_role($slug);
    if (!$role) {
        return new WP_Error('not_found', 'Role não encontrada.', ['status' => 404]);
    }
    global $wp_roles;
    $all = [];
    foreach ($wp_roles->roles as $rd) {
        foreach (array_keys($rd['capabilities']) as $cap) {
            $all[$cap] = true;
        }
    }
    $available = array_keys($all);
    sort($available);
    return new WP_REST_Response([
        'slug'         => $slug,
        'capabilities' => $role->capabilities,
        'available'    => $available,
    ], 200);
}

function painel_rest_update_role_caps(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $slug  = sanitize_key($r['slug']);
    $role  = get_role($slug);
    $caps  = $r['capabilities'] ?? [];
    if (!$role) {
        return new WP_Error('not_found', 'Role não encontrada.', ['status' => 404]);
    }
    if (!is_array($caps)) {
        return new WP_Error('invalid_data', 'capabilities deve ser um objeto.', ['status' => 422]);
    }
    foreach (array_keys($role->capabilities) as $cap) {
        $role->remove_cap($cap);
    }
    foreach ($caps as $cap => $granted) {
        if ($granted) $role->add_cap(sanitize_key($cap), true);
    }
    return new WP_REST_Response(['ok' => true, 'capabilities' => $role->capabilities], 200);
}

// ── Avatar ────────────────────────────────────────────────────────────────────

add_filter('get_avatar_url', 'painel_custom_avatar_url', 10, 3);
function painel_custom_avatar_url(string $url, $id_or_email, array $args): string
{
    $user_id = 0;
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Comment) {
        $user_id = (int) $id_or_email->user_id;
    } elseif (is_string($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        if ($user) $user_id = $user->ID;
    }
    if (!$user_id) return $url;
    $attachment_id = (int) get_user_meta($user_id, '_avatar_attachment_id', true);
    if (!$attachment_id) return $url;
    $size = (int) ($args['size'] ?? 96);
    $src  = wp_get_attachment_image_src($attachment_id, [$size, $size]);
    return $src ? $src[0] : $url;
}

// ── Relations ────────────────────────────────────────────────────────────────

function painel_rest_create_relation(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $required = ['from_id', 'from_type', 'to_id', 'to_type', 'relation_type'];
    foreach ($required as $field) {
        if (empty($r[$field])) {
            return new WP_Error('missing_field', "Campo obrigatório: {$field}", ['status' => 422]);
        }
    }

    $id = painel_relate(
        (int) $r['from_id'], sanitize_text_field($r['from_type']),
        (int) $r['to_id'],   sanitize_text_field($r['to_type']),
        sanitize_text_field($r['relation_type']),
        is_array($r['meta']) ? $r['meta'] : []
    );

    return new WP_REST_Response(['id' => $id], 201);
}

function painel_rest_list_relations(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    if (empty($r['id']) || empty($r['type']) || empty($r['relation_type'])) {
        return new WP_Error('missing_params', 'Parâmetros obrigatórios: id, type, relation_type', ['status' => 422]);
    }

    $results = painel_get_related(
        (int) $r->get_param('id'),
        sanitize_text_field($r->get_param('type')),
        sanitize_text_field($r->get_param('relation_type')),
        in_array($r->get_param('direction'), ['from', 'to']) ? $r->get_param('direction') : 'from'
    );

    return new WP_REST_Response($results, 200);
}

function painel_rest_delete_relation(WP_REST_Request $r): WP_REST_Response
{
    painel_unrelate((int) $r['id']);
    return new WP_REST_Response(null, 204);
}

// ── Tipo (supports + taxonomies) ────────────────────────────────────────────

function painel_rest_list_types(): WP_REST_Response
{
    $excluded = apply_filters('painel_excluded_types', [
        'attachment', 'wp_block', 'wp_navigation', 'wp_template',
        'wp_template_part', 'wp_global_styles', 'wp_font_family',
        'wp_font_face', 'nav_menu_item', 'post', 'page',
    ]);

    $result = [];
    foreach (get_post_types(['show_in_rest' => true], 'objects') as $pt) {
        if (in_array($pt->name, $excluded, true)) continue;

        $rest_base = $pt->rest_base ?? $pt->name;
        $supports  = array_fill_keys(array_keys(get_all_post_type_supports($pt->name)), true);

        $tax_slugs  = array_values(get_object_taxonomies($pt->name));
        $tax_labels = [];
        foreach ($tax_slugs as $tax_slug) {
            $tax_obj = get_taxonomy($tax_slug);
            if ($tax_obj) $tax_labels[$tax_slug] = $tax_obj->labels->name ?? $tax_slug;
        }

        $result[] = [
            'slug'            => $pt->name,
            'name'            => $pt->labels->name ?? $pt->name,
            'rest_base'       => $rest_base,
            'labels'          => [
                'name'          => $pt->labels->name          ?? $pt->name,
                'singular_name' => $pt->labels->singular_name ?? $pt->name,
                'menu_name'     => $pt->labels->menu_name     ?? $pt->name,
            ],
            'supports'        => (object) $supports,
            'hierarchical'    => !empty($pt->hierarchical),
            'taxonomies'      => $tax_slugs,
            'taxonomy_labels' => $tax_labels,
            'icon'            => rh_get_cpt_icon($pt->name),
            'menu_type'       => $pt->menu_type ?? null,
        ];
    }

    return new WP_REST_Response($result, 200);
}

function painel_rest_get_type(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $param  = sanitize_key($r['post_type']);
    $slug   = $param;
    $pt_obj = get_post_type_object($param);
    $raw    = get_all_post_type_supports($param);

    // param may be the rest_base (e.g. "products") instead of the CPT slug ("product")
    if (empty($raw)) {
        foreach (get_post_types([], 'objects') as $obj) {
            $base = $obj->rest_base ?? $obj->name;
            if ($base === $param) {
                $slug   = $obj->name;
                $pt_obj = $obj;
                $raw    = get_all_post_type_supports($slug);
                break;
            }
        }
    }

    if (empty($raw)) {
        return new WP_Error('not_found', 'Post type não encontrado.', ['status' => 404]);
    }

    $supports = [];
    foreach ($raw as $feature => $value) {
        $supports[$feature] = true;
    }

    $list_type    = $pt_obj->list_type    ?? 'default';
    $switchable   = !empty($pt_obj->list_switchable);
    $kanban_field = $pt_obj->kanban_field ?? null;

    // Deriva colunas do kanban a partir dos termos da taxonomia status_{slug}
    $kanban_columns = [];
    if ($list_type === 'kanban') {
        $terms = get_terms(['taxonomy' => 'status_' . $slug, 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $kanban_columns[] = ['id' => $term->slug, 'name' => $term->name];
            }
        }
    }

    return new WP_REST_Response([
        'slug'            => $slug,
        'singular_name'   => $pt_obj->labels->singular_name ?? $slug,
        'supports'        => (object) $supports,
        'taxonomies'      => array_values(get_object_taxonomies($slug)),
        'list_type'       => $list_type,
        'list_switchable' => $switchable,
        'kanban_field'    => $kanban_field,
        'kanban_columns'  => $kanban_columns,
        'hierarchical'     => !empty($pt_obj->hierarchical),
        'soft_delete_only' => !empty($pt_obj->soft_delete_only),
        'view_route'       => $pt_obj->rh_view_route ?? null,
    ], 200);
}

// ── Fields (definição CMB2) ──────────────────────────────────────────────────

function painel_get_field_groups(string $slug): array
{
    $groups = [];
    if (!class_exists('CMB2_Boxes')) return $groups;

    foreach (CMB2_Boxes::get_all() as $box) {
        $types = (array) $box->prop('object_types');
        if (!in_array($slug, $types, true)) continue;
        if (in_array('options-page', $types, true)) continue;

        $fields = [];
        foreach ($box->prop('fields') as $field) {
            if (isset($field['angular']) && $field['angular'] === false) continue;
            $fields[] = painel_serialize_cmb2_field($field);
        }
        if (empty($fields)) continue;

        $groups[] = ['title' => $box->prop('title') ?? '', 'fields' => $fields];
    }
    return $groups;
}

function painel_rest_get_fields(WP_REST_Request $r): WP_REST_Response
{
    $param = sanitize_key($r['post_type']);
    $slug  = $param;

    if (empty(get_all_post_type_supports($param))) {
        foreach (get_post_types([], 'objects') as $obj) {
            if (($obj->rest_base ?? $obj->name) === $param) { $slug = $obj->name; break; }
        }
    }

    return new WP_REST_Response(painel_get_field_groups($slug), 200);
}

function painel_get_term_field_groups(string $taxonomy): array
{
    $groups = [];
    if (!class_exists('CMB2_Boxes')) return $groups;

    foreach (CMB2_Boxes::get_all() as $box) {
        $types = (array) $box->prop('object_types');
        if (!in_array('term', $types, true)) continue;

        $taxonomies = (array) $box->prop('taxonomies');
        if (!empty($taxonomies) && !in_array($taxonomy, $taxonomies, true)) continue;

        $fields = [];
        foreach ($box->prop('fields') as $field) {
            $fields[] = painel_serialize_cmb2_field($field);
        }
        if (empty($fields)) continue;

        $groups[] = ['title' => $box->prop('title') ?? '', 'fields' => $fields];
    }
    return $groups;
}

function painel_rest_get_term_fields(WP_REST_Request $r): WP_REST_Response
{
    return new WP_REST_Response(painel_get_term_field_groups(sanitize_key($r['taxonomy'])), 200);
}

function painel_rest_bootstrap(): WP_REST_Response
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Plugins
    $extra_headers = ['painel' => 'Painel', 'cpts' => 'CPTs'];
    $plugins = [];
    foreach (get_plugins() as $file => $data) {
        $extra = get_file_data(WP_PLUGIN_DIR . '/' . $file, $extra_headers);
        if ($extra['painel'] !== 'true') continue;
        $plugins[] = [
            'file'   => $file,
            'active' => is_plugin_active($file),
            'cpts'   => array_values(array_filter(array_map('trim', explode(',', $extra['cpts'] ?? '')))),
        ];
    }

    // Post types com field groups
    $excluded = apply_filters('painel_excluded_types', [
        'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_font_family', 'wp_font_face', 'nav_menu_item', 'post', 'page',
    ]);

    $post_types = [];
    foreach (get_post_types(['show_in_rest' => true], 'objects') as $pt) {
        if (in_array($pt->name, $excluded, true)) continue;

        $slug     = $pt->name;
        $raw      = get_all_post_type_supports($slug);
        $supports = [];
        foreach ($raw as $feature => $_) { $supports[$feature] = true; }

        $list_type      = $pt->list_type ?? 'default';
        $kanban_columns = [];
        if ($list_type === 'kanban') {
            $terms = get_terms(['taxonomy' => 'status_' . $slug, 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $kanban_columns[] = ['id' => $term->slug, 'name' => $term->name];
                }
            }
        }

        $post_types[] = [
            'slug'             => $slug,
            'rest_base'        => $pt->rest_base ?? $slug,
            'singular_name'    => $pt->labels->singular_name ?? $slug,
            'supports'         => (object) $supports,
            'taxonomies'       => array_values(get_object_taxonomies($slug)),
            'list_type'        => $list_type,
            'list_switchable'  => !empty($pt->list_switchable),
            'kanban_field'     => $pt->kanban_field ?? null,
            'kanban_columns'   => $kanban_columns,
            'hierarchical'     => !empty($pt->hierarchical),
            'soft_delete_only' => !empty($pt->soft_delete_only),
            'view_route'       => $pt->rh_view_route ?? null,
            'field_groups'     => painel_get_field_groups($slug),
        ];
    }

    return new WP_REST_Response([
        'plugins'    => $plugins,
        'post_types' => $post_types,
        'auth_mode'  => painel_auth_mode(),
    ], 200);
}

function painel_serialize_cmb2_field(array $field): array
{
    // Normaliza tipos CMB2 para tipos HTML usados pelo Angular
    $type_map = [
        'text_money'  => 'money',
        'text_url'    => 'url',
        'text_email'  => 'email',
        'text_date'   => 'date',
        'text_time'   => 'time',
        'text_small'  => 'text',
        'textarea_small' => 'textarea',
        'textarea_code'  => 'textarea',
        'file'             => 'file',
        'file_list'        => 'file',
        'image'            => 'image',
        'oembed'           => 'url',
        'post_search_text' => 'post_select',
    ];

    $raw_type = $field['type'];
    $type     = $type_map[$raw_type] ?? $raw_type;

    // attributes['type'] tem prioridade (ex: type=text com attributes[type=number])
    if (!empty($field['attributes']['type'])) {
        $type = $field['attributes']['type'];
    }

    // rh_type permite sobrescrever o tipo sem afetar o CMB2 no WP Admin
    if (!empty($field['rh_type'])) {
        $type = $field['rh_type'];
    }

    $options = !empty($field['options']) ? $field['options'] : null;
    if (!$options && !empty($field['options_cb']) && is_callable($field['options_cb'])) {
        $options = call_user_func($field['options_cb']);
    }

    $out = [
        'key'              => $field['id'],
        'label'            => $field['name'] ?? '',
        'type'             => $type,
        'options'          => $options ?: null,
        'show_option_none' => ($field['show_option_none'] ?? null) !== false,
        'required'         => !empty($field['attributes']['required']),
        'description'      => $field['desc'] ?? null,
        'default'          => $field['default'] ?? null,
        'conditional_id'   => $field['attributes']['data-conditional-id'] ?? null,
        'conditional_val'  => $field['attributes']['data-conditional-value'] ?? null,
        'rows'             => isset($field['attributes']['rows']) ? (int) $field['attributes']['rows'] : null,
    ];

    if ($raw_type === 'group') {
        $out['repeatable'] = !empty($field['repeatable']);
        $out['fields']     = array_map('painel_serialize_cmb2_field', $field['fields'] ?? []);
    }

    if ($raw_type === 'post_select' || $raw_type === 'post_search_text') {
        $out['post_type'] = $field['post_type'] ?? '';
    }

    if ($raw_type === 'source_select') {
        $out['endpoint'] = $field['endpoint'] ?? '';
        $out['multiple'] = !empty($field['multiple']);
    }

    if ($raw_type === 'oauth_button' || $raw_type === 'oauth_button_multi') {
        $out['endpoint'] = $field['endpoint'] ?? '';
    }

    return $out;
}

// ── Groups ───────────────────────────────────────────────────────────────────

function painel_rest_list_groups(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $post_id = (int) $r->get_param('post_id');
    if (!$post_id) return new WP_Error('missing_post_id', 'post_id obrigatório.', ['status' => 422]);
    return new WP_REST_Response(painel_get_groups($post_id), 200);
}

function painel_rest_create_group(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $post_id   = (int) $r['post_id'];
    $post_type = sanitize_key($r['post_type'] ?? 'post');
    $title     = sanitize_text_field($r['title'] ?? 'Grupo');

    if (!$post_id) return new WP_Error('missing_post_id', 'post_id obrigatório.', ['status' => 422]);

    global $wpdb;
    $p         = RHUB_DB_PREFIX;
    $sort      = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}groups WHERE post_id = %d", $post_id
    ));

    $wpdb->insert("{$p}groups", [
        'post_id'    => $post_id,
        'post_type'  => $post_type,
        'title'      => $title,
        'sort_order' => $sort,
    ]);

    $group         = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}groups WHERE id = %d", $wpdb->insert_id));
    $group->items  = [];
    return new WP_REST_Response($group, 201);
}

function painel_rest_update_group(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    global $wpdb;
    $p  = RHUB_DB_PREFIX;
    $id = (int) $r['id'];

    $data = array_filter([
        'title'      => $r['title'] ? sanitize_text_field($r['title']) : null,
        'sort_order' => isset($r['sort_order']) ? (int) $r['sort_order'] : null,
    ], fn($v) => $v !== null);

    if (empty($data)) return new WP_Error('no_data', 'Nenhum campo para atualizar.', ['status' => 422]);

    $wpdb->update("{$p}groups", $data, ['id' => $id]);
    return new WP_REST_Response($wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}groups WHERE id = %d", $id)), 200);
}

function painel_rest_delete_group(WP_REST_Request $r): WP_REST_Response
{
    global $wpdb;
    $p  = RHUB_DB_PREFIX;
    $id = (int) $r['id'];
    $wpdb->delete("{$p}group_items", ['group_id' => $id]);
    $wpdb->delete("{$p}groups", ['id' => $id]);
    return new WP_REST_Response(null, 204);
}

// ── Group Items ───────────────────────────────────────────────────────────────

function painel_rest_create_group_item(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $group_id  = (int) $r['group_id'];
    $ref_id    = (int) $r['ref_id'];
    $ref_type  = sanitize_key($r['ref_type'] ?? 'catalog');
    $qty       = (float) ($r['quantity'] ?? 1);
    $price     = (float) ($r['unit_price'] ?? 0);
    $desc      = $r['description'] ? sanitize_textarea_field($r['description']) : null;

    if (!$group_id || !$ref_id) {
        return new WP_Error('missing_fields', 'group_id e ref_id obrigatórios.', ['status' => 422]);
    }

    global $wpdb;
    $p    = RHUB_DB_PREFIX;
    $sort = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}group_items WHERE group_id = %d", $group_id
    ));

    $wpdb->insert("{$p}group_items", [
        'group_id'   => $group_id,
        'ref_id'     => $ref_id,
        'ref_type'   => $ref_type,
        'description'=> $desc,
        'quantity'   => $qty,
        'unit_price' => $price,
        'sort_order' => $sort,
    ]);

    $item             = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}group_items WHERE id = %d", $wpdb->insert_id));
    $item->quantity   = (float) $item->quantity;
    $item->unit_price = (float) $item->unit_price;
    $item->meta       = null;
    $item->ref        = painel_embed_ref($ref_id, $ref_type);

    return new WP_REST_Response($item, 201);
}

function painel_rest_update_group_item(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    global $wpdb;
    $p  = RHUB_DB_PREFIX;
    $id = (int) $r['id'];

    $data = [];
    if (isset($r['quantity']))    $data['quantity']    = (float) $r['quantity'];
    if (isset($r['unit_price']))  $data['unit_price']  = (float) $r['unit_price'];
    if (isset($r['description'])) $data['description'] = sanitize_textarea_field($r['description']);
    if (isset($r['sort_order']))  $data['sort_order']  = (int) $r['sort_order'];

    if (empty($data)) return new WP_Error('no_data', 'Nenhum campo para atualizar.', ['status' => 422]);

    $wpdb->update("{$p}group_items", $data, ['id' => $id]);

    $item             = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}group_items WHERE id = %d", $id));
    $item->quantity   = (float) $item->quantity;
    $item->unit_price = (float) $item->unit_price;
    $item->meta       = $item->meta ? json_decode($item->meta, true) : null;
    $item->ref        = painel_embed_ref((int) $item->ref_id, $item->ref_type);

    return new WP_REST_Response($item, 200);
}

function painel_rest_delete_group_item(WP_REST_Request $r): WP_REST_Response
{
    global $wpdb;
    $wpdb->delete(RHUB_DB_PREFIX . 'group_items', ['id' => (int) $r['id']]);
    return new WP_REST_Response(null, 204);
}

// ── Settings ─────────────────────────────────────────────────────────────────

function painel_rest_list_settings(WP_REST_Request $r): WP_REST_Response
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . RHUB_DB_PREFIX . 'settings ORDER BY setting_key');
    return new WP_REST_Response($rows, 200);
}

function painel_rest_upsert_setting(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    if (empty($r['key'])) {
        return new WP_Error('missing_key', 'O campo key é obrigatório.', ['status' => 422]);
    }
    global $wpdb;
    $t   = RHUB_DB_PREFIX . 'settings';
    $key = sanitize_key($r['key']);
    $val = is_array($r['value']) ? wp_json_encode($r['value']) : sanitize_textarea_field((string) $r['value']);

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE setting_key = %s", $key));
    if ($existing) {
        $wpdb->update($t, ['setting_value' => $val, 'updated_at' => current_time('mysql')], ['setting_key' => $key]);
    } else {
        $wpdb->insert($t, ['setting_key' => $key, 'setting_value' => $val, 'updated_at' => current_time('mysql')]);
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE setting_key = %s", $key));
    return new WP_REST_Response($row, $existing ? 200 : 201);
}

// ── Event log ────────────────────────────────────────────────────────────────

function painel_rest_list_events(WP_REST_Request $r): WP_REST_Response
{
    global $wpdb;
    $t   = RHUB_DB_PREFIX . 'event_log';
    $per = min((int) ($r->get_param('per_page') ?: 20), 100);
    $pg  = max((int) ($r->get_param('page') ?: 1), 1);
    $off = ($pg - 1) * $per;

    $where = ['1=1'];
    $params = [];

    $subject_id = $r->get_param('subject_id');
    if ($subject_id !== null) {
        $where[] = 'subject_id = %d';
        $params[] = (int) $subject_id;
    }

    $subject_type = $r->get_param('subject_type');
    if ($subject_type !== null) {
        $where[] = 'subject_type = %s';
        $params[] = sanitize_key($subject_type);
    }

    $event_type = $r->get_param('event_type');
    if ($event_type !== null) {
        $where[] = 'event_type = %s';
        $params[] = sanitize_key($event_type);
    }

    $where_sql = implode(' AND ', $where);

    $query_params = $params;
    $query_params[] = $per;
    $query_params[] = $off;

    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY occurred_at DESC LIMIT %d OFFSET %d",
        ...$query_params
    ));

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}",
        ...$params
    ));

    foreach ($rows as $row) {
        if ($row->meta) {
            $row->meta = json_decode($row->meta, true);
        }
    }

    return new WP_REST_Response(['data' => $rows, 'total' => $total, 'page' => $pg, 'per_page' => $per], 200);
}

// ── Chat ─────────────────────────────────────────────────────────────────────

function painel_rest_chat(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    set_time_limit(300);

    $message       = trim($r->get_param('message') ?? '');
    $history       = (array) ($r->get_param('history') ?? []);
    $context       = trim($r->get_param('context') ?? '');
    $system_prompt = trim($r->get_param('system_prompt') ?? '');
    $dashboard_id  = (int) ($r->get_param('dashboard_id') ?? 0);

    if (empty($message)) {
        return new WP_Error('missing_message', 'O campo message é obrigatório.', ['status' => 422]);
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
        return new WP_Error('no_key', "Chave {$provider} não configurada.", ['status' => 503]);
    }

    $today = gmdate('Y-m-d');
    $year  = gmdate('Y');

    $base = apply_filters('painel_chat_base_prompt', implode("\n", [
        'Você é um assistente de análise de dados.',
        '',
        "DATA ATUAL: $today. Ano corrente: $year.",
        '',
        'FERRAMENTAS:',
        'Use as ferramentas disponíveis para buscar dados. Nunca invente nem estime dados.',
        'ESCOPO DA BUSCA: perguntas gerais (canal, CPL, leads totais, investimento) → busque dados agregados, sem breakdown por empreendimento. Só use empreendimento ou breakdown_por_empreendimento quando o usuário mencionar explicitamente um empreendimento ou pedir comparação entre eles.',
        'FILTRO POR CIDADE: quando o usuário perguntar sobre empreendimentos de uma cidade, use breakdown_por_empreendimento para buscar todos e depois apresente APENAS os empreendimentos que pertencem àquela cidade conforme o mapeamento no contexto. Nunca liste empreendimentos de outras cidades na resposta.',
        '',
        'PERÍODO DE ANÁLISE — executa antes de qualquer ferramenta:',
        'Passo 1: a mensagem contém referência de tempo? (mês, semana, datas, "últimos X meses", "esse ano", etc.)',
        'Passo 2a — SIM: resolva as datas usando as regras abaixo e busque imediatamente, sem pedir confirmação.',
        'Passo 2b — NÃO: use o mês atual como padrão (date_from=primeiro dia do mês, date_to=DATA ATUAL) e chame a ferramenta diretamente. Nunca pergunte o período ao usuário.',
        '',
        'RESOLUÇÃO DE DATAS (use DATA ATUAL para calcular):',
        '- Nome de mês sem ano (ex: "novembro", "outubro") → assume ano corrente. Se for o mês atual: date_from=primeiro dia do mês, date_to=DATA ATUAL. Se for mês passado: date_from=primeiro dia, date_to=último dia daquele mês.',
        '- "esse mês" / "mês atual" → date_from=primeiro dia do mês atual, date_to=DATA ATUAL.',
        '- "mês passado" → date_from=primeiro dia do mês anterior, date_to=último dia do mês anterior.',
        '- "esse ano" / "ano atual" → date_from=primeiro dia do ano corrente, date_to=DATA ATUAL.',
        '- "últimos N meses" → date_from=DATA ATUAL menos N meses (primeiro dia), date_to=DATA ATUAL.',
        '- "últimos N dias" → date_from=DATA ATUAL menos N-1 dias, date_to=DATA ATUAL.',
        '- Nunca peça confirmação de período quando um mês ou intervalo foi claramente mencionado.',
        '- COMPARAÇÃO MÊS ATUAL vs MÊS ANTERIOR: quando o mês atual ainda não terminou, NÃO compare parcial vs total. Se a ferramenta tiver o parâmetro comparacao_mensal=true, use-o — retorna os 3 períodos + projeção prontos. Caso contrário: calcule ritmo diário (total ÷ dias decorridos) e projete para o fim do mês antes de comparar com o mês anterior completo. NUNCA apresente uma queda percentual de parcial vs total como se fosse real — é enganoso.',
        '',
        'FORMATO:',
        'Seja direto: sem "Claro!", "Ótima pergunta!", "Com prazer!", sem frases de encerramento.',
        'PROIBIDO terminar a resposta com parágrafo genérico de análise, recomendação ou conclusão ("É recomendável...", "A diferença sugere...", "É importante investigar...", "Ajustes estratégicos..."). A resposta termina nos dados — ponto final. Se houver recomendação real baseada em número concreto, ela vai dentro da análise, não num parágrafo separado no fim.',
        'Direto ao ponto não significa raso: use quantas frases a análise pede. Nunca corte análise para ser breve.',
        'NUNCA anuncie intenções: "Vou buscar...", "Vou analisar...", "Para responder isso, preciso..." — proibido.',
        'NUNCA descreva ferramentas chamadas: "1. Chamei render_chart..." — proibido.',
        'Use **negrito** livremente onde ajudar o leitor: números-chave, nomes de canais, conclusões, alertas, qualquer coisa que mereça destaque. Não há restrição — grife o que for relevante para a análise.',
        'Markdown proibido: ### cabeçalhos, tabelas com | (pipe), listas com *, itálico. Para dados tabulares use render_chart(table), não texto com |.',
        'Não use LaTeX nem notação matemática especial — escreva cálculos em texto simples.',
    ]), $today, $year, $dashboard_id);

    $system = !empty($system_prompt) ? $base . "\n\n" . $system_prompt : $base;

    if (!empty($context)) {
        $system .= "\n\n" . $context;
    }

    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $h) {
        $role    = in_array($h['role'] ?? '', ['user', 'assistant'], true) ? $h['role'] : 'user';
        $content = trim($h['content'] ?? '');
        if ($content) $messages[] = ['role' => $role, 'content' => $content];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // Plugins can register tools via this filter (e.g. bw8-dashboard registra get_dashboard_metrics)
    $tool_ctx = ['dashboard_id' => $dashboard_id, 'user_id' => get_current_user_id()];
    $tools    = apply_filters('painel_chat_get_tools', [], $tool_ctx);
    if (empty($tools)) {
        $messages[0]['content'] .= "\n\nESTA CHAMADA NÃO TEM FERRAMENTAS. Os dados já foram consultados e estão no contexto desta pergunta. Responda usando esse contexto, sem tentar chamar ferramentas. Em perguntas de acompanhamento, use o histórico para entender a referência e os fatos recém-consultados como fonte numérica.";
    }

    $body = [
        'model'                => $cfg['model'],
        'messages'             => $messages,
        'max_tokens'           => 1500,
        'temperature'          => 0.3,
    ];
    if (!empty($tools)) {
        $body['tools'] = $tools;
        $body['parallel_tool_calls'] = true;
    }

    $charts          = [];
    $suggestions     = [];
    $recommendations = [];
    $auto_recs       = [];
    add_action('painel_chat_add_recommendations', function ($recs) use (&$auto_recs) {
        foreach ((array) $recs as $r) {
            $key = ($r['ad_name'] ?? $r['campaign_name'] ?? '') . '|' . ($r['action_type'] ?? '');
            if (!isset($auto_recs[$key])) {
                $auto_recs[$key] = $r;
            }
        }
    });
    for ($round = 0; $round < 12; $round++) {
        $resp = wp_remote_post($cfg['url'], [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 45,
        ]);

        if (is_wp_error($resp)) {
            return new WP_Error('ai_error', 'Erro ao comunicar com a IA.', ['status' => 502]);
        }

        $code    = (int) wp_remote_retrieve_response_code($resp);
        $decoded = json_decode(wp_remote_retrieve_body($resp), true);

        if ($code !== 200) {
            return new WP_Error('ai_error', $decoded['error']['message'] ?? 'Erro na IA.', ['status' => $code]);
        }

        $choice     = $decoded['choices'][0] ?? [];
        $msg        = $choice['message'] ?? [];
        $tool_calls = $msg['tool_calls'] ?? [];

        if (empty($tool_calls)) {
            $reply = trim($msg['content'] ?? '');
            $reply = preg_replace('/\s*render_chart\s*\([^)]*\)\s*/i', '', $reply);
            $reply = preg_replace('/\s*(O gráfico (abaixo|acima) ilustra[^.]*\.|Veja o gráfico (abaixo|acima)[^.]*\.)\s*/i', '', $reply);
            return new WP_REST_Response(['reply' => trim($reply), 'created' => [], 'charts' => $charts, 'suggestions' => $suggestions, 'recommendations' => array_merge($recommendations, array_values($auto_recs))], 200);
        }

        // Execute tool calls; render_chart and suggest_options are collected and returned to Angular
        $body['messages'][] = $msg;
        foreach ($tool_calls as $tc) {
            $fn_name = $tc['function']['name'] ?? '';
            $fn_args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            if ($fn_name === 'render_chart') {
                $charts[] = $fn_args;
                $result   = ['rendered' => true];
            } elseif ($fn_name === 'suggest_options') {
                $suggestions = array_values(array_filter((array) ($fn_args['options'] ?? []), 'is_string'));
                $result      = ['rendered' => true];
            } elseif ($fn_name === 'suggest_campaign_actions') {
                $recommendations = array_values((array) ($fn_args['recommendations'] ?? []));
                $result          = ['rendered' => true];
            } else {
                $result = apply_filters('painel_chat_run_tool', ['error' => 'Tool não disponível.'], $fn_name, $fn_args, $tool_ctx);
            }
            $body['messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'content'      => wp_json_encode($result),
            ];
        }
    }

    return new WP_Error('ai_error', 'Máximo de iterações atingido.', ['status' => 500]);
}

function painel_rest_chat_DISABLED(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    // Tool-calling chat (create WP posts, list posts, etc.) — moved to separate plugin.
    if (!function_exists('wp_ai_client_prompt') || !AiClient::defaultRegistry()->hasProvider('groq')) {
        return new WP_Error('ai_not_configured', 'Provedor de IA não disponível. Verifique a chave Groq nas configurações.', ['status' => 503]);
    }

    // Resolve the model explicitly to bypass capability-matching.
    try {
        $groq_model = AiClient::defaultRegistry()->getProviderModel('groq', 'llama-3.3-70b-versatile');
    } catch (\Throwable $e) {
        return new WP_Error('ai_not_configured', 'Modelo Groq não disponível: ' . $e->getMessage(), ['status' => 503]);
    }

    $fn_declarations = [
        new FunctionDeclaration('wp_create_post', 'Cria um novo registro no WordPress. Use para criar propostas, leads, tarefas, produtos, etc.', [
            'type'       => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'description' => 'Tipo: proposal, lead, task, catalog, post, page, etc.'],
                'title'     => ['type' => 'string', 'description' => 'Título do registro.'],
                'content'   => ['type' => 'string', 'description' => 'Descrição/conteúdo. Inclua todos os detalhes relevantes.'],
                'status'    => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'Status do post.'],
                'meta'      => ['type' => 'object', 'description' => 'Campos adicionais. Ex: {"total":"500.00","status":"rascunho"}.', 'additionalProperties' => ['type' => 'string']],
            ],
            'required' => ['post_type', 'title'],
        ]),
        new FunctionDeclaration('wp_list_posts', 'Lista registros existentes por tipo. Use para buscar IDs de produtos, propostas, etc.', [
            'type'       => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'description' => 'Tipo: proposal, lead, task, catalog, etc.'],
                'search'    => ['type' => 'string', 'description' => 'Termo de busca opcional.'],
                'per_page'  => ['type' => 'integer', 'description' => 'Resultados por página (máx 20).'],
            ],
            'required' => ['post_type'],
        ]),
        new FunctionDeclaration('rh_add_item_to_proposal', 'Adiciona um item do catálogo a uma proposta existente.', [
            'type'       => 'object',
            'properties' => [
                'proposal_id' => ['type' => 'integer', 'description' => 'ID da proposta.'],
                'catalog_id'  => ['type' => 'integer', 'description' => 'ID do item do catálogo.'],
                'quantity'    => ['type' => 'number', 'description' => 'Quantidade (padrão 1).'],
                'unit_price'  => ['type' => 'number', 'description' => 'Preço unitário (usa o preço do item se omitido).'],
                'description' => ['type' => 'string', 'description' => 'Descrição adicional do item.'],
                'group_title' => ['type' => 'string', 'description' => 'Nome do grupo de itens (padrão: Itens).'],
            ],
            'required' => ['proposal_id', 'catalog_id'],
        ]),
    ];

    // Build conversation as SDK Message objects.
    $msgs = [];
    foreach ((array) $history as $msg) {
        $role    = ($msg['role'] ?? '') === 'user' ? 'user' : 'assistant';
        $content = sanitize_text_field($msg['content'] ?? '');
        if (!$content) continue;
        $msgs[] = $role === 'user'
            ? new UserMessage([new MessagePart($content)])
            : new ModelMessage([new MessagePart($content)]);
    }
    $msgs[] = new UserMessage([new MessagePart($message)]);

    $system_prompt = <<<PROMPT
Você é um assistente do painel RockHub. Responda sempre em português, de forma objetiva. Quando o usuário pedir para criar, listar ou buscar dados, use as funções disponíveis — nunca apenas descreva o que faria. Ao criar um registro, coloque TODOS os detalhes relevantes (descrição, valor, prazo, cliente, observações) no campo "content" da função.

Campos disponíveis por tipo de registro (use os IDs exatos no campo "meta"):

proposal (proposta/orçamento):
- total: valor total em número (ex: "500.00")
- valid_until: data de validade no formato Y-m-d (ex: "2026-07-05")
- status: "rascunho" | "enviada" | "aceita" | "recusada"

lead:
- email, phone, budget (valor esperado), notes (observações)
- source: "site" | "indicacao" | "anuncio" | "outro"
- status: "novo" | "contato" | "proposta" | "ganho" | "perdido"

task (tarefa):
- due_date: prazo no formato Y-m-d
- priority: "baixa" | "media" | "alta"
- done: "1" (concluída) ou "" (pendente)

catalog (item do catálogo):
- tipo: "produto" | "servico"
- sku, ean, preco_unitario (preço de tabela), preco_venda (promocional — opcional, tem prioridade), unidade: "un" | "hora" | "dia" | "projeto" | "m2" | "m" | "kg" | "lt", prazo_entrega, descricao

Sempre preencha o máximo de campos possível com base no que o usuário informar.

Fluxo para criar proposta com itens do catálogo:
1. Listar itens do catálogo para encontrar o ID pelo nome (post_type=catalog)
2. Criar a proposta (post_type=proposal) — anote o ID retornado
3. Adicionar o item à proposta com proposal_id e catalog_id

Use apenas uma função por vez. Aguarde o resultado antes de chamar outra.

IMPORTANTE: O parâmetro de nível superior "status" da função "wp_create_post" deve ser estritamente "publish" ou "draft". O status de negócio da proposta (rascunho, enviada, aceita, recusada) ou do lead (novo, contato, proposta, ganho, perdido) deve ser colocado obrigatoriamente dentro do objeto "meta". Nunca envie valores como "rascunho" ou "enviada" para o parâmetro principal "status".

IMPORTANTE: Chame apenas uma ferramenta/função por vez. NUNCA tente concatenar JSON ou parâmetros diretamente no nome da função (por exemplo: NUNCA tente gerar chamadas onde o nome seja "wp_list_posts={...}"). O nome da função chamada deve ser estritamente o identificador limpo (ex: "wp_list_posts").

IMPORTANTE: nunca invente valores, datas, quantidades ou qualquer dado que o usuário não tenha informado explicitamente. Se faltarem informações, pergunte antes de criar o registro.
PROMPT;

    if (!empty($context)) {
        $system_prompt .= "\n\n--- DADOS DE CONTEXTO ---\n" . $context . "\n--- FIM DOS DADOS ---\n\nUse os dados acima para responder perguntas analíticas do usuário. Não invente números além do que está nos dados.";
    }

    // Multi-round tool calling loop using direct FunctionDeclarations (bypasses Abilities registry).
    $created = []; // records created/updated during this request

    for ($round = 0; $round < 6; $round++) {
        $builder = wp_ai_client_prompt()
            ->using_model($groq_model)
            ->using_system_instruction($system_prompt)
            ->using_function_declarations(...$fn_declarations);

        if (!empty($msgs)) {
            $builder = $builder->with_history(...$msgs);
        }

        $result = $builder->generate_text_result();

        if (is_wp_error($result)) {
            return $result;
        }

        $candidates = $result->getCandidates();
        if (empty($candidates)) {
            return new WP_Error('ai_error', 'Resposta vazia do modelo.', ['status' => 500]);
        }

        $model_msg    = $candidates[0]->getMessage();
        $fn_call_parts = [];
        $text          = '';

        foreach ($model_msg->getParts() as $part) {
            if ($part->getType()->isFunctionCall()) {
                $fn_call_parts[] = $part;
            } elseif ($part->getType()->isText()) {
                $text .= $part->getText();
            }
        }

        if (empty($fn_call_parts)) {
            if (!$text) {
                return new WP_Error('ai_error', 'Resposta vazia do modelo.', ['status' => 500]);
            }
            return new WP_REST_Response(['reply' => $text, 'created' => $created], 200);
        }

        // Execute each function call and build response parts for next round.
        $response_parts = [];
        foreach ($fn_call_parts as $part) {
            $fn_call   = $part->getFunctionCall();
            $fn_name   = $fn_call->getName() ?? '';
            $fn_args   = is_array($fn_call->getArgs()) ? $fn_call->getArgs() : [];
            $fn_result = painel_chat_execute_tool($fn_name, $fn_args);

            // Track newly created WP posts so the frontend can show a "View" button.
            if ($fn_name === 'wp_create_post' && !empty($fn_result['success']) && !empty($fn_result['id'])) {
                $created[] = [
                    'post_type' => $fn_result['post_type'],
                    'id'        => $fn_result['id'],
                    'title'     => $fn_result['title'],
                ];
            }

            $response_parts[] = new MessagePart(
                new FunctionResponse($fn_call->getId(), $fn_name ?: null, $fn_result)
            );
        }

        $msgs[] = $model_msg;
        $msgs[] = new UserMessage($response_parts);
    }

    return new WP_Error('ai_error', 'Máximo de iterações atingido sem resposta final.', ['status' => 500]);
}

function painel_chat_execute_tool(string $name, array $args): array
{
    switch ($name) {

        case 'wp_create_post':
            $post_type = sanitize_key($args['post_type'] ?? 'post');
            $title     = sanitize_text_field($args['title'] ?? '');
            if (!$title) return ['error' => 'Título obrigatório'];
            $id = wp_insert_post([
                'post_type'    => $post_type,
                'post_title'   => $title,
                'post_content' => sanitize_textarea_field($args['content'] ?? ''),
                'post_status'  => in_array($args['status'] ?? '', ['publish', 'draft']) ? $args['status'] : 'publish',
            ], true);
            if (is_wp_error($id)) return ['error' => $id->get_error_message()];
            if (!empty($args['meta']) && is_array($args['meta'])) {
                foreach ($args['meta'] as $key => $value) {
                    update_post_meta($id, sanitize_key($key), sanitize_text_field((string) $value));
                }
            }
            return ['success' => true, 'id' => $id, 'title' => $title, 'post_type' => $post_type];

        case 'wp_list_posts':
            $post_type = sanitize_key($args['post_type'] ?? 'post');
            $per_page  = min((int) ($args['per_page'] ?? 10), 20);
            $query     = new WP_Query([
                'post_type'      => $post_type,
                'posts_per_page' => $per_page,
                'post_status'    => 'any',
                's'              => sanitize_text_field($args['search'] ?? ''),
            ]);
            $items = [];
            foreach ($query->posts as $p) {
                $items[] = ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'date' => $p->post_date];
            }
            return ['total' => $query->found_posts, 'items' => $items];

        case 'rh_add_item_to_proposal':
            $proposal_id = (int) ($args['proposal_id'] ?? 0);
            $catalog_id  = (int) ($args['catalog_id']  ?? 0);
            if (!$proposal_id || !$catalog_id) return ['error' => 'proposal_id e catalog_id obrigatórios'];

            global $wpdb;
            $p = RHUB_DB_PREFIX;

            // usa grupo existente ou cria um novo
            $group_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}groups WHERE post_id = %d ORDER BY id ASC LIMIT 1", $proposal_id
            ));
            if (!$group_id) {
                $wpdb->insert("{$p}groups", [
                    'post_id'    => $proposal_id,
                    'post_type'  => 'proposal',
                    'title'      => sanitize_text_field($args['group_title'] ?? 'Itens'),
                    'sort_order' => 0,
                ]);
                $group_id = (int) $wpdb->insert_id;
            }

            // preço padrão do item se não informado — preco_venda tem prioridade sobre preco_unitario
            if (isset($args['unit_price'])) {
                $unit_price = (float) $args['unit_price'];
            } else {
                $preco_venda    = painel_parse_money((string) get_post_meta($catalog_id, 'preco_venda', true));
                $preco_unitario = painel_parse_money((string) get_post_meta($catalog_id, 'preco_unitario', true));
                $unit_price     = $preco_venda ?: $preco_unitario;
            }

            $sort = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}group_items WHERE group_id = %d", $group_id
            ));
            $wpdb->insert("{$p}group_items", [
                'group_id'    => $group_id,
                'ref_id'      => $catalog_id,
                'ref_type'    => 'catalog',
                'description' => isset($args['description']) ? sanitize_textarea_field($args['description']) : null,
                'quantity'    => (float) ($args['quantity'] ?? 1),
                'unit_price'  => $unit_price,
                'sort_order'  => $sort,
            ]);

            $catalog_title = get_the_title($catalog_id);
            return ['success' => true, 'group_id' => $group_id, 'item_id' => (int) $wpdb->insert_id, 'catalog' => $catalog_title, 'unit_price' => $unit_price];

        default:
            return ['error' => "Ferramenta desconhecida: {$name}"];
    }
}

// ── Config ───────────────────────────────────────────────────────────────────

function painel_rest_get_config(): WP_REST_Response {
    $kanban_types = [];
    foreach (get_post_types([], 'objects') as $obj) {
        if (($obj->list_type ?? 'default') !== 'kanban') continue;

        $columns = [];
        $terms   = get_terms(['taxonomy' => 'status_' . $obj->name, 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $columns[] = ['id' => $term->slug, 'name' => $term->name];
            }
        }

        $kanban_types[] = [
            'slug'    => $obj->name,
            'label'   => $obj->labels->menu_name ?: $obj->labels->name,
            'columns' => $columns,
        ];
    }

    $defaults = (array) get_option('painel_kanban_defaults', []);

    return new WP_REST_Response([
        'kanban_defaults' => $defaults,
        'kanban_types'    => $kanban_types,
    ]);
}

function painel_rest_save_config(WP_REST_Request $req): WP_REST_Response {
    $body     = $req->get_json_params();
    $defaults = array_map('strval', (array) ($body['kanban_defaults'] ?? []));
    update_option('painel_kanban_defaults', $defaults, false);
    return new WP_REST_Response(['ok' => true]);
}

// ── Helper: gravar evento no log ─────────────────────────────────────────────

function painel_log(string $eventType, ?int $subjectId = null, ?string $subjectType = null, array $meta = []): void
{
    global $wpdb;
    $wpdb->insert(RHUB_DB_PREFIX . 'event_log', [
        'user_id'      => get_current_user_id() ?: null,
        'subject_id'   => $subjectId,
        'subject_type' => $subjectType,
        'event_type'   => $eventType,
        'meta'         => wp_json_encode($meta),
    ]);
}

function painel_get_google_token(string $provider = 'google-drive'): string|WP_Error
{
    global $wpdb;
    $uid = get_current_user_id();
    $t   = RHUB_DB_PREFIX . 'oauth_connections';

    $db_provider = $provider;
    $conn = $wpdb->get_row($wpdb->prepare(
        "SELECT access_token, refresh_token, token_expires_at FROM {$t}
         WHERE user_id = %d AND provider = %s AND disconnected_at IS NULL",
        $uid, $provider
    ));

    // fallback para registros antigos salvos como 'google'
    if (!$conn && $provider === 'google-drive') {
        $conn = $wpdb->get_row($wpdb->prepare(
            "SELECT access_token, refresh_token, token_expires_at FROM {$t}
             WHERE user_id = %d AND provider = 'google' AND disconnected_at IS NULL",
            $uid
        ));
        if ($conn) $db_provider = 'google';
    }

    if (!$conn) {
        $labels = [
            'google-drive'     => 'Google Drive',
            'google-analytics' => 'Google Analytics',
            'google-ads'       => 'Google Ads',
            'youtube'          => 'YouTube',
        ];
        $label = $labels[$provider] ?? ucwords(str_replace('-', ' ', $provider));
        return new WP_Error('not_connected', "{$label} não conectado.", ['status' => 403]);
    }

    $expired = $conn->token_expires_at && strtotime($conn->token_expires_at) < time() + 60;

    if ($expired) {
        if (!$conn->refresh_token) {
            return new WP_Error('token_expired', 'Token expirado. Reconecte o Google Drive.', ['status' => 403]);
        }

        $refresh = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => painel_get_oauth_setting('google_client_id'),
                'client_secret' => painel_get_oauth_setting('google_client_secret'),
                'refresh_token' => $conn->refresh_token,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($refresh)) {
            return new WP_Error('token_refresh_failed', 'Falha ao renovar token.', ['status' => 502]);
        }

        $new = json_decode(wp_remote_retrieve_body($refresh), true);
        if (empty($new['access_token'])) {
            return new WP_Error('token_refresh_failed', 'Falha ao renovar token.', ['status' => 502]);
        }

        $expires_at = gmdate('Y-m-d H:i:s', time() + (int) ($new['expires_in'] ?? 3600));
        $wpdb->update($t, [
            'access_token'     => $new['access_token'],
            'token_expires_at' => $expires_at,
        ], ['user_id' => $uid, 'provider' => $db_provider]);

        return $new['access_token'];
    }

    return $conn->access_token;
}

// ── OAuth Connections ─────────────────────────────────────────────────────────

function painel_rest_list_connections(): WP_REST_Response
{
    global $wpdb;
    $t   = RHUB_DB_PREFIX . 'oauth_connections';
    $uid = get_current_user_id();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT provider, account_email, account_name, connected_at, token_expires_at
         FROM {$t} WHERE user_id = %d AND disconnected_at IS NULL",
        $uid
    ));
    return new WP_REST_Response($rows ?: [], 200);
}

function painel_rest_google_omnichannel_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $scopes = implode(' ', [
        'openid email profile',
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/meetings.space.created',
        'https://www.googleapis.com/auth/contacts.readonly',
        'https://www.googleapis.com/auth/chat.messages.readonly',
    ]);
    return painel_build_google_auth_url('google-omnichannel', $scopes, $r);
}

function painel_rest_google_drive_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $scopes = implode(' ', [
        'openid email profile',
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/spreadsheets.readonly',
    ]);
    return painel_build_google_auth_url('google-drive', $scopes, $r);
}

function painel_rest_gmail_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    return painel_build_google_auth_url('gmail', 'openid email profile https://www.googleapis.com/auth/gmail.modify', $r);
}

function painel_rest_google_analytics_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $scopes = implode(' ', [
        'openid email profile',
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/adwords',
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
    ]);
    return painel_build_google_auth_url('google-analytics', $scopes, $r);
}

function painel_rest_google_ads_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    return painel_build_google_auth_url('google-ads', 'openid email profile https://www.googleapis.com/auth/adwords', $r);
}

function painel_rest_google_calendar_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    return painel_build_google_auth_url('google-calendar', 'openid email profile https://www.googleapis.com/auth/calendar.readonly', $r);
}

function painel_rest_google_meet_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    return painel_build_google_auth_url('google-meet', 'openid email profile https://www.googleapis.com/auth/meetings.space.created', $r);
}

function painel_rest_google_contacts_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    return painel_build_google_auth_url('google-contacts', 'openid email profile https://www.googleapis.com/auth/contacts.readonly', $r);
}

function painel_rest_youtube_auth_url(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    return painel_build_google_auth_url('youtube', 'openid email profile https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly', $r);
}

function painel_resolve_frontend_url(WP_REST_Request $r): string
{
    $allowed   = array_map('trim', explode(',', getenv('ALLOWED_ORIGIN') ?: 'http://localhost:4200'));
    $param     = sanitize_text_field($r->get_param('frontend_url') ?? '');
    $candidate = rtrim($param, '/');
    if ($candidate) {
        $p      = parse_url($candidate);
        $origin = ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '');
        if (!empty($p['port'])) $origin .= ':' . $p['port'];
        foreach ($allowed as $a) {
            if (rtrim($a, '/') === $origin) return $candidate;
        }
    }
    return rtrim($allowed[0], '/');
}

function painel_build_google_auth_url(string $provider, string $scope, WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $client_id = painel_get_oauth_setting('google_client_id');
    if (!$client_id) {
        return new WP_Error('not_configured', 'Google OAuth não configurado.', ['status' => 503]);
    }

    $frontend = painel_resolve_frontend_url($r);
    $state    = wp_generate_password(32, false);
    set_transient('rhub_oauth_state_' . $state, ['user_id' => get_current_user_id(), 'provider' => $provider, 'frontend_url' => $frontend], 600);

    $params = [
        'client_id'     => $client_id,
        'redirect_uri'  => rest_url('rh/v1/connections/google/callback'),
        'response_type' => 'code',
        'scope'         => $scope,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ];

    return new WP_REST_Response([
        'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params),
    ], 200);
}

function painel_rest_google_callback(WP_REST_Request $r): never
{
    $allowed  = array_map('trim', explode(',', getenv('ALLOWED_ORIGIN') ?: 'http://localhost:4200'));
    $frontend = rtrim($allowed[0], '/');
    $error_url = $frontend . '/me/conexoes?oauth=error';

    $state = sanitize_text_field($r->get_param('state') ?? '');
    $code  = sanitize_text_field($r->get_param('code')  ?? '');

    if (!$state || !$code) {
        wp_redirect($error_url . '&reason=missing_params'); exit;
    }

    $state_data = get_transient('rhub_oauth_state_' . $state);
    delete_transient('rhub_oauth_state_' . $state);

    // suporte ao formato antigo (só user_id) e novo (array com user_id + provider + frontend_url)
    if (is_array($state_data)) {
        $user_id  = (int) ($state_data['user_id'] ?? 0);
        $provider = sanitize_key($state_data['provider'] ?? 'google-drive');
        if (!empty($state_data['frontend_url'])) {
            $frontend  = rtrim($state_data['frontend_url'], '/');
            $error_url = $frontend . '/me/conexoes?oauth=error';
        }
    } else {
        $user_id  = (int) $state_data;
        $provider = 'google-drive';
    }

    if (!$user_id) {
        wp_redirect($error_url . '&reason=invalid_state'); exit;
    }

    $client_id     = painel_get_oauth_setting('google_client_id');
    $client_secret = painel_get_oauth_setting('google_client_secret');
    $redirect_uri  = rest_url('rh/v1/connections/google/callback');

    $token_resp = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body' => compact('code', 'client_id', 'client_secret', 'redirect_uri') + ['grant_type' => 'authorization_code'],
    ]);

    if (is_wp_error($token_resp)) {
        wp_redirect($error_url . '&reason=token_request_failed'); exit;
    }

    $token = json_decode(wp_remote_retrieve_body($token_resp), true);

    if (empty($token['access_token'])) {
        wp_redirect($error_url . '&reason=no_access_token'); exit;
    }

    $info_resp  = wp_remote_get('https://www.googleapis.com/oauth2/v3/userinfo', [
        'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
    ]);
    $userinfo       = !is_wp_error($info_resp) ? json_decode(wp_remote_retrieve_body($info_resp), true) : [];
    $account_email  = sanitize_email($userinfo['email']  ?? '');
    $account_name   = sanitize_text_field($userinfo['name'] ?? '');

    $expires_at = !empty($token['expires_in'])
        ? gmdate('Y-m-d H:i:s', time() + (int) $token['expires_in'])
        : null;

    global $wpdb;
    $t        = RHUB_DB_PREFIX . 'oauth_connections';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t} WHERE user_id = %d AND provider = %s", $user_id, $provider
    ));

    $data = [
        'access_token'     => $token['access_token'],
        'refresh_token'    => $token['refresh_token'] ?? null,
        'token_expires_at' => $expires_at,
        'account_email'    => $account_email,
        'account_name'     => $account_name,
        'disconnected_at'  => null,
        'connected_at'     => current_time('mysql'),
    ];

    if ($existing) {
        $wpdb->update($t, $data, ['user_id' => $user_id, 'provider' => $provider]);
    } else {
        $wpdb->insert($t, array_merge($data, ['user_id' => $user_id, 'provider' => $provider]));
    }

    wp_redirect($frontend . '/me/conexoes?oauth=success'); exit;
}

function painel_rest_disconnect_provider(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    global $wpdb;
    $uid      = get_current_user_id();
    $provider = sanitize_key($r['provider']);
    $t        = RHUB_DB_PREFIX . 'oauth_connections';

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t} WHERE user_id = %d AND provider = %s AND disconnected_at IS NULL",
        $uid, $provider
    ));

    if (!$existing) {
        return new WP_Error('not_connected', 'Provider não conectado.', ['status' => 404]);
    }

    $wpdb->update($t, ['disconnected_at' => current_time('mysql')], ['user_id' => $uid, 'provider' => $provider]);
    return new WP_REST_Response(null, 204);
}

function painel_get_oauth_setting(string $key): string
{
    $env_map = [
        'google_client_id'     => 'GOOGLE_CLIENT_ID',
        'google_client_secret' => 'GOOGLE_CLIENT_SECRET',
    ];

    if (isset($env_map[$key])) {
        $val = getenv($env_map[$key]);
        if ($val !== false && $val !== '') return $val;
    }

    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare(
        'SELECT setting_value FROM ' . RHUB_DB_PREFIX . 'settings WHERE setting_key = %s',
        'oauth_' . $key
    ));
}

// ── Plugins Management ────────────────────────────────────────────────────────

function painel_rest_list_plugins(): WP_REST_Response
{
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    $response = [];

    $extra_headers = [
        'painel'      => 'Painel',
        'coming_soon' => 'Coming Soon',
        'price'       => 'Price',
        'image'       => 'Image',
        'video'       => 'Video',
        'cpts'        => 'CPTs',
    ];

    foreach ( $all_plugins as $file => $data ) {
        $plugin_file = WP_PLUGIN_DIR . '/' . $file;
        $extra       = get_file_data( $plugin_file, $extra_headers );

        if ( $extra['painel'] !== 'true' ) continue;

        $response[] = [
            'file'        => $file,
            'name'        => $data['Name'] ?? '',
            'description' => $data['Description'] ?? '',
            'version'     => $data['Version'] ?? '',
            'author'      => $data['AuthorName'] ?? $data['Author'] ?? '',
            'active'      => is_plugin_active( $file ),
            'coming_soon' => $extra['coming_soon'] === 'true',
            'price'       => $extra['price'] ?? '',
            'image'       => $extra['image'] ?? '',
            'video'       => $extra['video'] ?? '',
            'cpts'        => array_values( array_filter( array_map( 'trim', explode( ',', $extra['cpts'] ?? '' ) ) ) ),
            'nav'         => apply_filters( 'rh_plugin_nav', [], $file ),
        ];
    }

    return new WP_REST_Response($response, 200);
}

function painel_rest_toggle_plugin(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $body = $r->get_json_params();
    $file = sanitize_text_field($body['file'] ?? '');
    $active = (bool) ($body['active'] ?? false);

    if (!$file) {
        return new WP_Error('missing_file', 'O campo file é obrigatório.', ['status' => 422]);
    }

    $all_plugins = get_plugins();
    if (!isset($all_plugins[$file])) {
        return new WP_Error('invalid_plugin', 'Plugin não encontrado.', ['status' => 404]);
    }

    $extra = get_file_data(WP_PLUGIN_DIR . '/' . $file, ['coming_soon' => 'Coming Soon']);
    if ($extra['coming_soon'] === 'true') {
        return new WP_Error('plugin_coming_soon', 'Este módulo ainda não está disponível.', ['status' => 403]);
    }

    if ($active) {
        $result = activate_plugin($file);
        if (is_wp_error($result)) {
            return $result;
        }
    } else {
        deactivate_plugins($file);
    }

    return new WP_REST_Response([
        'file' => $file,
        'active' => is_plugin_active($file),
    ], 200);
}

function painel_rest_lost_password(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $email = sanitize_text_field($r->get_param('email') ?? '');
    if (empty($email)) {
        return new WP_Error('missing_email', 'O campo email é obrigatório.', ['status' => 422]);
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        $user = get_user_by('login', $email);
    }

    $login_name = '';
    if ($user) {
        $login_name = $user->user_login;
        $key = get_password_reset_key($user);
        if (!is_wp_error($key)) {
            require_once PAINEL_PLUGIN_DIR . 'emails.php';
            if (function_exists('painel_email_forgot_password')) {
                painel_email_forgot_password($user->user_email, $user->display_name ?: $user->user_login, $key);
            }
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'login' => $login_name,
        'message' => 'Se o e-mail fornecido for válido, uma mensagem contendo o código de recuperação foi enviada.'
    ], 200);
}

function painel_rest_reset_password(WP_REST_Request $r): WP_REST_Response|WP_Error
{
    $key = sanitize_text_field($r->get_param('key') ?? '');
    $login = sanitize_text_field($r->get_param('login') ?? '');
    $password = $r->get_param('password') ?? '';

    if (empty($key) || empty($login) || empty($password)) {
        return new WP_Error('missing_fields', 'Todos os campos são obrigatórios.', ['status' => 422]);
    }

    $user = check_password_reset_key($key, $login);
    if (is_wp_error($user)) {
        return new WP_Error('invalid_key', 'O código de redefinição é inválido ou expirou.', ['status' => 400]);
    }

    reset_password($user, $password);

    return new WP_REST_Response(['success' => true, 'message' => 'Senha alterada com sucesso!'], 200);
}

// ── Options Pages ─────────────────────────────────────────────────────────────

function painel_rest_list_options_pages(): WP_REST_Response
{
    if (!class_exists('CMB2_Boxes')) {
        return new WP_REST_Response([], 200);
    }

    $pages = [];

    foreach (CMB2_Boxes::get_all() as $box) {
        if (!in_array('options-page', (array) $box->prop('object_types'), true)) {
            continue;
        }

        $raw = $box->prop('option_key');
        $key = is_array($raw) ? (string) reset($raw) : (string) $raw;
        $key = $key ?: (string) $box->prop('id');

        if (!$key || isset($pages[$key])) {
            continue; // já registrado (múltiplos boxes da mesma página)
        }

        $pages[$key] = [
            'key'   => $key,
            'title' => (string) ($box->prop('menu_title') ?: $box->prop('title')),
        ];
    }

    return new WP_REST_Response(array_values($pages), 200);
}

function painel_rest_get_options_page(WP_REST_Request $r): WP_REST_Response
{
    $key = sanitize_key($r['key']);

    if (!class_exists('CMB2_Boxes')) {
        return new WP_REST_Response(['tabs' => [], 'values' => []], 200);
    }

    $tabs        = [];
    $page_title  = '';
    $values      = (array) get_option($key, []);

    foreach (CMB2_Boxes::get_all() as $box) {
        if (!in_array('options-page', (array) $box->prop('object_types'), true)) {
            continue;
        }
        $raw_key = $box->prop('option_key');
        $box_key = is_array($raw_key) ? (string) reset($raw_key) : (string) $raw_key;
        $box_key = $box_key ?: (string) $box->prop('id');
        if ($box_key !== $key) {
            continue;
        }

        if (!$page_title) {
            $page_title = (string) ($box->prop('menu_title') ?: $box->prop('title'));
        }

        $fields = [];
        foreach ($box->prop('fields') as $field) {
            if (isset($field['angular']) && $field['angular'] === false) continue;
            $fields[] = painel_serialize_cmb2_field($field);
        }

        $tabs[] = [
            'title'  => (string) ($box->prop('title')),
            'fields' => $fields,
        ];
    }

    return new WP_REST_Response(['title' => $page_title, 'tabs' => $tabs, 'values' => $values], 200);
}

function painel_rest_save_options_page(WP_REST_Request $r): WP_REST_Response
{
    $key    = sanitize_key($r['key']);
    $body   = $r->get_json_params();
    $values = $body['values'] ?? [];

    if (!is_array($values)) {
        return new WP_REST_Response(['error' => 'Payload inválido.'], 400);
    }

    $current = (array) get_option($key, []);
    $updated = array_merge($current, $values);
    update_option($key, $updated, false);

    return new WP_REST_Response(['success' => true], 200);
}
