<?php
if (!defined('ABSPATH')) exit;

add_action('cmb2_init', function () {

    $box = new_cmb2_box([
        'id'           => 'painel_email_config',
        'title'        => 'Servidor de E-mail',
        'object_types' => ['options-page'],
        'option_key'   => 'painel_email',
    ]);

    $box->add_field(['id' => 'smtp_host',      'name' => 'Host SMTP',        'type' => 'text']);
    $box->add_field(['id' => 'smtp_port',      'name' => 'Porta',            'type' => 'text']);
    $box->add_field(['id' => 'smtp_user',      'name' => 'Usuário',          'type' => 'text_email']);
    $box->add_field(['id' => 'smtp_password',  'name' => 'Senha',            'type' => 'text']);
    $box->add_field(['id' => 'smtp_from',      'name' => 'E-mail remetente', 'type' => 'text_email']);
    $box->add_field(['id' => 'smtp_from_name', 'name' => 'Nome remetente',   'type' => 'text']);

});

add_action('cmb2_init', function () {

    $box1 = new_cmb2_box([
        'id'           => 'painel_aparencia_identidade',
        'title'        => 'Identidade Visual',
        'object_types' => ['options-page'],
        'option_key'   => 'painel_aparencia',
    ]);

    $box1->add_field(['id' => 'logo_url',       'name' => 'Logo (URL)',      'type' => 'text_url']);
    $box1->add_field(['id' => 'favicon_url',    'name' => 'Favicon (URL)',   'type' => 'text_url']);
    $box1->add_field(['id' => 'cor_primaria',   'name' => 'Cor primária',    'type' => 'text', 'description' => 'Ex: #2f5d50']);
    $box1->add_field(['id' => 'cor_secundaria', 'name' => 'Cor secundária',  'type' => 'text']);

    $box2 = new_cmb2_box([
        'id'           => 'painel_aparencia_tipografia',
        'title'        => 'Tipografia',
        'object_types' => ['options-page'],
        'option_key'   => 'painel_aparencia',
    ]);

    $box2->add_field(['id' => 'font_family',    'name' => 'Família de fonte',   'type' => 'text', 'description' => 'Ex: Inter, sans-serif']);
    $box2->add_field(['id' => 'font_size_base', 'name' => 'Tamanho base (px)',  'type' => 'text']);

});
