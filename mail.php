<?php
if (!defined('ABSPATH')) exit;

add_filter('wp_mail_from', function ($email) {
    return getenv('MAIL_FROM_ADDRESS') ?: $email;
});

add_filter('wp_mail_from_name', function ($name) {
    return getenv('MAIL_FROM_NAME') ?: $name;
});

add_action('phpmailer_init', function (PHPMailer\PHPMailer\PHPMailer $mailer): void {
    $mailer->isSMTP();
    $mailer->Host       = getenv('MAIL_HOST')     ?: 'sandbox.smtp.mailtrap.io';
    $mailer->Port       = (int) (getenv('MAIL_PORT') ?: 2525);
    $mailer->SMTPAuth   = true;
    $mailer->Username   = getenv('MAIL_USERNAME')  ?: '';
    $mailer->Password   = getenv('MAIL_PASSWORD')  ?: '';
    $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

    $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: get_option('admin_email');
    $fromName    = getenv('MAIL_FROM_NAME')    ?: get_bloginfo('name');

    $mailer->setFrom($fromAddress, $fromName);
});
