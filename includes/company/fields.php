<?php
if (!defined('ABSPATH')) exit;

add_action('cmb2_init', function () {
    $cmb = new_cmb2_box([
        'id'           => 'company_fields',
        'title'        => 'Dados da Empresa',
        'object_types' => ['company'],
        'context'      => 'normal',
        'priority'     => 'high',
        'show_names'   => true,
    ]);

    $cmb->add_field(['name' => 'Nome fantasia', 'id' => 'trade_name', 'type' => 'text']);
    $cmb->add_field(['name' => 'CNPJ',          'id' => 'document',   'type' => 'text']);
    $cmb->add_field(['name' => 'Segmento',      'id' => 'segment',    'type' => 'text']);
    $cmb->add_field(['name' => 'Site',          'id' => 'website',    'type' => 'text_url']);
    $cmb->add_field(['name' => 'Observações',   'id' => 'notes',      'type' => 'textarea_small']);

    rhub_register_fields_contact($cmb);
});

add_action('cmb2_init', function () {
    rhub_register_fields_address('company');
});
