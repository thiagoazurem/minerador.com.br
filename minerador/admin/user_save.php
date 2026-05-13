<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_config_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
minerador_csrf_check();

$pdo = minerador_pdo();
$cfgTok = (string) (minerador_config()['minerador_token'] ?? '');

$action = (string) ($_POST['action'] ?? '');

if ($action === 'create') {
    $user = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 64, 'UTF-8');
    $pass = (string) ($_POST['password'] ?? '');
    if ($user === '' || strlen($pass) < 6) {
        header('Location: users.php?err=1');
        exit;
    }
    $token = minerador_new_user_token($cfgTok);
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    try {
        $ins = $pdo->prepare(
            'INSERT INTO minerador_users (username, password_hash, minerador_token, is_active) VALUES (?,?,?,1)'
        );
        $ins->execute([$user, $hash, $token]);
        $newId = (int) $pdo->lastInsertId();
        header('Location: users.php?created=1&new_token=' . rawurlencode($token) . '&new_user=' . rawurlencode($user) . '&new_id=' . $newId);
        exit;
    } catch (Throwable $e) {
        header('Location: users.php?err=2');
        exit;
    }
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        header('Location: users.php?err=3');
        exit;
    }
    $user = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 64, 'UTF-8');
    $pass = (string) ($_POST['password'] ?? '');
    $active = ((string) ($_POST['is_active'] ?? '1')) === '1' ? 1 : 0;
    if ($user === '') {
        header('Location: users.php?edit=' . $id . '&err=1');
        exit;
    }
    try {
        if ($pass !== '') {
            if (strlen($pass) < 6) {
                header('Location: users.php?edit=' . $id . '&err=4');
                exit;
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $up = $pdo->prepare(
                'UPDATE minerador_users SET username = ?, password_hash = ?, is_active = ? WHERE id = ?'
            );
            $up->execute([$user, $hash, $active, $id]);
        } else {
            $up = $pdo->prepare('UPDATE minerador_users SET username = ?, is_active = ? WHERE id = ?');
            $up->execute([$user, $active, $id]);
        }
        header('Location: users.php?saved=1');
        exit;
    } catch (Throwable $e) {
        header('Location: users.php?edit=' . $id . '&err=2');
        exit;
    }
}

if ($action === 'regenerate_token') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        header('Location: users.php?err=3');
        exit;
    }
    $token = minerador_new_user_token($cfgTok);
    $up = $pdo->prepare('UPDATE minerador_users SET minerador_token = ? WHERE id = ?');
    $up->execute([$token, $id]);
    header('Location: users.php?new_token=' . rawurlencode($token) . '&token_user_id=' . $id);
    exit;
}

header('Location: users.php?err=5');
exit;
