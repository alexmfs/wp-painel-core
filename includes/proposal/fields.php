<?php
if (!defined('ABSPATH')) exit;

add_action('cmb2_init', function () {
    $cmb = new_cmb2_box([
        'id'           => 'proposal_fields',
        'title'        => 'Dados da Proposta',
        'object_types' => ['proposal'],
        'context'      => 'normal',
        'priority'     => 'high',
        'show_names'   => true,
    ]);

    $cmb->add_field(['name' => 'Lead',       'id' => 'lead_id',     'type' => 'post_search_text', 'post_type' => 'lead']);
    $cmb->add_field(['name' => 'Válida até', 'id' => 'valid_until', 'type' => 'text_date', 'date_format' => 'Y-m-d']);
    $cmb->add_field(['name' => 'Total',      'id' => 'total',       'type' => 'text_money']);
    $cmb->add_field(['name' => 'Status',     'id' => 'status',      'type' => 'select',
        'options' => ['rascunho' => 'Rascunho', 'enviada' => 'Enviada', 'aceita' => 'Aceita', 'recusada' => 'Recusada'],
        'default' => 'rascunho',
    ]);
});
