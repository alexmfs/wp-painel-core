<?php
if (!defined('ABSPATH')) exit;

add_action('cmb2_admin_init', function () {
    $cmb = new_cmb2_box([
        'id'           => 'task_fields',
        'title'        => 'Dados da Tarefa',
        'object_types' => ['task'],
        'context'      => 'normal',
        'priority'     => 'high',
        'show_names'   => true,
    ]);

    $cmb->add_field(['name' => 'Prazo',      'id' => 'due_date',  'type' => 'text_date', 'date_format' => 'Y-m-d']);
    $cmb->add_field(['name' => 'Prioridade', 'id' => 'priority',  'type' => 'select',
        'options' => ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'],
        'default' => 'media',
    ]);
    $cmb->add_field(['name' => 'Concluída',  'id' => 'done',      'type' => 'checkbox']);
});
