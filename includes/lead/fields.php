<?php
if (!defined('ABSPATH')) exit;

add_action('cmb2_init', function () {
    $cmb = new_cmb2_box([
        'id'           => 'lead_fields',
        'title'        => 'Dados do Lead',
        'object_types' => ['lead'],
        'context'      => 'normal',
        'priority'     => 'high',
        'show_names'   => true,
    ]);

    $cmb->add_field(['name' => 'Pessoa',    'id' => 'person_id',  'type' => 'post_search_text', 'post_type' => 'person']);
    $cmb->add_field(['name' => 'Empresa',   'id' => 'company_id', 'type' => 'post_search_text', 'post_type' => 'company']);
    $cmb->add_field(['name' => 'Origem',    'id' => 'source',     'type' => 'select',
        'options' => ['site' => 'Site', 'indicacao' => 'Indicação', 'anuncio' => 'Anúncio', 'outro' => 'Outro']]);
    $cmb->add_field(['name' => 'Orçamento', 'id' => 'budget',     'type' => 'text_money']);
    $cmb->add_field(['name' => 'Observações','id' => 'notes',     'type' => 'textarea_small']);
    $cmb->add_field(['name' => 'Status',    'id' => 'status',     'type' => 'select',
        'options' => ['novo' => 'Novo', 'contato' => 'Contato feito', 'proposta' => 'Proposta enviada', 'ganho' => 'Ganho', 'perdido' => 'Perdido'],
        'default' => 'novo',
    ]);
});
