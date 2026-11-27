<?php
if (!defined('ABSPATH')) exit;

add_action('cmb2_init', function () {
    $cmb = new_cmb2_box([
        'id'           => 'person_fields',
        'title'        => 'Dados Pessoais',
        'object_types' => ['person'],
        'context'      => 'normal',
        'priority'     => 'high',
        'show_names'   => true,
    ]);

    rhub_register_fields_contact($cmb);

    $cmb->add_field(['name' => 'CPF',               'id' => 'document',   'type' => 'text']);
    $cmb->add_field(['name' => 'Data de nascimento', 'id' => 'birth_date', 'type' => 'text_date', 'date_format' => 'Y-m-d']);
    $cmb->add_field(['name' => 'Empresa',            'id' => 'company_id', 'type' => 'post_search_text', 'post_type' => 'company']);
    $cmb->add_field(['name' => 'Cargo',              'id' => 'job_title',  'type' => 'text']);
    $cmb->add_field(['name' => 'Usuário do painel',  'id' => 'user_id',    'type' => 'text', 'description' => 'ID do wp_users — preencher apenas se essa pessoa tiver acesso ao painel']);
    $cmb->add_field(['name' => 'Observações',        'id' => 'notes',      'type' => 'textarea_small']);
});

add_action('cmb2_init', function () {
    rhub_register_fields_address('person');
});
