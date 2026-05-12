<?php
declare(strict_types=1);

session_start();

header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require_once dirname(__DIR__) . '/bootstrap_db.php';

function minerador_admin_verify(string $user, string $pass): bool
{
    $c = minerador_config();
    if (($c['admin_user'] ?? '') !== $user) {
        return false;
    }
    $hash = (string) ($c['admin_pass_hash'] ?? '');
    if ($hash === '') {
        return false;
    }
    return password_verify($pass, $hash);
}

function minerador_admin_require_login(): void
{
    if (!empty($_SESSION['minerador_admin_ok'])) {
        return;
    }
    header('Location: login.php');
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function minerador_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function minerador_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $stored = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $stored === '' || !hash_equals($stored, $sent)) {
        http_response_code(400);
        exit('CSRF inválido');
    }
}
