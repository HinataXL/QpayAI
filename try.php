<?php
/**
 * ============================================================================
 *  QPayPro Docs · Proxy de pruebas ("Probar" en cada endpoint)
 * ----------------------------------------------------------------------------
 *  El navegador no puede llamar a los dominios de QPayPro directamente: no
 *  envían cabeceras CORS. Este proxy reenvía la petición desde el servidor y
 *  devuelve el resultado crudo (estado, cabeceras, cuerpo, tiempo).
 *
 *  NO es un proxy abierto: solo acepta los dominios de QPayPro de la lista
 *  blanca y rechaza cualquier otro destino.
 * ============================================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

/* ---------------------------------------------------------------- Ajustes */

/** Únicos destinos permitidos. Todo lo demás se rechaza. */
const ALLOWED_HOSTS = [
    'api-sandboxpayments.qpaypro.com',
    'sandboxpayments.qpaypro.com',
    'api-payments.qpaypro.com',
    'payments.qpaypro.com',
    'devbilling.qpaypro.com',
    'billing.qpaypro.com',
    'api.qpaypro.com',
];

const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

/** Cabeceras que nunca se reenvían: las controla cURL o rompen la petición. */
const BLOCKED_HEADERS = [
    'host', 'content-length', 'connection', 'transfer-encoding',
    'expect', 'upgrade', 'proxy-authorization', 'cookie',
];

const MAX_BODY_BYTES     = 262144;  // 256 KB de cuerpo saliente
const MAX_RESPONSE_BYTES = 1048576; // 1 MB de respuesta devuelta al navegador
const TIMEOUT_SECONDS    = 45;
const RATE_LIMIT_MAX     = 60;      // peticiones por ventana e IP
const RATE_LIMIT_WINDOW  = 300;     // 5 minutos
const RATE_FILE          = '.try_rate.json';

/* ---------------------------------------------------------------- Salida */

function out(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function deny(string $message, int $code = 400): never
{
    out(['ok' => false, 'error' => $message], $code);
}

/* ------------------------------------------------------------ Rate limit */

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

/** Ventana deslizante simple por IP, para que el proxy no sirva de relay masivo. */
function rate_limit_check(): void
{
    $file  = __DIR__ . '/' . RATE_FILE;
    $now   = time();
    $state = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];
    if (!is_array($state)) {
        $state = [];
    }

    $ip  = client_ip();
    $row = $state[$ip] ?? ['start' => $now, 'count' => 0];

    if ($now - (int) $row['start'] > RATE_LIMIT_WINDOW) {
        $row = ['start' => $now, 'count' => 0];
    }
    $row['count'] = (int) $row['count'] + 1;
    $state[$ip]   = $row;

    foreach ($state as $key => $value) {
        if ($now - (int) ($value['start'] ?? 0) > RATE_LIMIT_WINDOW * 3) {
            unset($state[$key]);
        }
    }
    @file_put_contents($file, json_encode($state), LOCK_EX);

    if ($row['count'] > RATE_LIMIT_MAX) {
        $wait = RATE_LIMIT_WINDOW - ($now - (int) $row['start']);
        deny('Demasiadas pruebas seguidas. Espera ' . max(1, (int) ceil($wait / 60)) . ' minuto(s).', 429);
    }
}

/* ------------------------------------------------------------ Validación */

/** Verifica que la URL apunte a un host permitido de QPayPro por HTTPS. */
function validate_url(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        deny('La URL no es válida.');
    }
    if (($parts['scheme'] ?? '') !== 'https') {
        deny('Solo se permiten URL https.');
    }
    if (isset($parts['port']) && !in_array((int) $parts['port'], [443], true)) {
        deny('Puerto no permitido.');
    }

    $host = strtolower($parts['host']);
    if (!in_array($host, ALLOWED_HOSTS, true)) {
        deny('Destino no permitido. Este proxy solo llama a los dominios de QPayPro: ' .
            implode(', ', ALLOWED_HOSTS) . '.', 403);
    }

    // Defensa en profundidad: el host no debe resolver a una IP interna.
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    )) {
        deny('El destino resuelve a una dirección interna.', 403);
    }

    return $url;
}

/** Normaliza las cabeceras enviadas por el navegador a formato cURL. */
function build_headers(array $raw): array
{
    $headers = [];
    foreach ($raw as $name => $value) {
        if (!is_string($name) || !is_scalar($value)) {
            continue;
        }
        $clean = trim($name);
        // Sin saltos de línea: evita inyección de cabeceras.
        if ($clean === '' || preg_match('/[^A-Za-z0-9\-_]/', $clean)) {
            continue;
        }
        if (in_array(strtolower($clean), BLOCKED_HEADERS, true)) {
            continue;
        }
        $val = trim(str_replace(["\r", "\n"], '', (string) $value));
        if ($val === '') {
            continue;
        }
        $headers[] = $clean . ': ' . $val;
    }
    return $headers;
}

