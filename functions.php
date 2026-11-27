<?php
if (!defined('ABSPATH')) exit;

function rh_register_cpt_icon(string $slug, string $icon): void {
    global $rh_cpt_icons;
    $rh_cpt_icons[$slug] = $icon;
}

function rh_get_cpt_icon(string $slug): string {
    global $rh_cpt_icons;
    return ($rh_cpt_icons ?? [])[$slug] ?? 'list';
}

function painel_get_person_id_by_user(int $userId): int
{
    $cached = wp_cache_get("person_by_user_{$userId}", 'painel');
    if ($cached !== false) return (int) $cached;

    $posts = get_posts([
        'post_type'      => 'person',
        'posts_per_page' => 1,
        'meta_key'       => 'user_id',
        'meta_value'     => $userId,
        'fields'         => 'ids',
    ]);

    $personId = $posts[0] ?? 0;
    wp_cache_set("person_by_user_{$userId}", $personId, 'painel', 300);
    return (int) $personId;
}

// Inclui roles do usuário na resposta do JWT token para evitar request extra ao /me.
add_filter('jwt_auth_token_before_dispatch', function (array $data, WP_User $user): array {
    $data['roles'] = array_values((array) $user->roles);
    return $data;
}, 10, 2);

// Auto-registra via REST todos os meta fields definidos em CMB2 boxes do plugin.
// Dispensa show_in_rest nos fields.php — basta usar cmb2_init (não cmb2_admin_init).
add_action('rest_api_init', function () {
    if (!class_exists('CMB2_Boxes')) return;
    foreach (CMB2_Boxes::get_all() as $box) {
        foreach ((array) $box->prop('object_types') as $post_type) {
            foreach ($box->prop('fields') ?? [] as $field) {
                if (empty($field['id'])) continue;
                register_post_meta($post_type, $field['id'], [
                    'show_in_rest'  => true,
                    'single'        => true,
                    'type'          => 'string',
                    'auth_callback' => fn() => current_user_can('edit_posts'),
                ]);
            }
        }
    }
});
