<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
minerador_csrf_check();
minerador_admin_require_config_admin();

$searchId = (int) ($_POST['search_id'] ?? 0);
if ($searchId <= 0 || !array_key_exists('target_user_id', $_POST)) {
    http_response_code(400);
    exit('Dados inválidos.');
}
$targetUserId = (int) $_POST['target_user_id'];
if ($targetUserId < 0) {
    http_response_code(400);
    exit('Dados inválidos.');
}

$scopeRedirect = (string) ($_POST['redirect_scope'] ?? '') === 'mine' ? 'mine' : 'all';
$scopeForCheck = $scopeRedirect;

function minerador_search_transfer_redirect(string $query): void
{
    header('Location: searches.php?' . $query);
    exit;
}

$scopeQ = $scopeRedirect === 'mine' ? '&scope=mine' : '';

$pdo = minerador_pdo();

if ($targetUserId > 0) {
    $stU = $pdo->prepare('SELECT id FROM minerador_users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stU->execute([$targetUserId]);
    if ($stU->fetchColumn() === false) {
        minerador_search_transfer_redirect('err=transfer_user' . $scopeQ);
    }
}

$st = $pdo->prepare('SELECT * FROM minerador_searches WHERE id = ? LIMIT 1');
$st->execute([$searchId]);
$row = $st->fetch();
if (!$row || !minerador_admin_can_manage_search($row, $scopeForCheck)) {
    http_response_code(403);
    exit('Sem permissão para transferir esta busca.');
}

$slug = (string) ($row['slug'] ?? '');
$curKey = (string) ($row['owner_key'] ?? '');
$curUid = isset($row['owner_user_id']) && $row['owner_user_id'] !== null && $row['owner_user_id'] !== ''
    ? (int) $row['owner_user_id']
    : 0;

if ($targetUserId === 0) {
    $newOwnerKey = 'cfg';
    $newOwnerUserId = null;
    if ($curKey === 'cfg' && $curUid === 0) {
        minerador_search_transfer_redirect('err=transfer_same' . $scopeQ);
    }
} else {
    $newOwnerKey = 'u:' . $targetUserId;
    $newOwnerUserId = $targetUserId;
    if ($curKey === $newOwnerKey && $curUid === $targetUserId) {
        minerador_search_transfer_redirect('err=transfer_same' . $scopeQ);
    }
}

$dup = $pdo->prepare(
    'SELECT id FROM minerador_searches WHERE owner_key = ? AND slug = ? AND id <> ? LIMIT 1'
);
$dup->execute([$newOwnerKey, $slug, $searchId]);
if ($dup->fetch()) {
    minerador_search_transfer_redirect('err=transfer_slug' . $scopeQ);
}

try {
    $up = $pdo->prepare(
        'UPDATE minerador_searches SET owner_key = ?, owner_user_id = ? WHERE id = ? LIMIT 1'
    );
    $up->execute([$newOwnerKey, $newOwnerUserId, $searchId]);
} catch (Throwable $e) {
    error_log(sprintf(
        '[minerador search_transfer_save] %s @ %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    minerador_search_transfer_redirect('err=transfer_db' . $scopeQ);
}

minerador_search_transfer_redirect('saved=transfer' . $scopeQ);
