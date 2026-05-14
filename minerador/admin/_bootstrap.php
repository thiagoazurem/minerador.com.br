<?php
declare(strict_types=1);

session_start();

header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; media-src 'self' https:; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require_once dirname(__DIR__) . '/bootstrap_db.php';

function minerador_admin_verify_config(string $user, string $pass): bool
{
    $c = minerador_config();
    if (($c['admin_user'] ?? '') !== $user) {
        return false;
    }
    $hash = (string) ($c['admin_pass_hash'] ?? '');
    if ($hash === '') {
        return false;
    }

    return password_verify($pass, $hash);
}

function minerador_admin_verify_delegated(PDO $pdo, string $user, string $pass): ?int
{
    $u = trim($user);
    if ($u === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT id, password_hash FROM minerador_users WHERE username = ? AND is_active = 1 LIMIT 1');
    $st->execute([$u]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $hash = (string) ($row['password_hash'] ?? '');
    if ($hash === '' || !password_verify($pass, $hash)) {
        return null;
    }

    return (int) $row['id'];
}

function minerador_admin_is_config_admin(): bool
{
    return !empty($_SESSION['minerador_is_config_admin']);
}

function minerador_admin_delegated_user_id(): ?int
{
    if (minerador_admin_is_config_admin()) {
        return null;
    }
    $id = (int) ($_SESSION['minerador_user_id'] ?? 0);

    return $id > 0 ? $id : null;
}

/**
 * Fragmento SQL (com AND inicial) + params para restringir linhas de `minerador_searches` (alias `s`).
 *
 * Admin de configuração (MySQL):
 * - URL sem `scope=mine` (“Todos”): sem filtro extra — vê todas as buscas (cfg, u:…, etc.).
 * - `scope=mine` (“Só token do admin”): apenas buscas criadas com `minerador_token` do `config.php`
 *   (`datacollect.php` grava `owner_key = 'cfg'`).
 *
 * Utilizador delegado: sempre o seu `owner_user_id` (ignora `scope` na URL).
 *
 * @return array{0: string, 1: list<mixed>}
 */
function minerador_admin_search_scope_sql(): array
{
    if (empty($_SESSION['minerador_admin_ok'])) {
        return [' AND 1=0', []];
    }
    if (minerador_admin_is_config_admin()) {
        $scope = (string) ($_GET['scope'] ?? 'all');
        if ($scope === 'mine') {
            return [' AND s.owner_key = ?', ['cfg']];
        }

        return ['', []];
    }
    $uid = (int) ($_SESSION['minerador_user_id'] ?? 0);
    if ($uid <= 0) {
        return [' AND 1=0', []];
    }

    return [' AND s.owner_user_id = ?', [$uid]];
}

/**
 * Fragmento SQL (com AND inicial) + params para restringir `minerador_leads` pela busca dona.
 *
 * Quando `$searchJoinedAsS` é true, a query já inclui `LEFT JOIN minerador_searches s ON s.id = l.search_id`
 * (ex.: leads.php, index.php); usa-se `s.owner_key` / `s.owner_user_id` em vez de `EXISTS`.
 *
 * Quando é false (ex.: export.php, só `FROM minerador_leads l`), usa-se `EXISTS` com alias `sx`.
 *
 * Semântica: “Todos” sem filtro extra; `scope=mine` (admin de config) → `owner_key = 'cfg'`; delegado → o seu `owner_user_id`.
 *
 * @param ?string $configAdminScopeOverride Se não for null, substitui `$_GET['scope']` para admin de config (ex.: POST em scripts que não têm query-string).
 *
 * @return array{0: string, 1: list<mixed>}
 */
function minerador_admin_lead_scope_sql(bool $searchJoinedAsS = false, ?string $configAdminScopeOverride = null): array
{
    if (empty($_SESSION['minerador_admin_ok'])) {
        return [' AND 1=0', []];
    }
    if (minerador_admin_is_config_admin()) {
        $scope = $configAdminScopeOverride !== null ? $configAdminScopeOverride : (string) ($_GET['scope'] ?? 'all');
        if ($scope === 'mine') {
            if ($searchJoinedAsS) {
                return [' AND s.owner_key = ?', ['cfg']];
            }

            return [' AND EXISTS (SELECT 1 FROM minerador_searches sx WHERE sx.id = minerador_leads.search_id AND sx.owner_key = ?)', ['cfg']];
        }

        return ['', []];
    }
    $uid = (int) ($_SESSION['minerador_user_id'] ?? 0);
    if ($uid <= 0) {
        return [' AND 1=0', []];
    }

    if ($searchJoinedAsS) {
        return [' AND s.owner_user_id = ?', [$uid]];
    }

    return [' AND EXISTS (SELECT 1 FROM minerador_searches sx WHERE sx.id = minerador_leads.search_id AND sx.owner_user_id = ?)', [$uid]];
}

/**
 * Filtros da listagem de leads (alias `l`), alinhados a `leads.php`.
 *
 * @param array<string, mixed> $q Chaves: search_id, fq, nome, cidade, estado, pais, date_from, date_to, tem_site (como em $_GET).
 *
 * @return array{0: string, 1: list<mixed>} Fragmento SQL com prefixo " AND ..." ou string vazia, e parâmetros.
 */
function minerador_admin_leads_filter_sql_from_query(array $q): array
{
    $where = [];
    $params = [];

    $searchId = (int) ($q['search_id'] ?? 0);
    if ($searchId > 0) {
        $where[] = 'l.search_id = ?';
        $params[] = $searchId;
    }

    $fq = trim((string) ($q['fq'] ?? ''));
    if ($fq !== '') {
        $where[] = 'l.query_text LIKE ?';
        $params[] = '%' . $fq . '%';
    }

    $cidade = trim((string) ($q['cidade'] ?? ''));
    if ($cidade !== '') {
        $where[] = 'l.cidade LIKE ?';
        $params[] = '%' . $cidade . '%';
    }

    $estado = trim((string) ($q['estado'] ?? ''));
    if ($estado !== '') {
        $where[] = 'l.estado LIKE ?';
        $params[] = '%' . $estado . '%';
    }

    $pais = trim((string) ($q['pais'] ?? ''));
    if ($pais !== '') {
        $where[] = 'l.pais LIKE ?';
        $params[] = '%' . $pais . '%';
    }

    $nome = trim((string) ($q['nome'] ?? ''));
    if ($nome !== '') {
        $where[] = 'l.nome LIKE ?';
        $params[] = '%' . $nome . '%';
    }

    $df = trim((string) ($q['date_from'] ?? ''));
    if ($df !== '') {
        $where[] = 'DATE(l.coletado_em) >= ?';
        $params[] = $df;
    }
    $dt = trim((string) ($q['date_to'] ?? ''));
    if ($dt !== '') {
        $where[] = 'DATE(l.coletado_em) <= ?';
        $params[] = $dt;
    }

    $tem = trim((string) ($q['tem_site'] ?? ''));
    if ($tem === 'sim') {
        $where[] = '(l.website IS NOT NULL AND l.website <> \'\')';
    } elseif ($tem === 'nao') {
        $where[] = '(l.website IS NULL OR l.website = \'\')';
    }

    $sql = $where ? (' AND ' . implode(' AND ', $where)) : '';

    return [$sql, $params];
}

function minerador_admin_require_login(): void
{
    if (!empty($_SESSION['minerador_admin_ok'])) {
        return;
    }
    header('Location: login.php');
    exit;
}

function minerador_admin_require_config_admin(): void
{
    minerador_admin_require_login();
    if (!minerador_admin_is_config_admin()) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

/**
 * @param array<string, mixed> $searchRow linha de minerador_searches
 *
 * Com admin de config em `scope=mine`, só permite operações em buscas `owner_key = 'cfg'`
 * (dados ingeridos com o token do `config.php`). Em modo “Todos”, permite qualquer busca.
 */
function minerador_admin_can_manage_search(array $searchRow, ?string $scopeOverride = null): bool
{
    if (empty($_SESSION['minerador_admin_ok'])) {
        return false;
    }
    $scope = $scopeOverride ?? (string) ($_GET['scope'] ?? 'all');
    if (minerador_admin_is_config_admin()) {
        if ($scope === 'mine') {
            return (string) ($searchRow['owner_key'] ?? '') === 'cfg';
        }

        return true;
    }
    $uid = (int) ($_SESSION['minerador_user_id'] ?? 0);

    return $uid > 0 && (int) ($searchRow['owner_user_id'] ?? 0) === $uid;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function minerador_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['csrf'];
}

function minerador_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $stored = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $stored === '' || !hash_equals($stored, $sent)) {
        http_response_code(400);
        exit('CSRF inválido');
    }
}

/**
 * Normaliza resposta do captcha (minúsculas, sem acentos quando possível).
 */
function minerador_normalize_answer(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    if (class_exists('Transliterator')) {
        $t = Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
        if ($t !== null) {
            $s = (string) $t->transliterate($s);
        }
    }

    return $s;
}

require_once __DIR__ . '/_nav.php';
