<?php
if (!defined('ABSPATH')) exit;

function rhub_register_fields_contact(CMB2 $cmb): void
{
    $phones = $cmb->add_field([
        'name'       => 'Telefones',
        'id'         => 'phones',
        'type'       => 'group',
        'repeatable' => true,
        'options'    => [
            'group_title'   => 'Telefone {#}',
            'add_button'    => 'Adicionar telefone',
            'remove_button' => 'Remover',
            'sortable'      => false,
        ],
    ]);
    $cmb->add_group_field($phones, ['name' => 'Rótulo', 'id' => 'label',  'type' => 'text', 'description' => 'Ex: Celular, WhatsApp, Fixo, Trabalho']);
    $cmb->add_group_field($phones, ['name' => 'Número', 'id' => 'number', 'type' => 'text']);

    $emails = $cmb->add_field([
        'name'       => 'E-mails',
        'id'         => 'emails',
        'type'       => 'group',
        'repeatable' => true,
        'options'    => [
            'group_title'   => 'E-mail {#}',
            'add_button'    => 'Adicionar e-mail',
            'remove_button' => 'Remover',
            'sortable'      => false,
        ],
    ]);
    $cmb->add_group_field($emails, ['name' => 'Rótulo',   'id' => 'label',   'type' => 'text',       'description' => 'Ex: Pessoal, Trabalho, NF']);
    $cmb->add_group_field($emails, ['name' => 'Endereço', 'id' => 'address', 'type' => 'text_email']);

    $socials = $cmb->add_field([
        'name'       => 'Redes Sociais',
        'id'         => 'socials',
        'type'       => 'group',
        'repeatable' => true,
        'options'    => [
            'group_title'   => 'Rede social {#}',
            'add_button'    => 'Adicionar rede social',
            'remove_button' => 'Remover',
            'sortable'      => false,
        ],
    ]);
    $cmb->add_group_field($socials, ['name' => 'Rede',  'id' => 'network', 'type' => 'select', 'options' => [
        'instagram' => 'Instagram',
        'linkedin'  => 'LinkedIn',
        'facebook'  => 'Facebook',
        'youtube'   => 'YouTube',
        'tiktok'    => 'TikTok',
        'x'         => 'X (Twitter)',
        'whatsapp'  => 'WhatsApp',
        'outro'     => 'Outro',
    ]]);
    $cmb->add_group_field($socials, ['name' => 'URL / usuário', 'id' => 'handle', 'type' => 'text']);
}

function rhub_register_fields_address(string $objectType): void
{
    $cmb = new_cmb2_box([
        'id'           => $objectType . '_address',
        'title'        => 'Endereços',
        'object_types' => [$objectType],
        'context'      => 'normal',
        'priority'     => 'low',
        'show_names'   => true,
    ]);

    $group = $cmb->add_field([
        'name'       => 'Endereços',
        'id'         => 'addresses',
        'type'       => 'group',
        'repeatable' => true,
        'options'    => [
            'group_title'   => 'Endereço {#}',
            'add_button'    => 'Adicionar endereço',
            'remove_button' => 'Remover',
            'sortable'      => false,
        ],
    ]);

    $cmb->add_group_field($group, ['name' => 'Rótulo',       'id' => 'label',        'type' => 'text', 'description' => 'Ex: Residencial, Comercial, Entrega']);
    $cmb->add_group_field($group, ['name' => 'Logradouro',   'id' => 'street',       'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'Número',       'id' => 'number',       'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'Complemento',  'id' => 'complement',   'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'Bairro',       'id' => 'neighborhood', 'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'Cidade',       'id' => 'city',         'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'Estado',       'id' => 'state',        'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'CEP',          'id' => 'zip',          'type' => 'text']);
    $cmb->add_group_field($group, ['name' => 'País',         'id' => 'country',      'type' => 'text', 'default' => 'Brasil']);
}
