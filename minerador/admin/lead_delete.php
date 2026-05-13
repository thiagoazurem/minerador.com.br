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
$chk = $pdo->prepare(
    'SELECT l.search_id, s.owner_key, s.owner_user_id FROM minerador_leads l ' .
    'LEFT JOIN minerador_searches s ON s.id = l.search_id WHERE l.id = ? LIMIT 1'
);
$chk->execute([$id]);
$meta = $chk->fetch();
if (!$meta) {
    http_response_code(404);
    exit('Lead não encontrado');
}
$sid = (int) ($meta['search_id'] ?? 0);
$scopePost = (string) ($_POST['scope'] ?? '') === 'mine' ? 'mine' : null;
if ($sid <= 0) {
    if (!minerador_admin_is_config_admin() || $scopePost === 'mine') {
        http_response_code(403);
        exit('Sem permissão');
    }
} else {
    $smini = [
        'owner_key' => (string) ($meta['owner_key'] ?? ''),
        'owner_user_id' => isset($meta['owner_user_id']) ? (int) $meta['owner_user_id'] : null,
    ];
    if (!minerador_admin_can_manage_search($smini, $scopePost)) {
        http_response_code(403);
        exit('Sem permissão');
    }
}

$del = $pdo->prepare('DELETE FROM minerador_leads WHERE id = ?');
$del->execute([$id]);

$backSearch = (int) ($_POST['back_search_id'] ?? 0);
$target = 'leads.php?deleted=1';
if ($backSearch > 0) {
    $target = 'leads.php?search_id=' . $backSearch . '&deleted=1';
}
if ($scopePost === 'mine') {
    $target .= (str_contains($target, '?') ? '&' : '?') . 'scope=mine';
}
header('Location: ' . $target);
exit;
