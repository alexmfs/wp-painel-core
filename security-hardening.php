<?php
/**
 * Security Hardening — CPTs privados + endpoints REST
 *
 * Como usar:
 * 1. Liste os CPTs privados em PRIVATE_POST_TYPES (linha abaixo).
 * 2. Liste a capability exigida para cada um em PRIVATE_POST_TYPES_CAPS.
 * 3. Inclua este arquivo uma vez no plugin principal (require_once).
 *
 * Tudo aqui é genérico — funciona para quantos CPTs você adicionar à lista,
 * sem precisar duplicar nenhuma regra.
 */

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────────────────────
// 1. CONFIGURAÇÃO — única parte que você edita ao adicionar um CPT novo
// ─────────────────────────────────────────────────────────────

if (!defined('PRIVATE_POST_TYPES')) {
    define('PRIVATE_POST_TYPES', [
        'lead',
        'proposal',
        'catalog',
        'task',
        // adicione novos CPTs privados aqui
    ]);
}

/**
 * Capability mínima exigida para LER cada CPT privado via REST.
 * Se um tipo não estiver aqui, cai no padrão: exige estar logado, sem
 * checar capability específica (ver get_required_capability() abaixo).
 */
if (!defined('PRIVATE_POST_TYPES_READ_CAP')) {
    define('PRIVATE_POST_TYPES_READ_CAP', [
        'lead' => 'edit_leads',
        'proposal' => 'edit_proposals',
        'catalog' => 'edit_catalogs',
        'task' => 'edit_tasks',
    ]);
}

// ─────────────────────────────────────────────────────────────
// 2. BLOQUEIO DE ACESSO REST — aplica a TODOS os CPTs da lista
// ─────────────────────────────────────────────────────────────

/**
 * Helper: resolve a capability exigida para um post_type, com fallback seguro.
 */
function security_get_required_capability(string $postType): string
{
    $map = PRIVATE_POST_TYPES_READ_CAP;
    return $map[$postType] ?? 'read'; // fallback: qualquer usuário logado, nunca anônimo
}

/**
 * Intercepta TODA requisição REST que toca um post_type da lista privada,
 * tanto rotas nativas (/wp/v2/{tipo}) quanto customizadas que usem
 * ?post_type= ou /{tipo}/ na rota.
 */
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    $route = $request->get_route();

    // Rotas públicas do nosso namespace nunca precisam de bloqueio
    if (str_starts_with($route, '/rh/v1/types/') || str_starts_with($route, '/rh/v1/fields/')) {
        return $result;
    }

    // Endpoints customizados com permission_callback próprio ficam fora do filtro global
    if ($route === '/rh/v1/catalog' || $route === '/rh/v1/catalog/lines') {
        return $result;
    }

    foreach (PRIVATE_POST_TYPES as $postType) {
        $touchesThisType = (
            str_contains($route, "/{$postType}") ||
            $request->get_param('post_type') === $postType ||
            $request->get_param('type') === $postType
        );

        if (!$touchesThisType) {
            continue;
        }

        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                'Autenticação necessária para acessar este conteúdo.',
                ['status' => 401]
            );
        }

        $requiredCap = security_get_required_capability($postType);

        if (!current_user_can($requiredCap)) {
            return new WP_Error(
                'rest_forbidden',
                'Você não tem permissão para acessar este conteúdo.',
                ['status' => 403]
            );
        }
    }

    return $result;
}, 10, 3);

/**
 * Garante que os CPTs privados nunca sejam expostos como "public" mesmo se
 * outro código (plugin, tema) tentar registrá-los com public => true por engano.
 * Roda depois de todos os register_post_type (prioridade alta).
 */
add_action('registered_post_type', function ($postType, $args) {
    if (!in_array($postType, PRIVATE_POST_TYPES, true)) {
        return;
    }

    if ($args->public === true) {
        // Loga um aviso visível no debug.log — sinal de configuração incorreta
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[security-hardening] CPT privado '{$postType}' foi registrado com public=true. Corrija o register_post_type().");
        }
    }
}, 999, 2);

// ─────────────────────────────────────────────────────────────
// 3. PROTEÇÃO DE QUERIES NO TEMA PHP (site público)
// ─────────────────────────────────────────────────────────────

/**
 * Impede que qualquer WP_Query do tema público (front-end, fora do admin
 * e fora de REST) acidentalmente inclua CPTs privados — cobre o caso comum
 * de 'post_type' => 'any' ou query sem post_type explícito.
 */
