<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/captcha_challenges.php';

if (!empty($_SESSION['minerador_admin_ok'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$captchaEmoji = '';

$challenges = minerador_login_captcha_challenges();
if ($challenges === []) {
    http_response_code(500);
    exit('Captcha não configurado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $pick = $challenges[random_int(0, count($challenges) - 1)];
    $captchaEmoji = (string) ($pick['emoji'] ?? '?');
    $normList = [];
    foreach ($pick['answers'] ?? [] as $a) {
        $normList[] = minerador_normalize_answer((string) $a);
    }
    $_SESSION['minerador_login_captcha'] = array_values(array_unique(array_filter($normList)));
} else {
    $user = trim((string) ($_POST['user'] ?? ''));
    $pass = (string) ($_POST['pass'] ?? '');
    $captchaIn = minerador_normalize_answer((string) ($_POST['captcha'] ?? ''));
    $allowed = $_SESSION['minerador_login_captcha'] ?? null;
    unset($_SESSION['minerador_login_captcha']);

    if (!is_array($allowed) || $allowed === []) {
        $error = 'Sessão do captcha expirou. Recarregue a página.';
    } elseif ($captchaIn === '' || !in_array($captchaIn, $allowed, true)) {
        $error = 'Captcha ou credenciais inválidos.';
    } elseif ($user === '' || $pass === '') {
        $error = 'Captcha ou credenciais inválidos.';
    } else {
        $cfg = minerador_config();
        $cfgAdminUser = (string) ($cfg['admin_user'] ?? '');

        if ($cfgAdminUser !== '' && $cfgAdminUser === $user) {
            if (minerador_admin_verify_config($user, $pass)) {
                session_regenerate_id(true);
                $_SESSION['minerador_admin_ok'] = true;
                $_SESSION['minerador_is_config_admin'] = true;
                $_SESSION['minerador_user_id'] = null;
                $_SESSION['minerador_username'] = $user;
                header('Location: index.php');
                exit;
            }
            $error = 'Captcha ou credenciais inválidos.';
        } else {
            try {
                $pdo = minerador_pdo();
                $uid = minerador_admin_verify_delegated($pdo, $user, $pass);
                if ($uid !== null) {
                    session_regenerate_id(true);
                    $_SESSION['minerador_admin_ok'] = true;
                    $_SESSION['minerador_is_config_admin'] = false;
                    $_SESSION['minerador_user_id'] = $uid;
                    $_SESSION['minerador_username'] = $user;
                    header('Location: index.php');
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'Erro ao validar utilizador. Confirme se a base de dados está migrada.';
            }
            $error = $error !== '' ? $error : 'Captcha ou credenciais inválidos.';
        }
    }

    $pick = $challenges[random_int(0, count($challenges) - 1)];
    $captchaEmoji = (string) ($pick['emoji'] ?? '?');
    $normList = [];
    foreach ($pick['answers'] ?? [] as $a) {
        $normList[] = minerador_normalize_answer((string) $a);
    }
    $_SESSION['minerador_login_captcha'] = array_values(array_unique(array_filter($normList)));
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login — Minerador.pt</title>
  <style>
    body { font-family: system-ui, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .card { background:#1e293b; padding:28px 26px; border-radius:12px; width:100%; max-width:400px; box-shadow:0 10px 40px rgba(0,0,0,.35); }
    h1 { margin:0 0 16px; font-size:20px; }
    label { display:block; margin-top:12px; font-size:13px; color:#94a3b8; }
    input { width:100%; box-sizing:border-box; margin-top:6px; padding:10px 12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:#f8fafc; }
    button { margin-top:18px; width:100%; padding:11px; border:none; border-radius:8px; background:#2563eb; color:#fff; font-weight:600; cursor:pointer; }
    .err { margin-top:12px; color:#fecaca; font-size:13px; }
    .hint { margin-top:14px; font-size:12px; color:#64748b; line-height:1.4; }
    .captcha-box { margin-top:14px; padding:14px; background:#0f172a; border-radius:10px; border:1px solid #334155; text-align:center; }
    .captcha-emoji { font-size:48px; line-height:1.2; }
    .captcha-q { font-size:13px; color:#94a3b8; margin-top:8px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Minerador.pt</h1>
    <form method="post" autocomplete="off">
      <label>Usuário</label>
      <input name="user" required autocomplete="username" />
      <label>Senha</label>
      <input name="pass" type="password" required autocomplete="current-password" />
      <div class="captcha-box">
        <div class="captcha-emoji" aria-hidden="true"><?= h($captchaEmoji) ?></div>
        <p class="captcha-q">Em português, o que é este ícone? (uma palavra)</p>
        <label for="captcha">Resposta</label>
        <input id="captcha" name="captcha" required autocomplete="off" placeholder="Ex.: cachorro" />
      </div>
      <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
      <button type="submit">Entrar</button>
    </form>
    <p class="hint">Administrador: credenciais em <code>minerador/config.php</code> (<code>admin_user</code> / <code>admin_pass_hash</code>). Utilizadores delegados são criados pelo admin em <strong>Users</strong>.</p>
  </div>
</body>
</html>
