<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    register_taxonomy('media_folder', ['attachment'], [
        'label'                 => 'Pastas',
        'public'                => false,
        'show_ui'               => true,
        'show_in_rest'          => true,
        'hierarchical'          => true,
        'rewrite'               => false,
        'update_count_callback' => '_painel_update_media_folder_count',
    ]);
});

function _painel_update_media_folder_count(array $terms, WP_Taxonomy $taxonomy): void {
    global $wpdb;
    foreach ($terms as $term) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             WHERE tr.term_taxonomy_id = %d
               AND p.post_status = 'inherit'
               AND p.post_type  = 'attachment'",
            $term
        ));
        $wpdb->update($wpdb->term_taxonomy, ['count' => $count], ['term_taxonomy_id' => $term]);
    }
}
