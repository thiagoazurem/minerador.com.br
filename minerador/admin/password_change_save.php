<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

minerador_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

minerador_csrf_check();

function minerador_password_change_redirect(array $query): void
{
    $q = http_build_query($query);
    header('Location: settings.php' . ($q !== '' ? '?' . $q : ''));
    exit;
}

if (minerador_admin_is_config_admin()) {
    $q = ['err' => 'pwd_config'];
    if ((string) ($_POST['scope'] ?? '') === 'mine') {
        $q['scope'] = 'mine';
    }
    minerador_password_change_redirect($q);
}

$uid = minerador_admin_delegated_user_id();
if ($uid === null || $uid <= 0) {
    http_response_code(403);
    exit('Sem permissão');
}

$p1 = (string) ($_POST['password_new'] ?? '');
$p2 = (string) ($_POST['password_confirm'] ?? '');

if ($p1 !== $p2) {
    minerador_password_change_redirect(['err' => 'pwd_mismatch']);
}

if (strlen($p1) < 6) {
    minerador_password_change_redirect(['err' => 'pwd_short']);
}

$hash = password_hash($p1, PASSWORD_DEFAULT);
$pdo = minerador_pdo();
$up = $pdo->prepare('UPDATE minerador_users SET password_hash = ? WHERE id = ? LIMIT 1');
$up->execute([$hash, $uid]);

minerador_password_change_redirect(['saved' => 'password']);
