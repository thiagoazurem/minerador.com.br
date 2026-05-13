<?php

declare(strict_types=1);



require_once __DIR__ . '/_bootstrap.php';

minerador_admin_require_login();



$pdo = minerador_pdo();

$csrf = minerador_csrf_token();

$isCfg = minerador_admin_is_config_admin();

$delegatedId = minerador_admin_delegated_user_id();



$cfg = minerador_config();

$cfgToken = (string) ($cfg['minerador_token'] ?? '');



$qualificacaoRulesText = '';
$rules = minerador_settings_get_qualificacao_website_rules($pdo);
foreach ($rules as $r) {
    $qualificacaoRulesText .= ($r['substr'] === '' ? '' : $r['substr']) . ' = ' . $r['nivel'] . "\n";
}
$qualificacaoRulesText = rtrim($qualificacaoRulesText);



$delegatedToken = '';

if ($delegatedId !== null) {

    $u = $pdo->prepare('SELECT minerador_token FROM minerador_users WHERE id = ? LIMIT 1');

    $u->execute([$delegatedId]);

    $delegatedToken = (string) ($u->fetchColumn() ?: '');

}



$scopeMine = $isCfg && (string) ($_GET['scope'] ?? '') === 'mine';

$wipeCounts = ['gallery' => 0, 'leads' => 0, 'searches' => 0];
if ($isCfg) {
    try {
        $wipeCounts['gallery'] = (int) $pdo->query('SELECT COUNT(*) FROM minerador_gallery')->fetchColumn();
        $wipeCounts['leads'] = (int) $pdo->query('SELECT COUNT(*) FROM minerador_leads')->fetchColumn();
        $wipeCounts['searches'] = (int) $pdo->query('SELECT COUNT(*) FROM minerador_searches')->fetchColumn();
    } catch (Throwable $e) {
        $wipeCounts = ['gallery' => 0, 'leads' => 0, 'searches' => 0];
    }
}

$flash = '';

if (isset($_GET['saved'])) {

    if ($_GET['saved'] === 'strings') {

        $flash = 'Strings de qualificação guardadas.';

    } elseif ($_GET['saved'] === 'config_token') {

        $flash = 'Token do config.php regenerado. Copie o novo valor para a extensão.';

    } elseif ($_GET['saved'] === 'user_token') {

        $flash = 'O seu minerador_token foi regenerado.';

    } elseif ($_GET['saved'] === 'password') {

        $flash = 'Senha alterada com sucesso.';

    } elseif ($_GET['saved'] === 'wipe') {

        $flash = 'Todos os registos de leads, buscas e galeria foram eliminados.';

    }

}

$newTokGet = isset($_GET['new_token']) ? (string) $_GET['new_token'] : '';

$errGet = isset($_GET['err']) ? (string) $_GET['err'] : '';



