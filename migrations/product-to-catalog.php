<?php
/**
 * Migração: product → catalog
 *
 * Executa via WP-CLI:
 *   wp eval-file wp-content/plugins/painel-core/migrations/product-to-catalog.php
 *
 * Seguro para rodar múltiplas vezes (idempotente).
 */
if (!defined('ABSPATH')) {
    // Permite rodar via WP-CLI (bootstraped) ou include direto num contexto WordPress
    die('Run via WP-CLI: wp eval-file path/to/this/file.php');
}

global $wpdb;

// 1. wp_posts: altera post_type
$posts_updated = $wpdb->update(
    $wpdb->posts,
    ['post_type' => 'catalog'],
    ['post_type' => 'product'],
    ['%s'],
    ['%s']
);

WP_CLI::log("wp_posts atualizados: {$posts_updated}");

// 2. rhub_group_items: altera ref_type
$prefix = defined('RHUB_DB_PREFIX') ? RHUB_DB_PREFIX : $wpdb->prefix . 'rhub_';
$items_updated = $wpdb->update(
    "{$prefix}group_items",
    ['ref_type' => 'catalog'],
    ['ref_type' => 'product'],
    ['%s'],
    ['%s']
);

WP_CLI::log("rhub_group_items atualizados: {$items_updated}");

// 3. wp_term_taxonomy: renomeia a taxonomia product_category → catalog_category
$tax_updated = $wpdb->update(
    $wpdb->term_taxonomy,
    ['taxonomy' => 'catalog_category'],
    ['taxonomy' => 'product_category'],
    ['%s'],
    ['%s']
);

WP_CLI::log("wp_term_taxonomy atualizados: {$tax_updated}");

WP_CLI::success('Migração product → catalog concluída.');
