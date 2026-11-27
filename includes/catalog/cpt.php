<?php
if (!defined('ABSPATH')) exit;

rh_register_cpt_icon('catalog', 'shopping-cart');

add_action('init', function () {
    register_post_type('catalog', [
        'label'           => 'Catálogo',
        'labels'          => [
            'name'          => 'Catálogo',
            'singular_name' => 'Item de Catálogo',
            'add_new_item'  => 'Novo Item de Catálogo',
            'edit_item'     => 'Editar Item de Catálogo',
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_rest'    => true,
        'rest_base'       => 'catalog',
        'capability_type' => 'post',
        'supports'        => ['title', 'thumbnail', 'custom-fields'],
        'has_archive'     => false,
        'rewrite'         => false,
        'menu_icon'       => 'dashicons-portfolio',
        'rh_icon'         => 'shopping-cart',
        'menu_type'       => 'entidade',
    ]);

    register_taxonomy('catalog_category', ['catalog'], [
        'label'        => 'Categorias',
        'public'       => false,
        'show_ui'      => true,
        'show_in_rest' => true,
        'hierarchical' => true,
        'rewrite'      => false,
    ]);
});