?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

  <meta charset="UTF-8" />

  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <title>Config — Minerador.pt</title>

  <style>

    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }

    a { color:#93c5fd; }

    <?= minerador_admin_header_css() ?>

    main { padding:18px 20px 40px; max-width:800px; margin:0 auto; }

    .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; font:inherit; }

    .btn.secondary { background:#374151; }

    .btn.danger { background:#991b1b; }

    .btn.danger:hover { background:#b91c1c; }

    .box { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:16px; margin-bottom:18px; }

    .flash { background:#065f46; color:#d1fae5; padding:10px 14px; border-radius:8px; margin-bottom:14px; }

    .flash.warn { background:#78350f; color:#ffedd5; }

    label { display:block; font-size:12px; color:#9ca3af; margin-top:10px; }

    textarea { width:100%; min-height:140px; padding:8px 10px; margin-top:4px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; font:inherit; box-sizing:border-box; }

    input[type=password] { width:100%; max-width:360px; padding:8px 10px; margin-top:4px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; font:inherit; box-sizing:border-box; }

    .wipe-dialog { max-width:420px; width:calc(100vw - 32px); border:none; border-radius:12px; padding:0; background:#111827; color:#e5e7eb; box-shadow:0 20px 50px rgba(0,0,0,.5); }

    .wipe-dialog::backdrop { background:rgba(0,0,0,.65); }

    .wipe-dialog-inner { padding:18px 20px 20px; }

    .wipe-dialog h3 { margin:0 0 10px; font-size:17px; color:#fecaca; }

    .wipe-dialog ul { margin:8px 0 12px; padding-left:1.2em; color:#9ca3af; font-size:14px; }

    .wipe-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; align-items:center; }

    .box.danger-zone { border-color:#7f1d1d; }

    .token { font-family:ui-monospace,monospace; font-size:12px; word-break:break-all; background:#0f172a; padding:10px; border-radius:8px; border:1px solid #374151; margin-top:8px; }

    .muted { color:#9ca3af; font-size:13px; }

    h2 { font-size:16px; margin:0 0 10px; }

    p { margin:8px 0; }

  </style>

</head>

<body>

  <?php minerador_admin_render_page_header('Config', []); ?>

  <main>

    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

    <?php if ($newTokGet !== ''): ?>

      <div class="flash">Novo token (copie agora): <span class="token"><?= h($newTokGet) ?></span></div>

    <?php endif; ?>

    <?php if ($errGet === 'pwd_mismatch'): ?>

      <div class="flash warn">As senhas não coincidem.</div>

    <?php elseif ($errGet === 'pwd_short'): ?>

      <div class="flash warn">A nova senha deve ter pelo menos 6 caracteres.</div>

    <?php elseif ($errGet === 'pwd_config'): ?>

      <div class="flash warn">O administrador de configuração altera a senha em config.php.</div>

    <?php elseif ($errGet === 'wipe_pwd'): ?>

      <div class="flash warn">Senha de administrador incorreta. Nada foi eliminado.</div>

    <?php elseif ($errGet === 'wipe_db'): ?>

      <div class="flash warn">Erro na base de dados ao eliminar. Nada foi alterado ou confirme o estado dos dados.</div>

    <?php elseif ($errGet !== ''): ?>

      <div class="flash warn"><?= h($errGet === 'strings_json' ? 'Erro ao guardar strings.' : ($errGet === 'token_retry' ? 'Não foi possível gerar token único; tente novamente.' : $errGet)) ?></div>

    <?php endif; ?>



    <?php if ($isCfg): ?>

      <div class="box">

        <h2>Token da extensão (config.php)</h2>

        <p class="muted">Este é o token usado quando entra como administrador de configuração na extensão. Não partilhe.</p>

        <div class="token"><?= h($cfgToken !== '' ? $cfgToken : '(vazio)') ?></div>

        <form method="post" action="settings_save.php" style="margin-top:14px;" onsubmit="return confirm('Regenerar o token? Terá de atualizar a extensão com o novo valor.');">

          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

          <input type="hidden" name="action" value="regenerate_config_token" />

          <?php if ($scopeMine): ?><input type="hidden" name="scope" value="mine" /><?php endif; ?>

          <button type="submit" class="btn secondary">Regenerar token (config.php)</button>

        </form>

      </div>



      <div class="box">

        <h2>Qualificação automática — substrings no website</h2>

        <p class="muted">Uma regra por linha: <code>substring = nível</code> (níveis: <code>baixo</code>, <code>medio</code>, <code>alto</code>, <code>max</code>). A comparação na URL do website é <strong>sem distinção de maiúsculas/minúsculas</strong>. A <strong>primeira</strong> regra cuja substring aparecer no URL ganha. Linhas com substring vazia antes do <code>=</code> (ex.: <code>= medio</code>) são catch-all: a <strong>última</strong> assim definida aplica-se quando há website mas nenhuma regra anterior bateu. Sem website o sistema usa sempre <strong>medio</strong>.</p>

        <form method="post" action="settings_save.php">

          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

          <input type="hidden" name="action" value="save_qualificacao_substrings" />

          <?php if ($scopeMine): ?><input type="hidden" name="scope" value="mine" /><?php endif; ?>

          <label for="qualificacao_substrings">Regras</label>

          <textarea id="qualificacao_substrings" name="qualificacao_substrings" spellcheck="false"><?= h($qualificacaoRulesText) ?></textarea>

          <div style="margin-top:12px;"><button type="submit" class="btn">Guardar strings</button></div>

        </form>

      </div>

      <div class="box danger-zone">

        <h2>Zona perigosa</h2>

        <p class="muted">Eliminar <strong>todos</strong> os registos de <code>minerador_leads</code>, <code>minerador_searches</code> e <code>minerador_gallery</code> (médias). Utilizadores, definições e token em <code>config.php</code> <strong>não</strong> são alterados.</p>

        <p class="muted" style="font-size:13px;">Estado atual: <?= h((string) $wipeCounts['gallery']) ?> médias · <?= h((string) $wipeCounts['leads']) ?> leads · <?= h((string) $wipeCounts['searches']) ?> buscas.</p>

        <button type="button" class="btn danger" id="openWipeDialog" style="margin-top:10px;">Eliminar todos os leads, buscas e médias…</button>

      </div>

      <dialog id="wipeDialog" class="wipe-dialog" aria-labelledby="wipeDialogTitle">

        <div class="wipe-dialog-inner">

          <h3 id="wipeDialogTitle">Confirmar eliminação total</h3>

          <p class="muted" style="font-size:14px;">Esta operação é <strong>irreversível</strong>. Serão apagadas todas as linhas das tabelas de galeria, leads e buscas.</p>

          <ul>

            <li><code>minerador_gallery</code></li>

            <li><code>minerador_leads</code></li>

            <li><code>minerador_searches</code></li>

          </ul>

          <p class="muted" style="font-size:14px;">Para confirmar, introduza a <strong>senha do administrador</strong> (a mesma de <code>config.php</code> / login de config).</p>

          <form method="post" action="settings_save.php" id="wipeForm">

            <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

            <input type="hidden" name="action" value="wipe_all_leads_data" />

            <?php if ($scopeMine): ?><input type="hidden" name="scope" value="mine" /><?php endif; ?>

            <label for="wipe_admin_password">Senha do administrador</label>

            <input id="wipe_admin_password" name="wipe_admin_password" type="password" required autocomplete="current-password" maxlength="256" style="max-width:none;" />

            <div class="wipe-actions">

              <button type="submit" class="btn danger">Eliminar definitivamente</button>

              <button type="button" class="btn secondary" id="closeWipeDialog">Cancelar</button>

            </div>

          </form>

        </div>

      </dialog>

      <script>

        (function () {

          var dlg = document.getElementById('wipeDialog');

          var openBtn = document.getElementById('openWipeDialog');

          var closeBtn = document.getElementById('closeWipeDialog');

          var pwd = document.getElementById('wipe_admin_password');

          if (!dlg || !openBtn) return;

          openBtn.addEventListener('click', function () {

            if (pwd) pwd.value = '';

            dlg.showModal();

            if (pwd) pwd.focus();

          });

          if (closeBtn) closeBtn.addEventListener('click', function () { dlg.close(); });

        })();

      </script>

    <?php else: ?>

      <div class="box">

        <h2>Qualificação automática</h2>

        <p class="muted">As regras de qualificação automática (substring no website) são definidas pelo administrador (config.php) em Configurações.</p>

      </div>

    <?php endif; ?>



    <?php if ($delegatedId !== null): ?>

      <div class="box">

        <h2>O seu minerador_token</h2>

        <p class="muted">Use este valor no header <code>X-Minerador-Token</code> da extensão.</p>

        <div class="token"><?= h($delegatedToken !== '' ? $delegatedToken : '(vazio)') ?></div>

        <form method="post" action="settings_save.php" style="margin-top:14px;" onsubmit="return confirm('Regenerar o seu token? Terá de atualizar a extensão.');">

          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

          <input type="hidden" name="action" value="regenerate_delegated_token" />

          <button type="submit" class="btn secondary">Regenerar o meu token</button>

        </form>

      </div>

      <div class="box">

        <h2>Alterar senha</h2>

        <p class="muted">Nova senha e confirmação devem ser iguais (mínimo 6 caracteres).</p>

        <form method="post" action="password_change_save.php" style="margin-top:12px;">

          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />

          <label for="password_new">Nova senha</label>

          <input id="password_new" name="password_new" type="password" required minlength="6" autocomplete="new-password" />

          <label for="password_confirm" style="margin-top:12px;">Confirmar senha</label>

          <input id="password_confirm" name="password_confirm" type="password" required minlength="6" autocomplete="new-password" />

          <div style="margin-top:14px;"><button type="submit" class="btn">Guardar nova senha</button></div>

        </form>

      </div>

    <?php endif; ?>

  </main>

</body>

</html>

