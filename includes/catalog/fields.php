<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    $string_fields = ['tipo', 'sku', 'preco_unitario', 'unidade'];
    foreach ($string_fields as $key) {
        register_post_meta('catalog', $key, ['type' => 'string', 'single' => true, 'show_in_rest' => true]);
    }
}, 20);

add_action('cmb2_init', function () {
    $cmb = new_cmb2_box([
        'id'           => 'catalog_fields',
        'title'        => 'Dados do Item',
        'object_types' => ['catalog'],
        'context'      => 'normal',
        'priority'     => 'high',
        'show_names'   => true,
    ]);

    $cmb->add_field([
        'name'    => 'Tipo',
        'id'      => 'tipo',
        'type'    => 'select',
        'options' => [
            'produto' => 'Produto',
            'servico' => 'Serviço',
        ],
        'default' => 'produto',
    ]);

    $cmb->add_field([
        'name' => 'SKU',
        'id'   => 'sku',
        'type' => 'text',
    ]);

    $cmb->add_field([
        'name' => 'Preço unitário',
        'id'   => 'preco_unitario',
        'type' => 'text_money',
    ]);

    $cmb->add_field([
        'name'    => 'Unidade',
        'id'      => 'unidade',
        'type'    => 'select',
        'options' => [
            'un'      => 'Unidade (un)',
            'hora'    => 'Hora (h)',
            'dia'     => 'Dia',
            'projeto' => 'Projeto',
            'm2'      => 'Metro quadrado (m²)',
            'm'       => 'Metro (m)',
            'kg'      => 'Quilograma (kg)',
            'lt'      => 'Litro (lt)',
        ],
        'default' => 'un',
    ]);

});
