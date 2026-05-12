<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (!empty($_SESSION['minerador_admin_ok'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? ''));
    $pass = (string) ($_POST['pass'] ?? '');
    if ($user !== '' && $pass !== '' && minerador_admin_verify($user, $pass)) {
        session_regenerate_id(true);
        $_SESSION['minerador_admin_ok'] = true;
        $_SESSION['minerador_admin_user'] = $user;
        header('Location: index.php');
        exit;
    }
    $error = 'Usuário ou senha inválidos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login — Minerador Admin</title>
  <style>
    body { font-family: system-ui, sans-serif; background:#0f172a; color:#e2e8f0; margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; }
    .card { background:#1e293b; padding:28px 26px; border-radius:12px; width:100%; max-width:380px; box-shadow:0 10px 40px rgba(0,0,0,.35); }
    h1 { margin:0 0 16px; font-size:20px; }
    label { display:block; margin-top:12px; font-size:13px; color:#94a3b8; }
    input { width:100%; margin-top:6px; padding:10px 12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:#f8fafc; }
    button { margin-top:18px; width:100%; padding:11px; border:none; border-radius:8px; background:#2563eb; color:#fff; font-weight:600; cursor:pointer; }
    .err { margin-top:12px; color:#fecaca; font-size:13px; }
    .hint { margin-top:14px; font-size:12px; color:#64748b; line-height:1.4; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Minerador — Admin</h1>
    <form method="post" autocomplete="off">
      <label>Usuário</label>
      <input name="user" required />
      <label>Senha</label>
      <input name="pass" type="password" required />
      <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
      <button type="submit">Entrar</button>
    </form>
    <p class="hint">Defina <code>admin_user</code> e <code>admin_pass_hash</code> em <code>minerador/config.php</code>.</p>
  </div>
</body>
</html>
