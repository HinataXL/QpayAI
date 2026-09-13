<?php
/**
 * ============================================================================
 *  Límite de peticiones por IP
 * ----------------------------------------------------------------------------
 *  Protege los endpoints que consumen servicios de pago (Gemini, Zoho) contra
 *  abuso y facturas infladas. Ventana deslizante con varios tramos a la vez
 *  (por minuto, por hora, por día) guardada en SQLite.
 *
 *  Por qué SQLite y no un JSON: el conteo tiene que ser atómico. Con un JSON
 *  leído y escrito en dos pasos, una ráfaga de peticiones simultáneas —que es
 *  justo el ataque del que nos defendemos— pierde incrementos y el límite se
 *  filtra. Aquí cada comprobación corre dentro de una transacción.
 * ============================================================================
 */

// Es una librería: pedirla directamente por HTTP no debe hacer nada.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/* ------------------------------------------------------------ Configuración */

/** Lee una variable del entorno o, si no está, del .env del proyecto. */
function rl_env(string $key, string $default = ''): string
{
    static $file = null;
    if ($file === null) {
        $path = dirname(__DIR__) . '/.env';
        $file = is_readable($path) ? (parse_ini_file($path) ?: []) : [];
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return isset($file[$key]) && $file[$key] !== '' ? (string) $file[$key] : $default;
}

/**
 * Proxies cuyos encabezados de IP se aceptan. Por defecto, redes privadas y
 * loopback: ahí viven `cloudflared` (red de Docker) y el balanceador de
 * plataformas como Render. Un cliente que llega directo desde internet trae
 * una IP pública, así que sus encabezados se ignoran.
 */
function rl_trusted_proxies(): array
{
    $raw = rl_env(
        'TRUSTED_PROXIES',
        '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7'
    );
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

/* ------------------------------------------------------------------ Red */

/** Convierte `::ffff:1.2.3.4` en `1.2.3.4` para que IPv4 se compare como IPv4. */
function rl_normalize_ip(string $ip): ?string
{
    $bin = @inet_pton(trim($ip));
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $bin = substr($bin, 12);
    }
    return inet_ntop($bin) ?: null;
}

function rl_ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];

    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }

    $max  = strlen($ipBin) * 8;
    $bits = $bits === null ? $max : (int) $bits;
    if ($bits < 0 || $bits > $max) {
        return false;
    }

    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

