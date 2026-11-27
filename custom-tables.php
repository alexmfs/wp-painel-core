<?php
if (!defined('ABSPATH')) exit;

/**
 * Cria/atualiza todas as tabelas customizadas do plugin.
 * dbDelta é idempotente: rodar de novo só aplica diferenças de schema.
 */
function painel_create_custom_tables(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $p = RHUB_DB_PREFIX;

    $sql = [];

    // entity_relationships: ligação polimórfica entre qualquer entidade
    $sql[] = "CREATE TABLE rh_entity_relationships (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        from_id BIGINT UNSIGNED NOT NULL,
        from_type VARCHAR(50) NOT NULL,
        to_id BIGINT UNSIGNED NOT NULL,
        to_type VARCHAR(50) NOT NULL,
        relation_type VARCHAR(50) NOT NULL,
        meta LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_from (from_id, from_type, relation_type),
        KEY idx_to (to_id, to_type, relation_type)
    ) {$charset_collate};";

    // settings: chave-valor fora do wp_options
    $sql[] = "CREATE TABLE {$p}settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL,
        setting_value LONGTEXT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_key (setting_key)
    ) {$charset_collate};";

    // groups: agrupador genérico ligado a qualquer WP post
    $sql[] = "CREATE TABLE {$p}groups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        post_type VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT 'Grupo',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_post (post_id, post_type)
    ) {$charset_collate};";

    // group_items: itens de um grupo, referenciando qualquer WP post
    $sql[] = "CREATE TABLE {$p}group_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        ref_id BIGINT UNSIGNED NOT NULL,
        ref_type VARCHAR(64) NOT NULL,
        description TEXT NULL,
        quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        meta LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_group (group_id)
    ) {$charset_collate};";

    // event_log: auditoria append-only
    $sql[] = "CREATE TABLE {$p}event_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        subject_id BIGINT UNSIGNED NULL,
        subject_type VARCHAR(50) NULL,
        event_type VARCHAR(50) NOT NULL,
        meta LONGTEXT NULL,
        occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_subject (subject_id, subject_type),
        KEY idx_event_type (event_type)
    ) {$charset_collate};";

    // oauth_connections: tokens OAuth por usuário e provider
    $sql[] = "CREATE TABLE {$p}oauth_connections (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(50) NOT NULL,
        access_token LONGTEXT NOT NULL,
        refresh_token LONGTEXT NULL,
        token_expires_at DATETIME NULL,
        account_email VARCHAR(255) NULL,
        account_name VARCHAR(255) NULL,
        connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        disconnected_at DATETIME NULL,
        UNIQUE KEY uq_user_provider (user_id, provider),
        KEY idx_provider (provider)
    ) {$charset_collate};";

    foreach ($sql as $statement) {
        dbDelta($statement);
    }

    update_option('rhub_db_version', PAINEL_VERSION);
}

register_activation_hook(PAINEL_PLUGIN_FILE, 'painel_create_custom_tables');

// Migração única: rhub_relations → rh_entity_relationships
add_action('plugins_loaded', function () {
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE 'rhub_relations'")) {
        $wpdb->query("RENAME TABLE rhub_relations TO rh_entity_relationships");
    }
}, 5);

// Atualiza schema automaticamente se a versão mudou (sem desativar/reativar o plugin)
add_action('plugins_loaded', function () {
    if (get_option('rhub_db_version') !== PAINEL_VERSION) {
        painel_create_custom_tables();
    }
});

// ── Funções de acesso a rh_entity_relationships ─────────────────────────────

function painel_relate(
    int $fromId, string $fromType,
    int $toId, string $toType,
    string $relationType, array $meta = []
): int {
    global $wpdb;
    $wpdb->insert('rh_entity_relationships', [
        'from_id'       => $fromId,
        'from_type'     => $fromType,
        'to_id'         => $toId,
        'to_type'       => $toType,
        'relation_type' => $relationType,
        'meta'          => wp_json_encode($meta),
    ]);
    return (int) $wpdb->insert_id;
}

function painel_unrelate(int $relationId): bool
{
    global $wpdb;
    return (bool) $wpdb->delete('rh_entity_relationships', ['id' => $relationId]);
}

function painel_get_related(
    int $id, string $type, string $relationType, string $direction = 'from'
): array {
    global $wpdb;
    $col     = $direction === 'from' ? 'from_id'   : 'to_id';
    $colType = $direction === 'from' ? 'from_type'  : 'to_type';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM rh_entity_relationships
         WHERE {$col} = %d AND {$colType} = %s AND relation_type = %s",
        $id, $type, $relationType
    ));

    return array_map(function ($row) {
        if ($row->meta) {
            $row->meta = json_decode($row->meta, true);
        }
        return $row;
    }, $rows);
}

// Remove relações órfãs ao deletar um post
add_action('before_delete_post', function (int $postId): void {
    global $wpdb;
    $t = 'rh_entity_relationships';
    $wpdb->delete($t, ['from_id' => $postId, 'from_type' => 'post']);
    $wpdb->delete($t, ['to_id'   => $postId, 'to_type'   => 'post']);
});

// ── Funções de acesso a rhub_groups / rhub_group_items ───────────────────────

function painel_get_groups(int $postId): array
{
    global $wpdb;
    $p      = RHUB_DB_PREFIX;
    $groups = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$p}groups WHERE post_id = %d ORDER BY sort_order ASC, id ASC",
        $postId
    )) ?: [];

    foreach ($groups as $group) {
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}group_items WHERE group_id = %d ORDER BY sort_order ASC, id ASC",
            $group->id
        )) ?: [];

        foreach ($items as $item) {
            $item->quantity   = (float) $item->quantity;
            $item->unit_price = (float) $item->unit_price;
            $item->meta       = $item->meta ? json_decode($item->meta, true) : null;
            $item->ref        = painel_embed_ref((int) $item->ref_id, $item->ref_type);
        }

        $group->sort_order = (int) $group->sort_order;
        $group->items      = $items;
    }

    return $groups;
}

function painel_embed_ref(int $refId, string $refType): array
{
    if (!$refId) return [];
    return [
        'id'        => $refId,
        'title'     => get_the_title($refId),
        'thumbnail' => get_the_post_thumbnail_url($refId, 'thumbnail') ?: null,
        'meta'      => get_post_meta($refId) ? array_map(fn($v) => $v[0], get_post_meta($refId)) : [],
    ];
}

// Remove grupos e itens ao deletar um post
add_action('before_delete_post', function (int $postId): void {
    global $wpdb;
    $p      = RHUB_DB_PREFIX;
    $groups = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$p}groups WHERE post_id = %d", $postId
    ));
    if ($groups) {
        $ids = implode(',', array_map('intval', $groups));
        $wpdb->query("DELETE FROM {$p}group_items WHERE group_id IN ({$ids})");
        $wpdb->query("DELETE FROM {$p}groups WHERE post_id = {$postId}");
    }
}, 5);
