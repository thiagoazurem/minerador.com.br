<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

$pdo = minerador_pdo();

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$off = ($page - 1) * $per;

$totalRows = (int) $pdo->query('SELECT COUNT(*) FROM minerador_searches')->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $per));
if ($page > $totalPages) {
    $page = $totalPages;
    $off = ($page - 1) * $per;
}

$sql =
    'SELECT s.id, s.slug, s.keyword, s.localizacao, s.query_text, s.created_at, ' .
    '(SELECT COUNT(*) FROM minerador_leads l WHERE l.search_id = s.id) AS total_leads ' .
    'FROM minerador_searches s ORDER BY s.created_at DESC LIMIT ' . $per . ' OFFSET ' . (int) $off;
$rows = $pdo->query($sql)->fetchAll();

$csrf = minerador_csrf_token();

$flash = '';
if (isset($_GET['deleted'])) {
    $flash = 'Busca excluída.';
} elseif (isset($_GET['saved'])) {
    $flash = 'Alterações salvas.';
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Buscas — Minerador</title>
  <style>
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    a { color:#93c5fd; }
    header { padding:16px 20px; background:#111827; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
    header h1 { margin:0; font-size:18px; }
    main { padding:18px 20px 40px; max-width:1200px; margin:0 auto; }
    .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .btn.secondary { background:#374151; }
    .btn.danger { background:#7f1d1d; }
    table { width:100%; border-collapse:collapse; font-size:13px; background:#111827; border:1px solid #1f2937; border-radius:10px; overflow:hidden; }
    th, td { padding:10px 8px; border-bottom:1px solid #1f2937; text-align:left; vertical-align:top; }
    th { background:#0f172a; font-size:12px; color:#9ca3af; white-space:nowrap; }
    tr:last-child td { border-bottom:none; }
    .pager { margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .muted { color:#9ca3af; font-size:12px; }
    .nowrap { white-space:nowrap; }
    .flash { background:#065f46; color:#d1fae5; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
    form.inline { display:inline; }
  </style>
</head>
<body>
  <header>
    <h1>Buscas realizadas</h1>
    <div>
      <a class="btn secondary" href="index.php">Todos os leads</a>
      <a class="btn secondary" href="logout.php">Sair</a>
    </div>
  </header>
  <main>
    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <p class="muted">Total de buscas: <?= h((string) $totalRows) ?> — página <?= h((string) $page) ?> / <?= h((string) $totalPages) ?></p>
    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Slug</th>
            <th>Palavra-chave</th>
            <th>Localização</th>
            <th>Leads</th>
            <th>Criada em</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="nowrap"><?= h((string) $r['id']) ?></td>
              <td class="nowrap"><?= h((string) $r['slug']) ?></td>
              <td><?= h((string) $r['keyword']) ?></td>
              <td><?= h((string) $r['localizacao']) ?></td>
              <td><?= h((string) $r['total_leads']) ?></td>
              <td class="nowrap"><?= h((string) $r['created_at']) ?></td>
              <td class="nowrap">
                <a class="btn" href="index.php?search_id=<?= h((string) (int) $r['id']) ?>">Ver leads</a>
                <form class="inline" method="post" action="search_delete.php" onsubmit="return confirm('Excluir esta busca e TODOS os seus leads?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                  <input type="hidden" name="search_id" value="<?= h((string) (int) $r['id']) ?>" />
                  <button class="btn danger" type="submit">Excluir busca</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($rows === []): ?>
            <tr><td colspan="7">Nenhuma busca registrada ainda.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn secondary" href="?page=<?= h((string) ($page - 1)) ?>">« Anterior</a><?php endif; ?>
      <?php if ($page < $totalPages): ?><a class="btn secondary" href="?page=<?= h((string) ($page + 1)) ?>">Próxima »</a><?php endif; ?>
    </div>
  </main>
</body>
</html>
