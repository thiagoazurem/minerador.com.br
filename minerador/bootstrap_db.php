<?php
declare(strict_types=1);

(function (): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $logFiles = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-errors.log',
        __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-errors.log',
        rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'minerador-php-errors.log',
    ];

    $picked = null;
    foreach ($logFiles as $file) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            continue;
        }
        if (!is_file($file)) {
            if (@file_put_contents($file, '') === false) {
                continue;
            }
        } elseif (!is_writable($file)) {
            continue;
        }
        $picked = $file;
        break;
    }

    ini_set('log_errors', '1');
    if ($picked !== null) {
        ini_set('error_log', $picked);
    }

    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if ($e === null) {
            return;
        }
        $t = (int) $e['type'];
        if (!in_array($t, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        error_log(sprintf(
            '[minerador fatal] %s in %s:%d',
            $e['message'],
            (string) ($e['file'] ?? ''),
            (int) ($e['line'] ?? 0)
        ));
    });
})();

/**
 * Conexão PDO compartilhada (datacollect + admin).
 */
function minerador_config(): array
{
    $path = __DIR__ . '/config.php';
    if (!is_readable($path)) {
        throw new RuntimeException('Arquivo config.php não encontrado. Copie config.example.php para config.php.');
    }
    /** @var array $cfg */
    $cfg = require $path;
    return $cfg;
}

function minerador_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = minerador_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $c['db_host'],
        $c['db_name'],
        $c['db_charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function minerador_lead_hash(string $nome, string $endereco, string $urlResultado = ''): string
{
    $n = mb_strtolower(trim($nome), 'UTF-8');
    $e = mb_strtolower(trim($endereco), 'UTF-8');
    $u = mb_strtolower(trim($urlResultado), 'UTF-8');
    return hash('sha256', $n . '|' . $e . '|' . $u);
}

const MINERADOR_SETTING_QUALIFICACAO_SUBSTRINGS = 'qualificacao_website_substrings';

/**
 * Token único para utilizadores delegados (evita colisão com token do config).
 */
function minerador_new_user_token(string $cfgTok): string
{
    for ($i = 0; $i < 12; $i++) {
        $t = bin2hex(random_bytes(24));
        if ($cfgTok !== '' && $cfgTok !== 'ALTERE_ESTE_TOKEN_LONGO' && hash_equals($cfgTok, $t)) {
            continue;
        }

        return $t;
    }

    return bin2hex(random_bytes(24));
}

function minerador_normalize_qualificacao_nivel(string $raw): ?string
{
    $r = mb_strtolower(trim($raw), 'UTF-8');
    if ($r === 'médio') {
        $r = 'medio';
    }
    if ($r === 'máx' || $r === 'máximo') {
        $r = 'max';
    }
    if (in_array($r, ['baixo', 'medio', 'alto', 'max'], true)) {
        return $r;
    }

    return null;
}

/**
 * Regras de qualificação automática por substring no website (ordem importa).
 * Formato em BD: JSON `[{"substr":"…","nivel":"alto"},…]` ou legado: array de strings (todas tratadas como nivel alto).
 *
 * @return list<array{substr: string, nivel: string}>
 */
function minerador_settings_get_qualificacao_website_rules(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $st = $pdo->prepare('SELECT setting_value FROM minerador_settings WHERE setting_key = ? LIMIT 1');
        $st->execute([MINERADOR_SETTING_QUALIFICACAO_SUBSTRINGS]);
        $raw = $st->fetchColumn();
    } catch (Throwable $e) {
        $cached = [];

        return $cached;
    }
    if ($raw === false || $raw === null || (string) $raw === '') {
        $cached = [];

        return $cached;
    }
    $s = (string) $raw;
    $decoded = json_decode($s, true);
    if (is_array($decoded)) {
        if ($decoded === []) {
            $cached = [];

            return $cached;
        }
        $keys = array_keys($decoded);
        $isList = $keys === range(0, count($decoded) - 1);
        if (!$isList) {
            $out = [];
            foreach ($decoded as $k => $v) {
                $substr = mb_substr(trim((string) $k), 0, 512, 'UTF-8');
                $nivel = minerador_normalize_qualificacao_nivel((string) $v);
                if ($nivel === null) {
                    continue;
                }
                $out[] = ['substr' => $substr, 'nivel' => $nivel];
            }
            $cached = $out;

            return $cached;
        }
        $first = $decoded[0] ?? null;
        if (is_string($first)) {
            $out = [];
            foreach ($decoded as $v) {
                $t = trim((string) $v);
                if ($t !== '') {
                    $out[] = ['substr' => mb_substr($t, 0, 512, 'UTF-8'), 'nivel' => 'alto'];
                }
            }
            $cached = $out;

            return $cached;
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $substr = isset($row['substr']) ? trim((string) $row['substr']) : trim((string) ($row['needle'] ?? ''));
            $substr = mb_substr($substr, 0, 512, 'UTF-8');
            $nivelRaw = (string) ($row['nivel'] ?? $row['level'] ?? '');
            $nivel = minerador_normalize_qualificacao_nivel($nivelRaw);
            if ($nivel === null) {
                continue;
            }
            $out[] = ['substr' => $substr, 'nivel' => $nivel];
        }
        $cached = $out;

        return $cached;
    }
    $out = [];
    foreach (preg_split('/\R/', $s) ?: [] as $line) {
        $t = trim((string) $line);
        if ($t !== '') {
            $out[] = ['substr' => mb_substr($t, 0, 512, 'UTF-8'), 'nivel' => 'alto'];
        }
    }
    $cached = $out;

    return $cached;
}