/* ------------------------------------------------------------- Enrutador */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    deny('Solo se aceptan peticiones POST.', 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    deny('Cuerpo de la petición inválido (se esperaba JSON).');
}

// Modo "info": el frontend consulta qué dominios están permitidos.
if (($input['action'] ?? '') === 'hosts') {
    out(['ok' => true, 'hosts' => ALLOWED_HOSTS]);
}

rate_limit_check();

$method = strtoupper(trim((string) ($input['method'] ?? 'POST')));
if (!in_array($method, ALLOWED_METHODS, true)) {
    deny('Método HTTP no permitido.');
}

$url  = validate_url(trim((string) ($input['url'] ?? '')));
$body = (string) ($input['body'] ?? '');

if (strlen($body) > MAX_BODY_BYTES) {
    deny('El cuerpo de la petición supera el límite de 256 KB.', 413);
}

$headers = build_headers(is_array($input['headers'] ?? null) ? $input['headers'] : []);

/* ------------------------------------------------------------- Reenvío */

/**
 * Nunca se desactiva la verificación TLS. Pero hay entornos (PHP en Windows,
 * imágenes mínimas) donde cURL no trae configurado ningún almacén de
 * certificados y toda petición fallaría. Aquí se le indica uno:
 *   1. Si php.ini ya define uno, se respeta esa configuración.
 *   2. Si existe un bundle en una ruta conocida (Linux/Docker), se usa.
 *   3. Si no, se usa el almacén nativo del sistema operativo (Windows/macOS).
 */
function ca_options(): array
{
    if (trim((string) ini_get('curl.cainfo')) !== '' || trim((string) ini_get('openssl.cafile')) !== '') {
        return [];
    }

    $candidates = [
        __DIR__ . '/cacert.pem',                 // bundle propio, si se coloca uno
        '/etc/ssl/certs/ca-certificates.crt',    // Debian/Ubuntu (imagen php:8.2-apache)
        '/etc/pki/tls/certs/ca-bundle.crt',      // RHEL/CentOS
        '/etc/ssl/cert.pem',                     // Alpine/macOS
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return [CURLOPT_CAINFO => $path];
        }
    }

    if (defined('CURLSSLOPT_NATIVE_CA')) {
        return [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
    }

    return [];
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_FOLLOWLOCATION => false,   // sin redirecciones: evita saltar la lista blanca
    CURLOPT_TIMEOUT        => TIMEOUT_SECONDS,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    CURLOPT_USERAGENT      => 'QPayPro-Docs-TryIt/1.0',
] + ca_options());

// Incluso GET y DELETE llevan cuerpo en varios endpoints de QPayPro.
if ($body !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$started  = microtime(true);
$raw      = curl_exec($ch);
$elapsed  = (int) round((microtime(true) - $started) * 1000);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$headSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($errno !== 0 || $raw === false) {
    $friendly = match ($errno) {
        CURLE_OPERATION_TIMEDOUT => 'El servidor de QPayPro no respondió dentro de ' . TIMEOUT_SECONDS . ' segundos.',
        CURLE_COULDNT_RESOLVE_HOST => 'No se pudo resolver el dominio de QPayPro.',
        CURLE_SSL_CACERT, CURLE_PEER_FAILED_VERIFICATION =>
            'Falló la verificación del certificado TLS. Suele indicar que al servidor le falta el ' .
            'paquete de certificados raíz (ca-certificates) o la opción curl.cainfo en php.ini.',
        default => 'Error de conexión: ' . $error,
    };
    out(['ok' => false, 'error' => $friendly, 'ms' => $elapsed], 502);
}

$rawHeaders = substr((string) $raw, 0, $headSize);
$rawBody    = substr((string) $raw, $headSize);
$fullSize   = strlen($rawBody);
$truncated  = false;

if ($fullSize > MAX_RESPONSE_BYTES) {
    $rawBody   = substr($rawBody, 0, MAX_RESPONSE_BYTES);
    $truncated = true;
}

// Solo el último bloque de cabeceras (puede haber "100 Continue" antes).
$blocks      = preg_split("/\r?\n\r?\n/", trim($rawHeaders));
$lastBlock   = is_array($blocks) ? (string) end($blocks) : '';
$respHeaders = [];
foreach (preg_split("/\r?\n/", $lastBlock) ?: [] as $line) {
    if (str_contains($line, ':')) {
        [$name, $value] = explode(':', $line, 2);
        $respHeaders[trim($name)] = trim($value);
    }
}

out([
    'ok'        => true,
    'status'    => $status,
    'ms'        => $elapsed,
    'size'      => $fullSize,
    'truncated' => $truncated,
    'headers'   => $respHeaders,
    'body'      => $rawBody,
]);
