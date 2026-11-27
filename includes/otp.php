<?php
if (!defined('ABSPATH')) exit;

// ── Registro de rotas ─────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('rh/v1', '/otp/request', [
        'methods'             => 'POST',
        'callback'            => 'painel_otp_request',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('rh/v1', '/otp/verify', [
        'methods'             => 'POST',
        'callback'            => 'painel_otp_verify',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('rh/v1', '/first-access', [
        'methods'             => 'POST',
        'callback'            => 'painel_first_access_complete',
        'permission_callback' => 'is_user_logged_in',
    ]);
});

// ── Auto-set first_access para novos subscribers ──────────────────────────────

add_action('user_register', function (int $user_id): void {
    $user = get_user_by('id', $user_id);
    if ($user && in_array('subscriber', (array) $user->roles, true)) {
        update_user_meta($user_id, 'rhub_first_access', '1');
    }
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function painel_auth_mode(): string {
    $mode = getenv('AUTH_MODE') ?: 'password';
    return in_array($mode, ['password', 'password_otp', 'otp'], true) ? $mode : 'password';
}

function painel_otp_generate(): string {
    $dev_code = getenv('OTP_DEV_CODE');
    if ($dev_code && defined('WP_ENV') && WP_ENV !== 'production') {
        return (string) $dev_code;
    }
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function painel_otp_send(WP_User $user, string $code): bool {
    return painel_email_otp($user->user_email, $user->display_name ?: $user->user_email, $code);
}

// ── Endpoints ─────────────────────────────────────────────────────────────────

function painel_otp_request(WP_REST_Request $r): WP_REST_Response|WP_Error {
    $mode  = painel_auth_mode();
    $login = sanitize_text_field($r['login'] ?? '');

    if (empty($login)) {
        return new WP_Error('missing_login', 'Campo login é obrigatório.', ['status' => 422]);
    }

    // Resolve usuário
    if ($mode === 'password_otp') {
        $password = $r['password'] ?? '';
        if (empty($password)) {
            return new WP_Error('missing_password', 'Campo password é obrigatório.', ['status' => 422]);
        }
        $user = wp_authenticate($login, $password);
        if (is_wp_error($user)) {
            return new WP_Error('invalid_credentials', 'Credenciais inválidas.', ['status' => 401]);
        }
    } else {
        // modo otp: busca por login ou email; cria subscriber se não existir
        $user = get_user_by('login', $login) ?: get_user_by('email', $login);
        if (!$user) {
            if (!is_email($login)) {
                return new WP_Error('invalid_email', 'Endereço de e-mail inválido.', ['status' => 422]);
            }
            $user_id = wp_insert_user([
                'user_login' => $login,
                'user_email' => $login,
                'role'       => 'subscriber',
                'user_pass'  => wp_generate_password(32, true, true),
            ]);
            if (is_wp_error($user_id)) {
                return new WP_Error('register_failed', 'Não foi possível criar a conta.', ['status' => 500]);
            }
            update_user_meta($user_id, 'rhub_first_access', '1');
            $user = get_user_by('id', $user_id);
            do_action('painel_otp_new_user_registered', $user);
        }
    }

    $code    = painel_otp_generate();
    $session = bin2hex(random_bytes(32));

    set_transient('rhub_otp_' . $session, [
        'user_id' => $user->ID,
        'code'    => $code,
    ], 600);

    painel_otp_send($user, $code);

    // Mascara o email: alex@gmail.com → a***@gmail.com
    $email_parts = explode('@', $user->user_email);
    $masked = substr($email_parts[0], 0, 1) . '***@' . ($email_parts[1] ?? '');

    return new WP_REST_Response([
        'otp_session' => $session,
        'expires_in'  => 600,
        'email'       => $masked,
        'message'     => 'Código enviado para o e-mail cadastrado.',
    ], 200);
}

function painel_otp_verify(WP_REST_Request $r): WP_REST_Response|WP_Error {
    $session = sanitize_text_field($r['otp_session'] ?? '');
    $code    = sanitize_text_field($r['code'] ?? '');

    if (empty($session) || empty($code)) {
        return new WP_Error('missing_fields', 'otp_session e code são obrigatórios.', ['status' => 422]);
    }

    $data = get_transient('rhub_otp_' . $session);

    if (!$data || !isset($data['code'], $data['user_id'])) {
        return new WP_Error('otp_expired', 'Código expirado ou inválido.', ['status' => 401]);
    }

    if (!hash_equals($data['code'], $code)) {
        return new WP_Error('otp_invalid', 'Código incorreto.', ['status' => 401]);
    }

    delete_transient('rhub_otp_' . $session);

    $user = get_user_by('id', (int) $data['user_id']);
    if (!$user) {
        return new WP_Error('user_not_found', 'Usuário não encontrado.', ['status' => 404]);
    }

    $auth         = new JWTAuth\Auth();
    $token_result = $auth->generate_token($user, false);

    if (is_wp_error($token_result)) {
        return $token_result;
    }

    $token_data                 = is_array($token_result) ? $token_result : (array) $token_result;
    $token_data['first_access'] = (bool) get_user_meta($user->ID, 'rhub_first_access', true);
    $token_data['roles']        = array_values((array) $user->roles);

    return new WP_REST_Response($token_data, 200);
}

function painel_first_access_complete(WP_REST_Request $r): WP_REST_Response|WP_Error {
    $user_id    = get_current_user_id();
    $name       = sanitize_text_field($r['name'] ?? '');
    $company    = sanitize_text_field($r['company'] ?? '');
    $cnpj       = sanitize_text_field($r['cnpj'] ?? '');
    $cargo      = sanitize_text_field($r['cargo'] ?? '');
    $department = sanitize_text_field($r['department'] ?? '');
    $city       = sanitize_text_field($r['city'] ?? '');
    $country    = sanitize_text_field($r['country'] ?? '');
    $phone      = sanitize_text_field($r['phone'] ?? '');
    $phone_ddi  = sanitize_text_field($r['phone_ddi'] ?? '');

    if ($name) {
        wp_update_user(['ID' => $user_id, 'display_name' => $name]);
    }

    foreach (compact('company', 'cnpj', 'cargo', 'department', 'city', 'country', 'phone', 'phone_ddi') as $key => $value) {
        if ($value !== '') {
            update_user_meta($user_id, $key, $value);
        }
    }

    delete_user_meta($user_id, 'rhub_first_access');

    return new WP_REST_Response(['success' => true], 200);
}
