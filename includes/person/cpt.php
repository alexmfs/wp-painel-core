<?php
if (!defined('ABSPATH')) exit;

rh_register_cpt_icon('person', 'user');

add_action('init', function () {
    register_post_type('person', [
        'label'           => 'Pessoas',
        'public'          => false,
        'show_ui'         => true,
        'show_in_rest'    => true,
        'rest_base'       => 'people',
        'capability_type' => 'post',
        'supports'        => ['title', 'thumbnail', 'custom-fields'],
        'has_archive'     => false,
        'rewrite'         => false,
        'menu_icon'       => 'dashicons-admin-users',
        'rh_icon'         => 'user',
        'menu_type'       => 'entidade',
    ]);
});
