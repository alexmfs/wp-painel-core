<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers RockHub CRM abilities on the WP7 Abilities API.
 * Each ability wraps the existing painel_chat_execute_tool() logic.
 */
add_action('wp_abilities_api_init', function () {

    wp_register_ability('rockhub/create_post', [
        'label'               => __('Criar Registro', 'painel-core'),
        'description'         => 'Cria um novo registro no sistema (lead, proposta, produto, tarefa, post, etc.).',
        'execute_callback'    => function (?array $args): array {
            return painel_chat_execute_tool('wp_create_post', $args ?? []);
        },
        'permission_callback' => '__return_true',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'description' => 'Tipo do registro: lead, proposal, catalog, task, post'],
                'title'     => ['type' => 'string', 'description' => 'Título do registro'],
                'status'    => ['type' => 'string', 'enum' => ['publish', 'draft'], 'description' => 'Status (padrão: publish)'],
                'content'   => ['type' => 'string', 'description' => 'Descrição completa do registro'],
                'meta'      => ['type' => 'object', 'description' => 'Campos extras ex: {"total": "500.00", "status": "rascunho"}'],
            ],
            'required'   => ['post_type', 'title'],
        ],
    ]);

    wp_register_ability('rockhub/list_posts', [
        'label'               => __('Listar Registros', 'painel-core'),
        'description'         => 'Lista registros do sistema por tipo.',
        'execute_callback'    => function (?array $args): array {
            return painel_chat_execute_tool('wp_list_posts', $args ?? []);
        },
        'permission_callback' => '__return_true',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'description' => 'Tipo: lead, proposal, catalog, task, post'],
                'per_page'  => ['type' => 'integer', 'description' => 'Quantidade (padrão 10, máx 20)'],
                'search'    => ['type' => 'string', 'description' => 'Busca por título'],
            ],
            'required'   => ['post_type'],
        ],
    ]);

    wp_register_ability('rockhub/add_item_to_proposal', [
        'label'               => __('Adicionar Item à Proposta', 'painel-core'),
        'description'         => 'Adiciona um produto/serviço a uma proposta. Cria o grupo automaticamente se necessário. Use após criar a proposta.',
        'execute_callback'    => function (?array $args): array {
            return painel_chat_execute_tool('rh_add_item_to_proposal', $args ?? []);
        },
        'permission_callback' => '__return_true',
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'proposal_id' => ['type' => 'integer', 'description' => 'ID da proposta (retornado ao criar)'],
                'catalog_id'  => ['type' => 'integer', 'description' => 'ID do item do catálogo (use rockhub/list_posts com post_type=catalog para buscar)'],
                'quantity'    => ['type' => 'number',  'description' => 'Quantidade (padrão 1)'],
                'unit_price'  => ['type' => 'number',  'description' => 'Preço unitário (usa preço do produto se omitido)'],
                'description' => ['type' => 'string',  'description' => 'Descrição do item (opcional)'],
                'group_title' => ['type' => 'string',  'description' => 'Nome do grupo (padrão: "Itens")'],
            ],
            'required'   => ['proposal_id', 'catalog_id'],
        ],
    ]);
});
