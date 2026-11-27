<?php
if (!defined('ABSPATH')) exit;

rh_register_cpt_icon('lead', 'target');

add_action('init', function () {
    register_post_type('lead', [
        'label'               => 'Leads',
        'public'              => false,
        'show_ui'             => true,
        'show_in_rest'        => true,
        'rest_base'           => 'leads',
        'capability_type'     => 'post',
        'supports'            => ['title', 'custom-fields'],
        'has_archive'         => false,
        'rewrite'             => false,
        'menu_icon'           => 'dashicons-businessman',
        'rh_icon'             => 'target',
    ]);
});
