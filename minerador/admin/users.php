<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_config_admin();

$pdo = minerador_pdo();
$csrf = minerador_csrf_token();

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM minerador_users WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $editRow = $st->fetch() ?: null;
}

$list = $pdo->query('SELECT id, username, minerador_token, is_active, created_at FROM minerador_users ORDER BY id ASC')->fetchAll();

$flash = '';
if (isset($_GET['saved'])) {
    $flash = 'Utilizador atualizado.';
} elseif (isset($_GET['deleted'])) {
    $flash = 'Utilizador excluído.';
} elseif (isset($_GET['created'])) {
    $flash = 'Utilizador criado. Copie o token abaixo para a extensão.';
} elseif (isset($_GET['del_err']) && $_GET['del_err'] === 'searches') {
    $flash = 'Não é possível excluir: existem buscas associadas a este utilizador.';
} elseif (isset($_GET['err'])) {
    $flash = 'Operação falhou (dados inválidos ou conflito).';
}

$newToken = isset($_GET['new_token']) ? (string) $_GET['new_token'] : '';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Users — Minerador.pt</title>
  <style>
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    a { color:#93c5fd; }
    <?= minerador_admin_header_css() ?>
    main { padding:18px 20px 40px; max-width:960px; margin:0 auto; }
    .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .btn.secondary { background:#374151; }
    .btn.danger { background:#7f1d1d; }
    table { width:100%; border-collapse:collapse; font-size:13px; background:#111827; border:1px solid #1f2937; border-radius:10px; overflow:hidden; }
    th, td { padding:10px 8px; border-bottom:1px solid #1f2937; text-align:left; }
    th { background:#0f172a; color:#9ca3af; font-size:12px; }
    tr:last-child td { border-bottom:none; }
    .flash { background:#065f46; color:#d1fae5; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
    .flash.warn { background:#78350f; color:#ffedd5; }
    .box { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:16px; margin-bottom:18px; }
    label { display:block; font-size:12px; color:#9ca3af; margin-top:10px; }
    input, select { width:100%; max-width:400px; padding:8px 10px; margin-top:4px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; font:inherit; box-sizing:border-box; }
    .token { font-family:ui-monospace,monospace; font-size:12px; word-break:break-all; }
    form.inline { display:inline; }
    h2 { font-size:16px; margin:22px 0 8px; }
  </style>
</head>
<body>
  <?php minerador_admin_render_page_header('Users', []); ?>
  <main>
    <?php if ($flash !== ''): ?>
      <div class="flash<?= (isset($_GET['del_err']) || isset($_GET['err'])) ? ' warn' : '' ?>"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($newToken !== ''): ?>
      <div class="flash">Novo token (copie agora): <span class="token"><?= h($newToken) ?></span></div>
    <?php endif; ?>

    <div class="box">
      <h2 style="margin-top:0;">Criar utilizador</h2>
      <form method="post" action="user_save.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <input type="hidden" name="action" value="create" />
        <label>Nome de utilizador</label>
        <input name="username" required maxlength="64" autocomplete="off" />
        <label>Senha (mín. 6 caracteres)</label>
        <input name="password" type="password" required minlength="6" autocomplete="new-password" />
        <div style="margin-top:14px;"><button type="submit" class="btn">Criar</button></div>
      </form>
    </div>

    <?php if ($editRow): ?>
      <div class="box">
        <h2 style="margin-top:0;">Editar #<?= h((string) (int) $editRow['id']) ?> — <?= h((string) $editRow['username']) ?></h2>
        <form method="post" action="user_save.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="action" value="update" />
          <input type="hidden" name="id" value="<?= h((string) (int) $editRow['id']) ?>" />
          <label>Nome de utilizador</label>
          <input name="username" required maxlength="64" value="<?= h((string) $editRow['username']) ?>" />
          <label>Nova senha (vazio = manter)</label>
          <input name="password" type="password" minlength="6" autocomplete="new-password" />
          <label>Ativo</label>
          <select name="is_active">
            <option value="1" <?= (int) $editRow['is_active'] === 1 ? 'selected' : '' ?>>sim</option>
            <option value="0" <?= (int) $editRow['is_active'] !== 1 ? 'selected' : '' ?>>não</option>
          </select>
          <div style="margin-top:14px;"><button type="submit" class="btn">Guardar</button></div>
        </form>
        <form method="post" action="user_save.php" style="margin-top:12px;" onsubmit="return confirm('Gerar novo token? A extensão deixará de aceitar o token antigo.');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="action" value="regenerate_token" />
          <input type="hidden" name="id" value="<?= h((string) (int) $editRow['id']) ?>" />
          <button type="submit" class="btn secondary">Regenerar minerador_token</button>
        </form>
        <p style="color:#9ca3af;font-size:12px;margin-top:12px;">Token atual: <span class="token"><?= h((string) $editRow['minerador_token']) ?></span></p>
        <a class="btn secondary" href="users.php">Fechar edição</a>
      </div>
    <?php endif; ?>

    <h2>Lista</h2>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Utilizador</th>
          <th>Token (extensão)</th>
          <th>Ativo</th>
          <th>Criado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $u): ?>
          <tr>
            <td><?= h((string) (int) $u['id']) ?></td>
            <td><?= h((string) $u['username']) ?></td>
            <td class="token"><?= h((string) $u['minerador_token']) ?></td>
            <td><?= (int) $u['is_active'] === 1 ? 'sim' : 'não' ?></td>
            <td><?= h((string) $u['created_at']) ?></td>
            <td>
              <a class="btn secondary" href="users.php?edit=<?= h((string) (int) $u['id']) ?>">Editar</a>
              <form class="inline" method="post" action="user_delete.php" onsubmit="return confirm('Excluir este utilizador?');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                <input type="hidden" name="id" value="<?= h((string) (int) $u['id']) ?>" />
                <button type="submit" class="btn danger">Excluir</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($list === []): ?>
          <tr><td colspan="6">Nenhum utilizador delegado. O admin continua definido em <code>config.php</code>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <p style="color:#9ca3af;font-size:13px;margin-top:16px;">O token do administrador <strong>não</strong> aparece aqui: use <code>minerador_token</code> em <code>config.php</code> na extensão do admin.</p>
  </main>
</body>
</html>
