<?php

declare(strict_types=1);



require_once __DIR__ . '/_bootstrap.php';

minerador_admin_require_login();



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    exit('Method not allowed');

}

minerador_csrf_check();



$pdo = minerador_pdo();

$action = (string) ($_POST['action'] ?? '');

$scopeQ = (string) ($_POST['scope'] ?? '') === 'mine' ? '&scope=mine' : '';



function minerador_settings_redirect(string $query): void

{

    header('Location: settings.php?' . $query);

    exit;

}



if ($action === 'save_qualificacao_substrings') {

    minerador_admin_require_config_admin();

    $raw = (string) ($_POST['qualificacao_substrings'] ?? '');

    $lines = preg_split('/\R/u', $raw) ?: [];

    $rules = [];

    foreach ($lines as $line) {

        $line = trim((string) $line);

        if ($line === '' || (isset($line[0]) && $line[0] === '#')) {

            continue;

        }

        if (strpos($line, '=') === false) {

            continue;

        }

        $parts = explode('=', $line, 2);

        $left = trim((string) ($parts[0] ?? ''));

        $right = trim((string) ($parts[1] ?? ''));

        if (strlen($left) >= 2 && (($left[0] === '"' && substr($left, -1) === '"') || ($left[0] === "'" && substr($left, -1) === "'"))) {

            $left = substr($left, 1, -1);

        }

        $substr = mb_substr($left, 0, 512, 'UTF-8');

        $nivel = minerador_normalize_qualificacao_nivel($right);

        if ($nivel === null) {

            continue;

        }

        $rules[] = ['substr' => $substr, 'nivel' => $nivel];

    }

    $json = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {

        minerador_settings_redirect('err=strings_json' . $scopeQ);

    }

    minerador_settings_set($pdo, MINERADOR_SETTING_QUALIFICACAO_SUBSTRINGS, $json);

    minerador_settings_redirect('saved=strings' . $scopeQ);

}



if ($action === 'save_leads_ignore_terms') {

    minerador_admin_require_config_admin();

    $raw = (string) ($_POST['leads_ignore_terms'] ?? '');

    $raw = mb_substr($raw, 0, 65535, 'UTF-8');

    minerador_settings_set($pdo, MINERADOR_SETTING_LEADS_IGNORE_TERMS, $raw);

    minerador_settings_redirect('saved=ignore_terms' . $scopeQ);

}



if ($action === 'regenerate_config_token') {

    minerador_admin_require_config_admin();

    $newToken = minerador_new_user_token('');

    $wr = minerador_config_write_minerador_token($newToken);

    if (!$wr['ok']) {

        minerador_settings_redirect('err=' . rawurlencode((string) ($wr['error'] ?? 'config_write')) . $scopeQ);

    }

    minerador_settings_redirect('saved=config_token&new_token=' . rawurlencode($newToken) . $scopeQ);

}



if ($action === 'regenerate_delegated_token') {

    $uid = minerador_admin_delegated_user_id();

    if ($uid === null) {

        http_response_code(403);

        exit('Apenas utilizadores delegados podem regenerar este token aqui.');

    }

    $cfgTok = (string) (minerador_config()['minerador_token'] ?? '');

    for ($attempt = 0; $attempt < 40; $attempt++) {

        $newToken = minerador_new_user_token($cfgTok);

        $dup = $pdo->prepare('SELECT id FROM minerador_users WHERE minerador_token = ? AND id <> ? LIMIT 1');

        $dup->execute([$newToken, $uid]);

        if ($dup->fetch()) {

            continue;

        }

        $up = $pdo->prepare('UPDATE minerador_users SET minerador_token = ? WHERE id = ?');

        $up->execute([$newToken, $uid]);

        minerador_settings_redirect('saved=user_token&new_token=' . rawurlencode($newToken));

    }

    minerador_settings_redirect('err=token_retry');

}



if ($action === 'wipe_all_leads_data') {

    minerador_admin_require_config_admin();

    $pass = (string) ($_POST['wipe_admin_password'] ?? '');

    if (trim($pass) === '') {

        minerador_settings_redirect('err=wipe_pwd' . $scopeQ);

    }

    $cfg = minerador_config();

    $adminUser = (string) ($cfg['admin_user'] ?? '');

    if ($adminUser === '' || !minerador_admin_verify_config($adminUser, $pass)) {

        minerador_settings_redirect('err=wipe_pwd' . $scopeQ);

    }

    try {

        $pdo->beginTransaction();

        $pdo->exec('DELETE FROM minerador_gallery');

        $pdo->exec('DELETE FROM minerador_leads');

        $pdo->exec('DELETE FROM minerador_searches');

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        error_log(sprintf('[minerador settings_save wipe] %s @ %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));

        minerador_settings_redirect('err=wipe_db' . $scopeQ);

    }

    minerador_settings_redirect('saved=wipe' . $scopeQ);

}



minerador_settings_redirect('err=action' . $scopeQ);

