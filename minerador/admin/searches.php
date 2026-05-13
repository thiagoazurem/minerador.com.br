<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

$pdo = minerador_pdo();

function searches_qs(array $extra): string
{
    $base = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }

    return http_build_query($base);
}

[$scopeSql, $scopeParams] = minerador_admin_search_scope_sql();

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$off = ($page - 1) * $per;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM minerador_searches s WHERE 1=1 ' . $scopeSql);
$countStmt->execute($scopeParams);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $per));
if ($page > $totalPages) {
    $page = $totalPages;
    $off = ($page - 1) * $per;
}

$isCfgAdmin = minerador_admin_is_config_admin();
$userJoin = $isCfgAdmin ? ' LEFT JOIN minerador_users u ON u.id = s.owner_user_id ' : '';
$userSelect = $isCfgAdmin ? ', u.username AS owner_username' : '';
$listStmt = $pdo->prepare(
    'SELECT s.id, s.slug, s.keyword, s.localizacao, s.query_text, s.created_at, s.owner_key' . $userSelect . ', ' .
    '(SELECT COUNT(*) FROM minerador_leads l WHERE l.search_id = s.id) AS total_leads ' .
    'FROM minerador_searches s' . $userJoin . ' WHERE 1=1 ' . $scopeSql . ' ORDER BY s.created_at DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off
);
$listStmt->execute($scopeParams);
$rows = $listStmt->fetchAll();

$csrf = minerador_csrf_token();
$searchesScopeMine = $isCfgAdmin && (string) ($_GET['scope'] ?? '') === 'mine';

