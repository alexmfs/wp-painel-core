<?php
if (!defined('ABSPATH')) exit;

/**
 * Envia um email transacional a partir de um template HTML compilado.
 *
 * Templates ficam em emails/dist/{template}.html.
 * Variáveis são substituídas com a sintaxe {{ variavel }}.
 *
 * Exemplo:
 *   painel_send_email('welcome', 'fulano@email.com', 'Bem-vindo!', [
 *       'name'          => 'Fulano',
 *       'email'         => 'fulano@email.com',
 *       'temp_password' => 'abc123',
 *       'login_url'     => 'https://painel.exemplo.com',
 *       'ano'           => date('Y'),
 *   ]);
 */
function painel_send_email(string $template, string $to, string $subject, array $vars = []): bool
{
    $file = PAINEL_PLUGIN_DIR . "emails/dist/{$template}.html";

    if (!file_exists($file)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[painel/emails] Template não encontrado: {$file}");
        }
        return false;
    }

    $body = file_get_contents($file);

    // Injeta variáveis — {{ chave }} → valor escapado
    foreach ($vars as $key => $value) {
        $body = str_replace('{{ ' . $key . ' }}', esc_html((string) $value), $body);
    }

    add_filter('wp_mail_content_type', fn() => 'text/html');
    $sent = wp_mail($to, $subject, $body);
    remove_filter('wp_mail_content_type', fn() => 'text/html');

    return $sent;
}

/**
 * Atalhos para os templates existentes.
 * Adicione uma função nova a cada template .mjml que criar.
 */

function painel_email_welcome(string $to, string $name, string $tempPassword, string $loginUrl): bool
{
    return painel_send_email('welcome', $to, 'Bem-vindo ao painel!', [
        'name'          => $name,
        'email'         => $to,
        'temp_password' => $tempPassword,
        'login_url'     => $loginUrl,
        'ano'           => date('Y'),
    ]);
}

function painel_email_proposal(
    string $to,
    string $clientName,
    string $proposalTitle,
    string $validUntil,
    string $total,
    string $proposalUrl,
    string $contactName,
    string $contactEmail
): bool {
    return painel_send_email('proposal', $to, "Proposta: {$proposalTitle}", [
        'client_name'    => $clientName,
        'proposal_title' => $proposalTitle,
        'valid_until'    => $validUntil,
        'total'          => $total,
        'proposal_url'   => $proposalUrl,
        'contact_name'   => $contactName,
        'contact_email'  => $contactEmail,
        'ano'            => date('Y'),
    ]);
}

function painel_email_forgot_password(string $to, string $name, string $key): bool
{
    return painel_send_email('forgot_password', $to, 'Recuperação de Senha', [
        'name' => $name,
        'key'  => $key,
        'ano'  => date('Y'),
    ]);
}

function painel_email_otp(string $to, string $name, string $code): bool
{
    return painel_send_email('otp', $to, 'Seu código de acesso', [
        'name'      => $name,
        'code'      => $code,
        'site_name' => get_bloginfo('name'),
        'site_url'  => rtrim(getenv('FRONTEND_URL') ?: home_url(), '/'),
        'ano'       => date('Y'),
    ]);
}
