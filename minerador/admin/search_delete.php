<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
minerador_csrf_check();

$searchId = (int) ($_POST['search_id'] ?? 0);
if ($searchId <= 0) {
    http_response_code(400);
    exit('search_id inválido');
}

$scopeRedirect = (string) ($_POST['redirect_scope'] ?? '') === 'mine' ? 'mine' : 'all';
$scopeForCheck = $scopeRedirect;

$pdo = minerador_pdo();
$st = $pdo->prepare('SELECT * FROM minerador_searches WHERE id = ? LIMIT 1');
$st->execute([$searchId]);
$row = $st->fetch();
if (!$row || !minerador_admin_can_manage_search($row, $scopeForCheck)) {
    http_response_code(403);
    exit('Sem permissão para excluir esta busca.');
}

$pdo->beginTransaction();
try {
    $del1 = $pdo->prepare('DELETE FROM minerador_leads WHERE search_id = ?');
    $del1->execute([$searchId]);
    $del2 = $pdo->prepare('DELETE FROM minerador_searches WHERE id = ?');
    $del2->execute([$searchId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    exit('Erro ao excluir busca: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$redir = 'searches.php?deleted=1';
if ($scopeRedirect === 'mine') {
    $redir .= '&scope=mine';
}
header('Location: ' . $redir);
exit;