function rl_is_trusted_proxy(string $ip): bool
{
    foreach (rl_trusted_proxies() as $cidr) {
        if (rl_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * IP real del cliente.
 *
 * Los encabezados de reenvío los escribe quien quiera: si se creyeran siempre,
 * bastaría con mandar `X-Forwarded-For: <ip aleatoria>` en cada petición para
 * no toparse nunca con el límite. Por eso solo se leen cuando la conexión
 * llega desde un proxy de confianza, y solo el encabezado configurado:
 *
 *   TRUSTED_PROXY_HEADER=CF-Connecting-IP   (por defecto; Cloudflare Tunnel)
 *   TRUSTED_PROXY_HEADER=X-Forwarded-For    (Render, nginx, otros balanceadores)
 *   TRUSTED_PROXY_HEADER=none               (servidor expuesto directo)
 */
function rl_client_ip(): string
{
    $remote = rl_normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? '0.0.0.0';
    $header = trim(rl_env('TRUSTED_PROXY_HEADER', 'CF-Connecting-IP'));

    if ($header === '' || strtolower($header) === 'none' || !rl_is_trusted_proxy($remote)) {
        return $remote;
    }

    $raw = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '');
    if ($raw === '') {
        return $remote;
    }

    if (strtolower($header) !== 'x-forwarded-for') {
        return rl_normalize_ip($raw) ?? $remote;
    }

    // X-Forwarded-For crece hacia la derecha: cada proxy añade a quien le habló.
    // Lo de la izquierda lo pudo inventar el cliente, así que se recorre desde
    // la derecha y se toma la primera IP que no sea uno de nuestros proxies.
    $chain = array_values(array_filter(array_map('rl_normalize_ip', explode(',', $raw))));
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (!rl_is_trusted_proxy($chain[$i])) {
            return $chain[$i];
        }
    }
    return $chain[0] ?? $remote;
}

/**
 * Clave con la que se cuenta. En IPv6 cada cliente recibe normalmente un /64
 * completo y puede estrenar dirección en cada petición, así que se agrupa por
 * ese prefijo; contar la dirección exacta dejaría el límite sin efecto.
 */
function rl_client_key(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return $ip;
}

/* ------------------------------------------------------------ Almacenamiento */

function rl_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Fuera de la raíz web por defecto: el archivo guarda IPs de visitantes.
    $path = rl_env('RATE_LIMIT_DB', rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'qpayai-rate-limit.sqlite');

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,   // espera por el candado en vez de fallar al instante
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hits (
            bucket TEXT    NOT NULL,
            client TEXT    NOT NULL,
            ts     INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS hits_lookup ON hits (bucket, client, ts)');

    return $pdo;
}

/* ------------------------------------------------------------------ Límite */

/**
 * Registra una petición y dice si cabe dentro de los límites.
 *
 * @param string $bucket Nombre del recurso protegido (p. ej. "tappy").
 * @param array<string, array{0:int,1:int}> $limits Tramos como
 *        ['minuto' => [8, 60], 'hora' => [40, 3600]]. Un máximo 0 lo desactiva.
 * @return array{allowed:bool, retry_after:int, window:?string, remaining:?int, client:string}
 *
 * Las peticiones rechazadas no se anotan: quien reintenta mientras espera no
 * alarga su propio bloqueo. Si el almacén falla se deja pasar la petición y se
 * registra el error — un límite roto no debe tumbar el soporte.
 */
function rate_limit_hit(string $bucket, array $limits): array
{
    $ip     = rl_client_ip();
    $client = rl_client_key($ip);
    $result = ['allowed' => true, 'retry_after' => 0, 'window' => null, 'remaining' => null, 'client' => $client];

    $limits = array_filter($limits, static fn ($l) => (int) $l[0] > 0 && (int) $l[1] > 0);
    if (!$limits) {
        return $result;
    }

    try {
        $db  = rl_db();
        $now = time();

        // IMMEDIATE toma el candado de escritura antes de contar: dos peticiones
        // simultáneas no pueden ver ambas "queda 1" y pasar las dos.
        $db->exec('BEGIN IMMEDIATE');

        $count = $db->prepare('SELECT COUNT(*) FROM hits WHERE bucket = ? AND client = ? AND ts > ?');
        $nth   = $db->prepare(
            'SELECT ts FROM hits WHERE bucket = ? AND client = ? AND ts > ? ORDER BY ts ASC LIMIT 1 OFFSET ?'
        );

        $remaining = PHP_INT_MAX;
        foreach ($limits as $name => [$max, $window]) {
            $max    = (int) $max;
            $window = (int) $window;

            $count->execute([$bucket, $client, $now - $window]);
            $used = (int) $count->fetchColumn();

            if ($used >= $max) {
                // Hay que esperar a que salga de la ventana la petición que
                // deja sitio para una más: la que ocupa la posición used-max.
                $nth->execute([$bucket, $client, $now - $window, $used - $max]);
                $oldest = (int) $nth->fetchColumn();
                $wait   = max(1, $oldest + $window - $now);

                if ($wait > $result['retry_after']) {
                    $result['retry_after'] = $wait;
                    $result['window']      = (string) $name;
                }
                $result['allowed'] = false;
            }
            $remaining = min($remaining, $max - $used - 1);
        }

        if ($result['allowed']) {
            $db->prepare('INSERT INTO hits (bucket, client, ts) VALUES (?, ?, ?)')
                ->execute([$bucket, $client, $now]);
            $result['remaining'] = max(0, $remaining);
        } else {
            $result['remaining'] = 0;
        }

        // Limpieza ocasional de lo que ya salió de la ventana más larga.
        if (random_int(1, 50) === 1) {
            $longest = max(array_map(static fn ($l) => (int) $l[1], $limits));
            $db->prepare('DELETE FROM hits WHERE ts <= ?')->execute([$now - $longest]);
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        error_log('[rate_limit] almacén no disponible, se permite la petición: ' . $e->getMessage());
        return ['allowed' => true, 'retry_after' => 0, 'window' => null, 'remaining' => null, 'client' => $client];
    }

    return $result;
}

/** "45 segundos", "3 minutos", "2 horas". */
function rate_limit_human(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds === 1 ? '1 segundo' : "$seconds segundos";
    }
    if ($seconds < 3600) {
        $m = (int) ceil($seconds / 60);
        return $m === 1 ? '1 minuto' : "$m minutos";
    }
    $h = (int) ceil($seconds / 3600);
    return $h === 1 ? '1 hora' : "$h horas";
}
