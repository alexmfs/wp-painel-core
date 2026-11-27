<?php
if (!defined('ABSPATH')) exit;

rh_register_cpt_icon('proposal', 'file-text');

add_action('init', function () {
    register_post_type('proposal', [
        'label'           => 'Propostas',
        'public'          => false,
        'show_ui'         => true,
        'show_in_rest'    => true,
        'rest_base'       => 'proposals',
        'capability_type' => 'post',
        'supports'        => ['title', 'custom-fields', 'groups'],
        'has_archive'     => false,
        'rewrite'         => false,
        'menu_icon'       => 'dashicons-clipboard',
        'rh_icon'         => 'file-text',
        'list_type'        => 'kanban',
        'list_switchable'  => false,
        'kanban_field'     => 'status',
        'soft_delete_only' => true,
    ]);

    register_post_meta('proposal', 'client_id', [
        'type'         => 'integer',
        'single'       => true,
        'show_in_rest' => true,
        'default'      => 0,
    ]);
});
