<?php
/**
 * ============================================================================
 *  QPayPro AI · Panel de administración de la base de conocimiento
 * ----------------------------------------------------------------------------
 *  API JSON de un solo endpoint. El frontend (admin.html) envía POST con
 *  { "action": "..." } y una cookie de sesión.
 *
 *  Gestiona el archivo qpaypro_docs.txt, que es la documentación que
 *  resolver.php inyecta como contexto RAG en cada consulta a la IA.
 *  Cada guardado crea una versión en docs_versions/ para poder revertir.
 * ============================================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // nunca filtrar rutas/errores al cliente

/* ---------------------------------------------------------------- Constantes */

const DOC_FILE        = 'qpaypro_docs.txt';
const VERSIONS_DIR    = 'docs_versions';
const VERSIONS_INDEX  = 'versions.json';
const ATTEMPTS_FILE   = '.admin_attempts.json';
const MAX_DOC_BYTES   = 2097152;   // 2 MB
const MAX_VERSIONS    = 40;        // se conservan las N más recientes
const MAX_ATTEMPTS    = 5;         // intentos fallidos por IP
const LOCKOUT_SECONDS = 900;       // 15 min de bloqueo
const IDLE_TIMEOUT    = 7200;      // 2 h de inactividad cierran la sesión

/* ------------------------------------------------------------------ Sesión */

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('QPAYADMIN');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

/* ------------------------------------------------------------------ Helpers */

function json_out(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): never
{
    json_out(['ok' => false, 'error' => $message], $code);
}

function env_value(string $key, string $default = ''): string
{
    static $env = null;
    if ($env === null) {
        $path = __DIR__ . '/.env';
        $env  = is_readable($path) ? (parse_ini_file($path) ?: []) : [];
    }
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return (string) $fromEnv;
    }
    return isset($env[$key]) && $env[$key] !== '' ? (string) $env[$key] : $default;
}

function client_ip(): string
{
    $fwd = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
}

/* mbstring no está garantizado en todas las instalaciones: se usa si existe. */
function u_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function u_substr(string $s, int $start, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($s, $start, $length, 'UTF-8')
        : substr($s, $start, $length);
}

function doc_path(): string
{
    return __DIR__ . '/' . DOC_FILE;
}

function versions_dir(): string
{
    $dir = __DIR__ . '/' . VERSIONS_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('No se pudo crear el directorio de versiones. Revisa permisos de escritura.', 500);
    }
    return $dir;
}

/** Escritura atómica: se escribe en un temporal y se renombra. */
function atomic_write(string $path, string $content): void
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        fail('No se pudo escribir el archivo. Revisa los permisos del directorio.', 500);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        fail('No se pudo reemplazar el archivo de documentación.', 500);
    }
    @chmod($path, 0664);
}

/* ---------------------------------------------------- Índice de versiones */

