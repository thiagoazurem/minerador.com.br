<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_config_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
minerador_csrf_check();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID inválido');
}

$pdo = minerador_pdo();
$stc = $pdo->prepare('SELECT COUNT(*) FROM minerador_searches WHERE owner_user_id = ?');
$stc->execute([$id]);
$c = (int) $stc->fetchColumn();
if ($c > 0) {
    header('Location: users.php?del_err=searches');
    exit;
}

$del = $pdo->prepare('DELETE FROM minerador_users WHERE id = ?');
$del->execute([$id]);
header('Location: users.php?deleted=1');
exit;