/**
 * @return list<array{substr: string, nivel: string}>
 */
function minerador_settings_get_qualificacao_substrings(PDO $pdo): array
{
    return minerador_settings_get_qualificacao_website_rules($pdo);
}

function minerador_settings_set(PDO $pdo, string $key, string $value): void
{
    $st = $pdo->prepare(
        'INSERT INTO minerador_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $st->execute([$key, $value]);
}

/**
 * Website vazio -> medio. Com website: percorre regras por ordem; primeira substring
 * (case-insensitive) contida no URL devolve o nivel; substr vazio acumula catch-all
 * (última regra com substr vazio aplica-se se nenhuma anterior tiver correspondido).
 *
 * @param list<array{substr: string, nivel: string}> $rules
 */
function minerador_qualificacao_auto(string $website, array $rules): ?string
{
    $w = trim($website);
    if ($w === '') {
        return 'medio';
    }
    $default = null;
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $needle = isset($rule['substr']) ? (string) $rule['substr'] : '';
        $nivelRaw = isset($rule['nivel']) ? (string) $rule['nivel'] : '';
        $nivel = minerador_normalize_qualificacao_nivel($nivelRaw);
        if ($needle === '') {
            if ($nivel !== null) {
                $default = $nivel;
            }

            continue;
        }
        if ($nivel === null) {
            continue;
        }
        if (mb_stripos($w, $needle, 0, 'UTF-8') !== false) {
            return $nivel;
        }
    }

    return $default;
}

/**
 * URL do Google Maps (allowlist de host + path). Entrada vazia ou inválida -> null.
 */
function minerador_normalize_mapurl_for_db(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if (stripos($s, 'http') === 0 && stripos($s, 'google.com/url') !== false) {
        $q = parse_url($s, PHP_URL_QUERY);
        if (is_string($q) && $q !== '') {
            parse_str($q, $params);
            if (!empty($params['q']) && is_string($params['q'])) {
                $s = trim($params['q']);
            }
        }
    }
    $s = mb_substr($s, 0, 65535, 'UTF-8');
    if (!filter_var($s, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) (parse_url($s, PHP_URL_SCHEME) ?: ''));
    if ($scheme !== 'https' && $scheme !== 'http') {
        return null;
    }
    $host = strtolower((string) (parse_url($s, PHP_URL_HOST) ?: ''));
    $path = (string) (parse_url($s, PHP_URL_PATH) ?: '');
    if ($host === 'maps.google.com') {
        return $s;
    }
    if (
        ($host === 'www.google.com' || $host === 'google.com' || $host === 'www.google.com.br' || $host === 'google.com.br')
        && str_starts_with($path, '/maps')
    ) {
        return $s;
    }

    return null;
}

function minerador_config_file_path(): string
{
    return __DIR__ . '/config.php';
}

/**
 * @return array{ok: bool, error?: string}
 */
function minerador_config_write_minerador_token(string $newToken): array
{
    if ($newToken === '' || str_contains($newToken, "'")) {
        return ['ok' => false, 'error' => 'Token inválido.'];
    }
    $path = minerador_config_file_path();
    if (!is_readable($path)) {
        return ['ok' => false, 'error' => 'config.php não legível.'];
    }
    if (!is_writable($path)) {
        return ['ok' => false, 'error' => 'config.php não tem permissão de escrita no servidor.'];
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return ['ok' => false, 'error' => 'Não foi possível ler config.php.'];
    }
    $newContent = preg_replace(
        "/('minerador_token'\\s*=>\\s*)'[^']*'/",
        '$1\'' . $newToken . '\'',
        $content,
        1,
        $count
    );
    if ($newContent === null || $count !== 1) {
        return ['ok' => false, 'error' => 'Não foi encontrado minerador_token em config.php (formato inesperado).'];
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $newContent) === false) {
        return ['ok' => false, 'error' => 'Falha ao gravar ficheiro temporário.'];
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);

        return ['ok' => false, 'error' => 'Falha ao substituir config.php.'];
    }

    return ['ok' => true];
}
