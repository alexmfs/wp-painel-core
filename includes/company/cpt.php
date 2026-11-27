<?php
if (!defined('ABSPATH')) exit;

rh_register_cpt_icon('company', 'briefcase');

add_action('init', function () {
    register_post_type('company', [
        'label'           => 'Empresas',
        'public'          => false,
        'show_ui'         => true,
        'show_in_rest'    => true,
        'rest_base'       => 'companies',
        'capability_type' => 'post',
        'supports'        => ['title', 'custom-fields'],
        'has_archive'     => false,
        'rewrite'         => false,
        'menu_icon'       => 'dashicons-building',
        'rh_icon'         => 'briefcase',
        'menu_type'       => 'entidade',
    ]);
});