$transferUsers = [];
if ($isCfgAdmin) {
    try {
        $transferUsers = $pdo->query(
            'SELECT id, username FROM minerador_users WHERE is_active = 1 ORDER BY username ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        $transferUsers = [];
    }
}

$flash = '';
$flashWarn = '';
if (isset($_GET['deleted'])) {
    $flash = 'Busca excluída.';
} elseif (isset($_GET['saved']) && (string) $_GET['saved'] === 'transfer') {
    $flash = 'Busca e leads associados foram transferidos para o utilizador selecionado.';
} elseif (isset($_GET['saved'])) {
    $flash = 'Alterações salvas.';
} elseif (isset($_GET['err'])) {
    $err = (string) $_GET['err'];
    if ($err === 'transfer_slug') {
        $flashWarn = 'Não foi possível transferir: o utilizador destino já tem uma busca com o mesmo slug.';
    } elseif ($err === 'transfer_user') {
        $flashWarn = 'Utilizador destino inválido ou inativo.';
    } elseif ($err === 'transfer_same') {
        $flashWarn = 'Esta busca já pertence a esse utilizador.';
    } elseif ($err === 'transfer_db') {
        $flashWarn = 'Erro na base de dados ao transferir.';
    } else {
        $flashWarn = 'Operação falhou.';
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Searches — Minerador.pt</title>
  <style>
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    a { color:#93c5fd; }
    <?= minerador_admin_header_css() ?>
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
    .flash.warn { background:#78350f; color:#ffedd5; }
    form.inline { display:inline; }
    .row-actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
    .transfer-dialog { max-width:440px; width:calc(100vw - 32px); border:none; border-radius:12px; padding:0; background:#111827; color:#e5e7eb; box-shadow:0 20px 50px rgba(0,0,0,.5); }
    .transfer-dialog::backdrop { background:rgba(0,0,0,.65); }
    .transfer-dialog-inner { padding:18px 20px 20px; }
    .transfer-dialog h3 { margin:0 0 10px; font-size:17px; }
    .transfer-dialog label { display:block; font-size:12px; color:#9ca3af; margin-top:12px; }
    .transfer-dialog select { width:100%; margin-top:4px; padding:8px 10px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; font:inherit; box-sizing:border-box; }
    .transfer-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; align-items:center; }
  </style>
</head>
<body>
  <?php minerador_admin_render_page_header('Searches', []); ?>
  <main>
    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($flashWarn !== ''): ?><div class="flash warn"><?= h($flashWarn) ?></div><?php endif; ?>
    <?php if ($isCfgAdmin): ?>
      <p class="muted" style="margin-bottom:12px;">Como administrador de configuração, pode transferir uma busca (e todos os leads com o mesmo <code>search_id</code>) para si (token admin em <code>config.php</code>) ou para um utilizador delegado.</p>
    <?php endif; ?>
    <p class="muted">Total de buscas: <?= h((string) $totalRows) ?> — página <?= h((string) $page) ?> / <?= h((string) $totalPages) ?></p>
    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Slug</th>
            <th>Palavra-chave</th>
            <th>Localização</th>
            <?php if ($isCfgAdmin): ?><th>Username</th><?php endif; ?>
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
              <?php if ($isCfgAdmin): ?>
                <td class="nowrap"><?php
                $ownerUser = trim((string) ($r['owner_username'] ?? ''));
                if ($ownerUser !== '') {
                    echo h($ownerUser);
                } elseif ((string) ($r['owner_key'] ?? '') === 'cfg') {
                    ?><span class="muted">(admin / config)</span><?php
                } else {
                    ?><span class="muted">—</span><?php
                }
                ?></td>
              <?php endif; ?>
              <td><?= h((string) $r['total_leads']) ?></td>
              <td class="nowrap"><?= h((string) $r['created_at']) ?></td>
              <td class="nowrap">
                <div class="row-actions">
                  <a class="btn" href="leads.php?<?= h(searches_qs(['search_id' => (int) $r['id'], 'page' => null])) ?>">Ver leads</a>
                  <?php if ($isCfgAdmin): ?>
                    <button type="button" class="btn secondary js-open-transfer"
                      data-search-id="<?= h((string) (int) $r['id']) ?>"
                      data-slug="<?= h((string) $r['slug']) ?>">Transferir registros</button>
                  <?php endif; ?>
                  <form class="inline" method="post" action="search_delete.php" onsubmit="return confirm('Excluir esta busca e TODOS os seus leads?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="search_id" value="<?= h((string) (int) $r['id']) ?>" />
                    <?php if ($searchesScopeMine): ?>
                      <input type="hidden" name="redirect_scope" value="mine" />
                    <?php endif; ?>
                    <button class="btn danger" type="submit">Excluir busca</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($rows === []): ?>
            <tr><td colspan="<?= $isCfgAdmin ? 8 : 7 ?>">Nenhuma busca registrada ainda.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn secondary" href="?<?= h(searches_qs(['page' => $page - 1])) ?>">« Anterior</a><?php endif; ?>
      <?php if ($page < $totalPages): ?><a class="btn secondary" href="?<?= h(searches_qs(['page' => $page + 1])) ?>">Próxima »</a><?php endif; ?>
    </div>

    <?php if ($isCfgAdmin): ?>
      <dialog id="transferDialog" class="transfer-dialog" aria-labelledby="transferDialogTitle">
        <div class="transfer-dialog-inner">
          <h3 id="transferDialogTitle">Transferir busca</h3>
          <p class="muted" style="font-size:14px;margin:0;">Busca <strong id="transferDialogSlug"></strong>: os leads mantêm-se; apenas o dono da busca muda.</p>
          <form method="post" action="search_transfer_save.php" id="transferForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
            <input type="hidden" name="search_id" id="transferSearchId" value="" />
            <?php if ($searchesScopeMine): ?>
              <input type="hidden" name="redirect_scope" value="mine" />
            <?php endif; ?>
            <label for="transferTargetUser">Novo proprietário</label>
            <select id="transferTargetUser" name="target_user_id" required>
              <option value="" disabled selected>— Escolher destino —</option>
              <option value="0">Eu (token admin / config)</option>
              <?php foreach ($transferUsers as $tu): ?>
                <option value="<?= h((string) (int) $tu['id']) ?>"><?= h((string) $tu['username']) ?> (#<?= h((string) (int) $tu['id']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="transfer-actions">
              <button type="submit" class="btn">Confirmar transferência</button>
              <button type="button" class="btn secondary" id="transferDialogCancel">Cancelar</button>
            </div>
          </form>
        </div>
      </dialog>
      <script>
        (function () {
          var dlg = document.getElementById('transferDialog');
          var sid = document.getElementById('transferSearchId');
          var slugEl = document.getElementById('transferDialogSlug');
          var sel = document.getElementById('transferTargetUser');
          var cancel = document.getElementById('transferDialogCancel');
          if (!dlg || !sid) return;
          document.querySelectorAll('.js-open-transfer').forEach(function (btn) {
            btn.addEventListener('click', function () {
              if (btn.disabled) return;
              sid.value = btn.getAttribute('data-search-id') || '';
              if (slugEl) slugEl.textContent = btn.getAttribute('data-slug') || '';
              if (sel) { sel.selectedIndex = 0; }
              dlg.showModal();
            });
          });
          if (cancel) cancel.addEventListener('click', function () { dlg.close(); });
        })();
      </script>
    <?php endif; ?>
  </main>
</body>
</html>