add_action('pre_get_posts', function (WP_Query $query) {
    if (is_admin() || defined('REST_REQUEST')) {
        return; // não interfere no wp-admin nem nas rotas REST (já tratadas acima)
    }

    if (!$query->is_main_query()) {
        return;
    }

    $requestedTypes = $query->get('post_type');

    // 'any' ou vazio = WP tentaria buscar todos os tipos públicos registrados,
    // o que poderia incluir um CPT privado se ele estiver mal configurado.
    if (empty($requestedTypes) || $requestedTypes === 'any') {
        $publicTypes = get_post_types(['public' => true]);
        $safeTypes = array_diff($publicTypes, PRIVATE_POST_TYPES);
        $query->set('post_type', $safeTypes);
        return;
    }

    // Se a query pediu explicitamente um tipo privado fora do contexto admin/REST,
    // remove esse tipo da lista (não bloqueia a query inteira, só filtra o tipo sensível).
    if (is_array($requestedTypes)) {
        $query->set('post_type', array_diff($requestedTypes, PRIVATE_POST_TYPES));
    } elseif (in_array($requestedTypes, PRIVATE_POST_TYPES, true)) {
        $query->set('post_type', '_none_'); // força resultado vazio
    }
});

// ─────────────────────────────────────────────────────────────
// 4. HARDENING GERAL DA SUPERFÍCIE PÚBLICA (afeta o site inteiro, uma vez)
// ─────────────────────────────────────────────────────────────

// Bloqueia enumeração de usuário via REST para visitantes anônimos.
// Usuários autenticados (ex: painel Angular) ainda conseguem listar autores.
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    $route = $request->get_route();
    $isUsersRoute = (
        $route === '/wp/v2/users' ||
        (str_starts_with($route, '/wp/v2/users/') && !str_ends_with($route, '/resetpassword'))
    );

    if ($isUsersRoute && !is_user_logged_in()) {
        return new WP_Error(
            'rest_forbidden',
            'Autenticação necessária.',
            ['status' => 401]
        );
    }

    return $result;
}, 10, 3);

// Desabilita os endpoints REST de post e page por padrão.
// O plugin painel-site reabilita com priority maior quando estiver ativo.
add_filter('register_post_type_args', function (array $args, string $post_type): array {
    if (in_array($post_type, ['post', 'page'], true)) {
        $args['show_in_rest'] = false;
    }
    return $args;
}, 5, 2);

// Desabilita XML-RPC (vetor clássico de brute-force/ataque automatizado)
add_filter('xmlrpc_enabled', '__return_false');

// Remove headers que vazam versão do WP (reduz fingerprinting automatizado)
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

// Bloqueia enumeração de usuário via ?author=N na URL (redireciona para home)
add_action('template_redirect', function () {
    if (is_author() && !is_user_logged_in()) {
        wp_redirect(home_url(), 301);
        exit;
    }
});

// Impede a exclusão permanente de posts protegidos, permitindo apenas o envio para a lixeira
add_filter('pre_delete_post', function ($check, $post, $force_delete) {
    $protected_types = ['lead', 'proposal', 'catalog', 'task'];
    if (in_array($post->post_type, $protected_types, true)) {
        if ($force_delete) {
            return new WP_Error(
                'rest_cannot_delete',
                'Este registro está protegido. Ele só pode ser movido para a lixeira, não excluído permanentemente.',
                ['status' => 403]
            );
        }
    }
    return $check;
}, 10, 3);

/**
 * Concede ao role administrator todas as caps customizadas dos CPTs privados.
 * Chamada pelo endpoint painel/v1/setup/capabilities via painel_grant_admin_caps().
 */
function painel_grant_admin_caps(): void
{
    $role = get_role('administrator');
    if (!$role) return;

    foreach (PRIVATE_POST_TYPES_READ_CAP as $cap) {
        $role->add_cap($cap, true);
    }
}

// Habilita suporte a lixeira para nossos Custom Post Types que são privados (não públicos)
add_filter('wp_private_posts_support_trash', function ($support, $post_type) {
    $protected_types = ['lead', 'proposal', 'catalog', 'task'];
    if (in_array($post_type, $protected_types, true)) {
        return true;
    }
    return $support;
}, 10, 2);

add_filter('is_post_type_viewable', function ($viewable, $post_type_obj) {
    $post_type = is_string($post_type_obj) ? $post_type_obj : ($post_type_obj->name ?? '');
    $protected_types = ['lead', 'proposal', 'catalog', 'task'];
    if (in_array($post_type, $protected_types, true)) {
        return true;
    }
    return $viewable;
}, 10, 2);
