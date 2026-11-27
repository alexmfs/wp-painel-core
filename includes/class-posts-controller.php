<?php
if (!defined('ABSPATH')) exit;

class Painel_Posts_Controller extends WP_REST_Controller {

    protected $namespace = 'rh/v1';
    protected $rest_base = 'posts';

    public function register_routes(): void {
        $type_pattern = '(?P<post_type>[a-z0-9_-]+)';

        register_rest_route($this->namespace, "/{$this->rest_base}/{$type_pattern}", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => 'painel_auth',
                'args'                => $this->get_collection_params(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => 'painel_auth',
            ],
        ]);

        register_rest_route($this->namespace, "/{$this->rest_base}/{$type_pattern}/(?P<id>\d+)", [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => 'painel_auth',
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => 'painel_auth',
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => 'painel_auth',
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => 'painel_auth',
            ],
        ]);
    }

    public function get_items($request): WP_REST_Response|WP_Error {
        $post_type = $this->resolve_post_type($request['post_type']);

        if (!$post_type) {
            return new WP_Error('invalid_post_type', 'Tipo inválido.', ['status' => 404]);
        }

        $per_page = min((int) ($request['per_page'] ?? 20), 100);
        $page     = max((int) ($request['page'] ?? 1), 1);

        $args = [
            'post_type'      => $post_type,
            'post_status'    => $request['status'] ?: 'any',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $request['orderby'] ?: 'date',
            'order'          => $request['order'] ?: 'DESC',
        ];

        if (!empty($request['search'])) {
            $args['s'] = sanitize_text_field($request['search']);
        }

        if (!empty($request['slug'])) {
            $args['name'] = sanitize_title($request['slug']);
        }

        if (!empty($request['meta_key']) && isset($request['meta_value'])) {
            $args['meta_key']   = sanitize_key($request['meta_key']);
            $args['meta_value'] = sanitize_text_field($request['meta_value']);
        }

        $query = new WP_Query($args);

        return rest_ensure_response([
            'items' => array_map(fn($post) => $this->format_post($post, true), $query->posts),
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ]);
    }

    public function get_item($request): WP_REST_Response|WP_Error {
        $post_type = $this->resolve_post_type($request['post_type']);
        $post      = get_post((int) $request['id']);

        if (!$post || $post->post_type !== $post_type) {
            return new WP_Error('not_found', 'Post não encontrado.', ['status' => 404]);
        }

        return rest_ensure_response($this->format_post($post));
    }

    public function create_item($request): WP_REST_Response|WP_Error {
        $post_type = $this->resolve_post_type($request['post_type']);

        if (!$post_type) {
            return new WP_Error('invalid_post_type', 'Tipo inválido.', ['status' => 404]);
        }

        $data = [
            'post_type'     => $post_type,
            'post_title'    => sanitize_text_field($request['title'] ?? ''),
            'post_content'  => wp_kses_post($request['content'] ?? ''),
            'post_excerpt'  => sanitize_text_field($request['excerpt'] ?? ''),
            'post_status'   => sanitize_key($request['status'] ?? 'draft'),
            'post_name'     => !empty($request['slug']) ? sanitize_title($request['slug']) : '',
            'post_author'   => isset($request['author']) ? (int) $request['author'] : get_current_user_id(),
            'post_parent'   => isset($request['parent']) ? (int) $request['parent'] : 0,
            'post_password' => isset($request['password']) ? sanitize_text_field($request['password']) : '',
        ];

        if (!empty($request['date'])) {
            $data['post_date'] = $this->parse_date($request['date']);
        }

        $id = wp_insert_post($data, true);
        if (is_wp_error($id)) return $id;

        $this->save_meta($id, $post_type, (array) ($request['meta'] ?? []));
        $this->save_featured_media($id, $request);
        $this->save_taxonomies($id, $post_type, $request);

        $response = rest_ensure_response($this->format_post(get_post($id)));
        $response->set_status(201);

        return $response;
    }

    public function update_item($request): WP_REST_Response|WP_Error {
        $post_type = $this->resolve_post_type($request['post_type']);
        $post      = get_post((int) $request['id']);

        if (!$post || $post->post_type !== $post_type) {
            return new WP_Error('not_found', 'Post não encontrado.', ['status' => 404]);
        }

        $data = ['ID' => $post->ID];

        if (isset($request['title']))    $data['post_title']    = sanitize_text_field($request['title']);
        if (isset($request['content']))  $data['post_content']  = wp_kses_post($request['content']);
        if (isset($request['excerpt']))  $data['post_excerpt']  = sanitize_text_field($request['excerpt']);
        if (isset($request['status']))   $data['post_status']   = sanitize_key($request['status']);
        if (isset($request['slug']))     $data['post_name']     = sanitize_title($request['slug']);
        if (isset($request['author']))   $data['post_author']   = (int) $request['author'];
        if (isset($request['parent']))   $data['post_parent']   = (int) $request['parent'];
        if (isset($request['password'])) $data['post_password'] = sanitize_text_field($request['password']);
        if (!empty($request['date']))    $data['post_date']     = $this->parse_date($request['date']);

        $result = wp_update_post($data, true);
        if (is_wp_error($result)) return $result;

        if (isset($request['meta'])) {
            $this->save_meta($post->ID, $post->post_type, (array) $request['meta']);
        }

        $this->save_featured_media($post->ID, $request);
        $this->save_taxonomies($post->ID, $post->post_type, $request);

        return rest_ensure_response($this->format_post(get_post($post->ID)));
    }

    public function delete_item($request): WP_REST_Response|WP_Error {
        $post_type = $this->resolve_post_type($request['post_type']);
        $post      = get_post((int) $request['id']);

        if (!$post || $post->post_type !== $post_type) {
            return new WP_Error('not_found', 'Post não encontrado.', ['status' => 404]);
        }

        $pt_obj = get_post_type_object($post->post_type);
        $force  = (bool) $request->get_param('force');

        if ($force && !empty($pt_obj->soft_delete_only)) {
            return new WP_Error('soft_delete_only', 'Este registro não pode ser excluído permanentemente.', ['status' => 403]);
        }

        wp_delete_post($post->ID, $force);

        return rest_ensure_response(['deleted' => true, 'id' => $post->ID]);
    }

    public function prepare_item_for_response($post, $request): WP_REST_Response {
        return rest_ensure_response($this->format_post($post));
    }

    private function resolve_post_type(string $input): string|null {
        if (post_type_exists($input)) return $input;
        foreach (get_post_types([], 'objects') as $pt) {
            if (($pt->rest_base ?? '') === $input) return $pt->name;
        }
        return null;
    }

    private function format_post(WP_Post $post, bool $collection = false): array {
        $meta = [];
        if (!$collection) {
            foreach (array_keys(get_registered_meta_keys('post', $post->post_type)) as $key) {
                $meta[$key] = get_post_meta($post->ID, $key, true);
            }
        }

        $taxonomies = [];
        if (!$collection) {
            foreach (get_object_taxonomies($post->post_type) as $tax) {
                $term_ids = wp_get_post_terms($post->ID, $tax, ['fields' => 'ids']);
                $taxonomies[$tax] = is_wp_error($term_ids) ? [] : array_values($term_ids);
            }
        }

        return array_merge([
            'id'             => $post->ID,
            'title'          => ['raw' => $post->post_title,   'rendered' => $post->post_title],
            'status'         => $post->post_status,
            'slug'           => $post->post_name,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'author'         => (int) $post->post_author,
            'parent'         => (int) $post->post_parent,
            'featured_media'  => (int) get_post_thumbnail_id($post->ID) ?: 0,
            'thumbnail_url'   => get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
            'categories'     => $taxonomies['category'] ?? [],
            'tags'           => $taxonomies['post_tag'] ?? [],
        ], $collection ? $taxonomies : array_merge($taxonomies, [
            'content'  => ['raw' => $post->post_content, 'rendered' => $post->post_content],
            'excerpt'  => ['raw' => $post->post_excerpt, 'rendered' => $post->post_excerpt],
            'password' => $post->post_password,
            'meta'     => $meta,
        ]));
    }

    private function save_meta(int $post_id, string $post_type, array $meta): void {
        $allowed  = array_keys(get_registered_meta_keys('post', $post_type));
        $excluded = (array) apply_filters('rh_post_save_exclude_meta', [], $post_type);
        foreach ($meta as $key => $value) {
            if (in_array($key, $allowed, true) && !in_array($key, $excluded, true)) {
                update_post_meta($post_id, $key, $value);
            }
        }
    }

    private function save_featured_media(int $post_id, WP_REST_Request $request): void {
        if (!isset($request['featured_media'])) return;
        $thumbnail_id = (int) $request['featured_media'];
        if ($thumbnail_id > 0) {
            set_post_thumbnail($post_id, $thumbnail_id);
        } else {
            delete_post_thumbnail($post_id);
        }
    }

    private function save_taxonomies(int $post_id, string $post_type, WP_REST_Request $request): void {
        if (isset($request['tags']) && is_array($request['tags'])) {
            wp_set_post_terms($post_id, array_map('intval', $request['tags']), 'post_tag');
        }
        if (isset($request['categories']) && is_array($request['categories'])) {
            wp_set_post_terms($post_id, array_map('intval', $request['categories']), 'category');
        }
        foreach (get_object_taxonomies($post_type) as $tax) {
            if (in_array($tax, ['category', 'post_tag'], true)) continue;
            if (isset($request[$tax]) && is_array($request[$tax])) {
                wp_set_post_terms($post_id, array_map('intval', $request[$tax]), $tax);
            }
        }
    }

    private function parse_date(string $date): string {
        return date('Y-m-d H:i:s', strtotime($date));
    }

    public function get_collection_params(): array {
        return [
            'page'       => ['default' => 1,      'type' => 'integer', 'minimum' => 1],
            'per_page'   => ['default' => 20,     'type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'search'     => ['default' => '',     'type' => 'string'],
            'status'     => ['default' => '',     'type' => 'string'],
            'orderby'    => ['default' => 'date', 'type' => 'string', 'enum' => ['date', 'modified', 'title', 'id', 'menu_order']],
            'order'      => ['default' => 'DESC', 'type' => 'string', 'enum' => ['ASC', 'DESC']],
            'meta_key'   => ['default' => '',     'type' => 'string'],
            'meta_value' => ['default' => '',     'type' => 'string'],
        ];
    }
}

add_action('rest_api_init', function () {
    (new Painel_Posts_Controller())->register_routes();
});
