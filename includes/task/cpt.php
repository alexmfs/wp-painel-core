<?php
if (!defined('ABSPATH')) exit;

rh_register_cpt_icon('task', 'check-square');

add_action('init', function () {
    register_post_type('task', [
        'label'           => 'Tarefas',
        'public'          => false,
        'show_ui'         => true,
        'show_in_rest'    => true,
        'rest_base'       => 'tasks',
        'capability_type' => 'post',
        'supports'        => ['title', 'custom-fields'],
        'has_archive'     => false,
        'rewrite'         => false,
        'menu_icon'       => 'dashicons-yes-alt',
        'rh_icon'         => 'check-square',
    ]);
});
