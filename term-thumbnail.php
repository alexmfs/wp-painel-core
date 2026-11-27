<?php
if (!defined('ABSPATH')) exit;

// Registra meta thumbnail_id em todas as taxonomias com REST habilitado
add_action('init', function () {
    $taxonomies = get_taxonomies(['show_in_rest' => true], 'names');
    foreach ($taxonomies as $tax) {
        register_term_meta($tax, 'thumbnail_id', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => function () {
                return current_user_can('edit_posts');
            },
        ]);
    }
}, 20);

// Expõe thumbnail_url calculado a partir do meta thumbnail_id
add_action('rest_api_init', function () {
    $taxonomies = get_taxonomies(['show_in_rest' => true], 'names');
    foreach ($taxonomies as $tax) {
        register_rest_field($tax, 'thumbnail_url', [
            'get_callback' => function ($item) {
                $id = (int) get_term_meta($item['id'], 'thumbnail_id', true);
                if (!$id) return '';
                return (string) (wp_get_attachment_url($id) ?: '');
            },
            'schema' => ['type' => 'string', 'context' => ['view', 'edit']],
        ]);
    }
});