function versions_read(): array
{
    $file = versions_dir() . '/' . VERSIONS_INDEX;
    if (!is_readable($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function versions_write(array $index): void
{
    atomic_write(versions_dir() . '/' . VERSIONS_INDEX, json_encode(
        array_values($index),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    ));
}

/**
 * Guarda el contenido actual del documento como una nueva versión.
 * Devuelve la entrada creada (o null si el documento aún no existe).
 */
function versions_snapshot(string $note): ?array
{
    $current = doc_path();
    if (!is_readable($current)) {
        return null;
    }
    $content = (string) file_get_contents($current);
    $name    = 'doc_' . date('Ymd-His') . '_' . bin2hex(random_bytes(3)) . '.txt';

    atomic_write(versions_dir() . '/' . $name, $content);

    $entry = [
        'file'  => $name,
        'ts'    => time(),
        'note'  => u_substr(trim($note), 0, 160),
        'bytes' => strlen($content),
        'lines' => substr_count($content, "\n") + 1,
        'ip'    => client_ip(),
    ];

    $index = versions_read();
    array_unshift($index, $entry);

    // Poda: se eliminan del disco las versiones más antiguas.
    while (count($index) > MAX_VERSIONS) {
        $old = array_pop($index);
        $p   = versions_dir() . '/' . basename((string) ($old['file'] ?? ''));
        if (is_file($p)) {
            @unlink($p);
        }
    }
    versions_write($index);

    return $entry;
}

function version_file_path(string $name): string
{
    $name = basename($name);
    if (!preg_match('/^doc_\d{8}-\d{6}_[0-9a-f]{6}\.txt$/', $name)) {
        fail('Identificador de versión inválido.');
    }
    $path = versions_dir() . '/' . $name;
    if (!is_readable($path)) {
        fail('La versión solicitada ya no existe.', 404);
    }
    return $path;
}

/* -------------------------------------------------- Control de intentos */

function attempts_state(): array
{
    $file = __DIR__ . '/' . ATTEMPTS_FILE;
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];
    return is_array($data) ? $data : [];
}

function attempts_save(array $data): void
{
    @file_put_contents(__DIR__ . '/' . ATTEMPTS_FILE, json_encode($data), LOCK_EX);
}

/** Segundos restantes de bloqueo para la IP actual (0 = sin bloqueo). */
function lockout_remaining(): int
{
    $ip    = client_ip();
    $state = attempts_state();
    $row   = $state[$ip] ?? null;
    if (!$row || ($row['count'] ?? 0) < MAX_ATTEMPTS) {
        return 0;
    }
    $elapsed = time() - (int) ($row['last'] ?? 0);
    return $elapsed >= LOCKOUT_SECONDS ? 0 : LOCKOUT_SECONDS - $elapsed;
}

function attempts_register_failure(): void
{
    $ip    = client_ip();
    $state = attempts_state();
    $row   = $state[$ip] ?? ['count' => 0, 'last' => 0];

    // Si el bloqueo ya expiró, el contador arranca de cero.
    if (time() - (int) $row['last'] > LOCKOUT_SECONDS) {
        $row = ['count' => 0, 'last' => 0];
    }
    $row['count'] = (int) $row['count'] + 1;
    $row['last']  = time();
    $state[$ip]   = $row;

    // Limpieza de entradas viejas para que el archivo no crezca.
    foreach ($state as $key => $value) {
        if (time() - (int) ($value['last'] ?? 0) > LOCKOUT_SECONDS * 4) {
            unset($state[$key]);
        }
    }
    attempts_save($state);
}

function attempts_clear(): void
{
    $state = attempts_state();
    unset($state[client_ip()]);
    attempts_save($state);
}

/* -------------------------------------------------------------- Auth */

function password_configured(): bool
{
    return env_value('ADMIN_PASSWORD_HASH') !== '' || env_value('ADMIN_PASSWORD') !== '';
}

function password_matches(string $candidate): bool
{
    $hash = env_value('ADMIN_PASSWORD_HASH');
    if ($hash !== '') {
        return password_verify($candidate, $hash);
    }
    $plain = env_value('ADMIN_PASSWORD');
    return $plain !== '' && hash_equals($plain, $candidate);
}

function session_is_valid(): bool
{
    if (empty($_SESSION['admin'])) {
        return false;
    }
    if (time() - (int) ($_SESSION['seen'] ?? 0) > IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['seen'] = time();
    return true;
}

function require_session(): void
{
    if (!session_is_valid()) {
        fail('Tu sesión expiró. Vuelve a iniciar sesión.', 401);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(array $body): void
{
    $sent = (string) ($body['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        fail('Token de seguridad inválido. Recarga la página.', 419);
    }
}

/* ------------------------------------------------------- Métricas del doc */

function doc_stats(string $content): array
{
    $sections = preg_match_all('/^##\s+(.+)$/mu', $content, $m) ? $m[1] : [];
    $words    = preg_match_all('/[\p{L}\p{N}_]+/u', $content);

    return [
        'bytes'    => strlen($content),
        'chars'    => u_strlen($content),
        'lines'    => $content === '' ? 0 : substr_count($content, "\n") + 1,
        'words'    => $words === false ? 0 : $words,
        'tokens'   => (int) ceil(u_strlen($content) / 4),  // estimación ~4 chars/token
        'sections' => array_map(static fn($s) => trim($s), $sections),
    ];
}

/* ------------------------------------------------------------- Enrutador */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Solo se aceptan peticiones POST.', 405);
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    fail('Cuerpo de la petición inválido (se esperaba JSON).');
}
$action = (string) ($body['action'] ?? '');

switch ($action) {

    /* -------- Estado de la sesión (lo llama admin.html al cargar) -------- */
    case 'status':
        json_out([
            'ok'            => true,
            'authenticated' => session_is_valid(),
            'configured'    => password_configured(),
            'csrf'          => session_is_valid() ? csrf_token() : null,
            'lockout'       => lockout_remaining(),
            'doc'           => DOC_FILE,
        ]);

    /* ---------------------------- Login ---------------------------- */
    case 'login':
        if (!password_configured()) {
            fail('No hay contraseña de administrador configurada. Define ADMIN_PASSWORD_HASH en el archivo .env.', 503);
        }
        $wait = lockout_remaining();
        if ($wait > 0) {
            fail('Demasiados intentos fallidos. Intenta de nuevo en ' . ceil($wait / 60) . ' minuto(s).', 429);
        }
        $password = (string) ($body['password'] ?? '');
        if ($password === '') {
            fail('Escribe la contraseña.');
        }
        usleep(300000); // retardo constante contra fuerza bruta rápida
        if (!password_matches($password)) {
            attempts_register_failure();
            $left = MAX_ATTEMPTS - (int) (attempts_state()[client_ip()]['count'] ?? 0);
            fail('Contraseña incorrecta.' . ($left > 0 ? " Te quedan $left intento(s)." : ''), 401);
        }
        attempts_clear();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['seen']  = time();
        $_SESSION['since'] = time();
        json_out(['ok' => true, 'csrf' => csrf_token()]);

    /* ---------------------------- Logout ---------------------------- */
    case 'logout':
        session_unset();
        session_destroy();
        json_out(['ok' => true]);

    /* ------------------------ Leer documentación ------------------------ */
    case 'doc.get':
        require_session();
        $path    = doc_path();
        $content = is_readable($path) ? (string) file_get_contents($path) : '';
        json_out([
            'ok'       => true,
            'content'  => $content,
            'mtime'    => is_file($path) ? filemtime($path) : null,
            'stats'    => doc_stats($content),
            'versions' => versions_read(),
        ]);

    /* ----------------------- Guardar documentación ----------------------- */
    case 'doc.save':
        require_session();
        require_csrf($body);

        if (!array_key_exists('content', $body) || !is_string($body['content'])) {
            fail('Falta el contenido a guardar.');
        }
        $content = str_replace("\r\n", "\n", $body['content']);
        if (trim($content) === '') {
            fail('La documentación no puede quedar vacía: la IA se quedaría sin contexto.');
        }
        if (strlen($content) > MAX_DOC_BYTES) {
            fail('El documento supera el límite de 2 MB.', 413);
        }

        // Bloqueo optimista: si el archivo cambió desde que se abrió el editor.
        $path      = doc_path();
        $realMtime = is_file($path) ? filemtime($path) : null;
        $seenMtime = isset($body['mtime']) ? (int) $body['mtime'] : null;
        if (empty($body['force']) && $realMtime !== null && $seenMtime !== null && $seenMtime !== $realMtime) {
            fail('Otra persona guardó cambios mientras editabas. Recarga para no perder su trabajo.', 409);
        }

        $note     = (string) ($body['note'] ?? '');
        $snapshot = versions_snapshot($note !== '' ? $note : 'Respaldo automático antes de guardar');
        atomic_write($path, $content);
        clearstatcache(true, $path);

        json_out([
            'ok'       => true,
            'mtime'    => filemtime($path),
            'stats'    => doc_stats($content),
            'versions' => versions_read(),
            'backup'   => $snapshot,
        ]);

    /* -------------------------- Ver una versión -------------------------- */
    case 'version.get':
        require_session();
        $path    = version_file_path((string) ($body['file'] ?? ''));
        $content = (string) file_get_contents($path);
        json_out(['ok' => true, 'content' => $content, 'stats' => doc_stats($content)]);

    /* ------------------------ Restaurar una versión ---------------------- */
    case 'version.restore':
        require_session();
        require_csrf($body);
        $name    = (string) ($body['file'] ?? '');
        $path    = version_file_path($name);
        $content = (string) file_get_contents($path);

        // El estado actual también se respalda: restaurar nunca destruye nada.
        versions_snapshot('Respaldo automático antes de restaurar ' . basename($name));
        atomic_write(doc_path(), $content);
        clearstatcache(true, doc_path());

        json_out([
            'ok'       => true,
            'content'  => $content,
            'mtime'    => filemtime(doc_path()),
            'stats'    => doc_stats($content),
            'versions' => versions_read(),
        ]);

    /* ----------------------- Utilidad: generar hash ---------------------- */
    // Permite rotar la contraseña sin acceso a la línea de comandos.
    case 'password.hash':
        require_session();
        require_csrf($body);
        $new = (string) ($body['password'] ?? '');
        if (u_strlen($new) < 10) {
            fail('Usa una contraseña de al menos 10 caracteres.');
        }
        json_out([
            'ok'   => true,
            'hash' => password_hash($new, PASSWORD_BCRYPT),
        ]);

    default:
        fail('Acción no reconocida.', 404);
}
