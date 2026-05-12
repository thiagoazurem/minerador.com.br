<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

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
$del = $pdo->prepare('DELETE FROM minerador_leads WHERE id = ?');
$del->execute([$id]);

$backSearch = (int) ($_POST['back_search_id'] ?? 0);
$target = 'index.php?deleted=1';
if ($backSearch > 0) {
    $target = 'index.php?search_id=' . $backSearch . '&deleted=1';
}
header('Location: ' . $target);
exit;
