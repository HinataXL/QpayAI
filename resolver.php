<?php
error_reporting(E_ALL);
// Los errores van al log, nunca a la respuesta: es un endpoint JSON, y un aviso de
// PHP en el cuerpo rompe el chat y expone rutas internas del servidor al visitante.
// En local siguen visibles en la terminal de `php -S`.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(120);

// 1. Configuración Inicial
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1.1 Límite de peticiones por IP
// Va antes de todo lo demás: cada consulta que pasa de aquí cuesta una llamada
// a Gemini y puede terminar en un ticket de Zoho. Los tramos se ajustan por
// entorno (0 desactiva uno); ver LIMITES.md.
require_once __DIR__ . '/lib/rate_limit.php';

$limite = rate_limit_hit('tappy', [
    'minuto' => [(int) rl_env('RATE_LIMIT_PER_MINUTE', '8'), 60],
    'hora'   => [(int) rl_env('RATE_LIMIT_PER_HOUR', '40'), 3600],
    'dia'    => [(int) rl_env('RATE_LIMIT_PER_DAY', '150'), 86400],
]);

if ($limite['remaining'] !== null) {
    header('X-RateLimit-Remaining: ' . $limite['remaining']);
}

if (!$limite['allowed']) {
    $espera = rate_limit_human($limite['retry_after']);
    $mensaje = match ($limite['window']) {
        'dia'   => "Alcanzaste el límite diario de consultas a Tappy. Podrás volver a escribir en $espera.",
        'hora'  => "Alcanzaste el límite de consultas por hora. Podrás volver a escribir en $espera.",
        default => "Estás enviando consultas muy seguido. Espera $espera y vuelve a intentarlo.",
    };

    http_response_code(429);
    header('Retry-After: ' . $limite['retry_after']);
    echo json_encode([
        "error"        => $mensaje,
        "rate_limited" => true,
        "retry_after"  => $limite['retry_after'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$env_path = __DIR__ . '/.env';
$env = file_exists($env_path) ? parse_ini_file($env_path) : [];

$gemini_api_key = getenv('GEMINI_API_KEY') ?: ($env['GEMINI_API_KEY'] ?? '');
$gemini_model = getenv('GEMINI_MODEL') ?: ($env['GEMINI_MODEL'] ?? 'gemini-1.5-flash');
$zoho_org_id = getenv('ZOHO_DESK_ORG_ID') ?: ($env['ZOHO_DESK_ORG_ID'] ?? '');
$zoho_department_id = getenv('ZOHO_DESK_DEPARTMENT_ID') ?: ($env['ZOHO_DESK_DEPARTMENT_ID'] ?? '');
$zoho_client_id = getenv('ZOHO_CLIENT_ID') ?: ($env['ZOHO_CLIENT_ID'] ?? '');
$zoho_client_secret = getenv('ZOHO_CLIENT_SECRET') ?: ($env['ZOHO_CLIENT_SECRET'] ?? '');
$zoho_refresh_token = getenv('ZOHO_REFRESH_TOKEN') ?: ($env['ZOHO_REFRESH_TOKEN'] ?? '');

if (!$gemini_api_key) {
    echo json_encode(["error" => "No se encontró GEMINI_API_KEY en el entorno ni en un archivo .env."]);
    exit;
}

// 1.2 Tamaño de la consulta
// El límite por IP acota cuántas consultas llegan; esto acota cuánto cuesta cada
// una. Ver LIMITES.md.
require_once __DIR__ . '/lib/tappy_history.php';
require_once __DIR__ . '/lib/tls.php';

$max_body_bytes = (int) rl_env('TAPPY_MAX_BODY_BYTES', '262144');

// Si el Content-Length ya declara más del máximo no se lee nada. Si no, se lee
// como mucho un byte de más: basta para detectar un cuerpo que mintió en la
// cabecera sin cargar en memoria algo arbitrariamente grande.
$input_json = '';
$demasiado_grande = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max_body_bytes;
if (!$demasiado_grande) {
    $input_json = (string) file_get_contents('php://input', false, null, 0, $max_body_bytes + 1);
    $demasiado_grande = strlen($input_json) > $max_body_bytes;
}

if ($demasiado_grande) {
    http_response_code(413);
    echo json_encode([
        "error"     => "La consulta es demasiado grande. Inicia una conversación nueva o envía un mensaje más corto.",
        "too_large" => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input_data = json_decode($input_json, true);

$saneado = tappy_sanitize_history(is_array($input_data) ? ($input_data['history'] ?? null) : null, [
    'message_chars' => (int) rl_env('TAPPY_MAX_MESSAGE_CHARS', '10000'),
    'history_chars' => (int) rl_env('TAPPY_MAX_HISTORY_CHARS', '40000'),
    'history_turns' => (int) rl_env('TAPPY_MAX_HISTORY_TURNS', '20'),
]);

if (!$saneado['ok']) {
    http_response_code($saneado['code']);
    echo json_encode([
        "error"     => $saneado['error'],
        "too_large" => $saneado['code'] === 413,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$history = $saneado['history'];

// 1.3 Datos sensibles
// Llaves reales o tarjetas no deben llegar a Gemini ni quedar en un ticket de Zoho.
// Se revisan todos los mensajes del usuario, no solo el último: el historial lo arma
// el navegador. chat.html hace la misma revisión antes de enviar; esta es la barrera
// que no depende del cliente.
require_once __DIR__ . '/lib/sensitive_data.php';
require_once __DIR__ . '/lib/zoho_ticket.php';

$sensibles = [];
foreach ($history as $turno) {
    if ($turno['role'] === 'user') {
        $sensibles = array_merge($sensibles, sensitive_scan($turno['parts'][0]['text']));
    }
}
if ($sensibles) {
    http_response_code(422);
    echo json_encode([
        "error"          => sensitive_message($sensibles),
        "sensitive_data" => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Extraer el último mensaje del usuario para el análisis RAG
$ultimo_mensaje_usuario = '';
for ($i = count($history) - 1; $i >= 0; $i--) {
    if ($history[$i]['role'] === 'user') {
        $ultimo_mensaje_usuario = $history[$i]['parts'][0]['text'] ?? '';
        break;
    }
}

// 2. RAG - Base de Conocimiento y Recuperación
$docs_path = __DIR__ . '/qpaypro_docs.txt';
$contexto_recuperado = '';

if (file_exists($docs_path)) {
    $contexto_recuperado = file_get_contents($docs_path);
} else {
    $contexto_recuperado = "No hay documentación local disponible. Responde usando tu conocimiento general.";
}

// 3. Prompt de Sistema (Augmentation)
// La documentación manda en todo lo específico de QPayPro; fuera de eso el
// asistente responde como el ingeniero de integraciones que es.
$system_prompt = <<<PROMPT
Eres el Ingeniero de Soporte Senior de QPayPro. Acompañas a desarrolladores que
están integrando la pasarela y tu meta es desbloquearlos en el momento.

DOCUMENTACIÓN OFICIAL DE QPAYPRO:
$contexto_recuperado

CÓMO USAR TU CONOCIMIENTO

1. La documentación de arriba es la ÚNICA fuente de verdad para todo lo que es
   propio de QPayPro: URLs y endpoints, nombres y valores de parámetros,
   credenciales, códigos y mensajes de error, flujos y reglas del negocio.
   NUNCA inventes ni supongas un endpoint, un parámetro o un valor que no
   aparezca ahí; un dato inventado le rompe la integración al comercio.

2. Para todo lo demás SÍ usas tu criterio y experiencia de ingeniero: HTTP,
   JSON, cURL, PHP, JavaScript, Python, depuración de errores, CORS, TLS,
   webhooks, manejo de errores y reintentos, seguridad, conceptos generales de
   pagos en línea (3D-Secure, tokenización, autorización y captura) y buenas
   prácticas de integración. Explica, razona, propón alternativas y escribe el
   código que haga falta.

3. Cuando lo que respondas venga de tu conocimiento general y no esté
   respaldado por la documentación, dilo con naturalidad en la misma frase
   (por ejemplo: "esto no está en la documentación oficial, pero por lo general
   ..."). Así el desarrollador sabe qué está garantizado y qué no.
   Lo mismo con lo que la documentación marca como "comportamiento observado":
   úsalo, pero preséntalo como algo visto en pruebas, nunca como documentación
   oficial de QPayPro.

4. Si te falta un dato específico de QPayPro, dilo con claridad en vez de
   rellenarlo. Aun así ayuda con lo que sí puedes: explica el concepto, muestra
   cómo depurarlo, señala qué revisar o qué preguntar.

5. Haz preguntas de vuelta cuando te falte contexto para dar una buena
   respuesta (qué error exacto reciben, qué endpoint usan, qué enviaron).

CUÁNDO PASAR A UN HUMANO

6. Nunca abras ni ofrezcas un ticket sin contexto. Si el usuario pide un ticket,
   soporte o hablar con una persona y todavía NO explicó su problema (qué
   endpoint o servicio usa, qué envió, qué error o respuesta recibe), pon
   "pide_ticket_sin_contexto": true y NO pidas su correo todavía. En cualquier
   otro caso "pide_ticket_sin_contexto" es false.

7. Con el problema ya explicado, ofrece escalar a soporte humano cuando: el
   usuario lo pida, el asunto sea de su cuenta o comercio (activación,
   credenciales propias, cobros, liquidaciones, configuración de su pasarela),
   haya que revisar una transacción concreta, o falte información que solo
   QPayPro puede dar. En esos casos pide su correo electrónico (obligatorio) y
   el nombre de su comercio, y pon "solicita_contacto": true. En cualquier otra
   respuesta "solicita_contacto" es false.
   No escales por costumbre: si puedes resolverlo tú, resuélvelo.

8. El correo electrónico es OBLIGATORIO para abrir un ticket. Solo cuando el
   usuario ya explicó el problema Y ya escribió un correo válido Y el nombre de
   su comercio, pon "escalar_a_humano": true, colócalos en "correo_cliente" y
   "nombre_comercio", y confirma en 'diagnostico' que estás creando el ticket.
   Nunca inventes ni supongas un correo. Mientras falte algo, "escalar_a_humano"
   es false.

FORMATO

9. Responde en español, directo y sin rodeos. Usa las credenciales de sandbox de
   la documentación en los ejemplos, nunca inventes llaves reales.

10. Devuelve ESTRICTAMENTE un JSON válido con esta estructura exacta, sin texto
   adicional fuera del JSON:
{
  "diagnostico": "Tu respuesta para el usuario.",
  "codigo_corregido": "El bloque completo de código corregido o de ejemplo. Vacío si no aplica.",
  "escalar_a_humano": true o false,
  "pide_ticket_sin_contexto": true si pide un ticket o soporte sin haber explicado su problema, si no false,
  "solicita_contacto": true si en esta respuesta pides correo y comercio para escalar, si no false,
  "correo_cliente": "El correo que dio el usuario, o vacío.",
  "nombre_comercio": "El nombre del comercio que dio el usuario, o vacío."
}
PROMPT;

// 4. Llamada a la API de Gemini (cURL)
$gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/{$gemini_model}:generateContent";

$data = [
    "systemInstruction" => [
        "parts" => [
            ["text" => $system_prompt]
        ]
    ],
    "contents" => $history,
    "generationConfig" => [
        // 0.1 producía respuestas casi calcadas de la documentación; algo más de
        // temperatura le da margen para explicar y proponer sin perder precisión.
        "temperature" => 0.35,
        "responseMimeType" => "application/json",
        // Los tokens de salida se facturan más caros que los de entrada. Una
        // respuesta normal (diagnóstico + código) usa una fracción de esto.
        "maxOutputTokens" => (int) rl_env('GEMINI_MAX_OUTPUT_TOKENS', '4096')
    ]
];

$ch = curl_init($gemini_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($data),
    CURLOPT_HTTPHEADER     => [
        "x-goog-api-key: " . $gemini_api_key,
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT        => 120,
] + tls_curl_options());

$response = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);

if ($curl_errno !== 0) {
    error_log("[resolver] Gemini cURL $curl_errno: $curl_error");
    echo json_encode([
        "error" => tls_is_cert_error($curl_errno) ? tls_cert_error_message() : "Error de conexión: " . $curl_error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$gemini_result = json_decode($response, true);

if (isset($gemini_result['error'])) {
    echo json_encode(["error" => "Error API: " . $gemini_result['error']['message']]);
    exit;
}

// Si la respuesta se cortó por el tope de salida, el JSON viene truncado y no
// se puede interpretar: mejor decirlo claro que fallar con un error de formato.
if (($gemini_result['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') {
    echo json_encode([
        "error" => "La respuesta de Tappy salió demasiado larga y se cortó. Intenta con una pregunta más acotada."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ia_reply = $gemini_result['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($ia_reply)) {
    echo json_encode(["error" => "La IA devolvió una respuesta vacía."]);
    exit;
}

// 5. Lógica de "Human in the loop" y Salida al Frontend
try {
    $parsed_json = json_decode($ia_reply, true);
    if (!$parsed_json || !isset($parsed_json['diagnostico'])) {
        throw new Exception("El formato devuelto por la IA no fue el esperado JSON estricto.");
    }
    
    $escalar = isset($parsed_json['escalar_a_humano']) && $parsed_json['escalar_a_humano'] === true;

    // 5.1 Requisitos del ticket, aplicados aquí y no solo en el prompt: la IA puede
    // equivocarse o dejarse convencer, estas reglas no. Primero el contexto, luego
    // el correo; hasta que se cumplan ambos no se abre nada.
    $comercio_ia = (string) ($parsed_json['nombre_comercio'] ?? '');
    $con_contexto = tappy_ticket_has_context($history, $comercio_ia);
    $correo_valido = tappy_ticket_email($history, (string) ($parsed_json['correo_cliente'] ?? ''));

    $pide_sin_contexto = ($parsed_json['pide_ticket_sin_contexto'] ?? false) === true;
    $quiere_ticket = $escalar || (($parsed_json['solicita_contacto'] ?? false) === true);

    if ($pide_sin_contexto || ($quiere_ticket && !$con_contexto)) {
        $escalar = false;
        $parsed_json['diagnostico'] = TAPPY_MSG_NEED_CONTEXT;
        $parsed_json['codigo_corregido'] = '';
        $parsed_json['requiere_contexto'] = true;      // el chat muestra a Tappy ansioso
        $parsed_json['solicita_contacto'] = false;
    } elseif ($escalar && $correo_valido === null) {
        $escalar = false;
        $parsed_json['diagnostico'] = TAPPY_MSG_NEED_EMAIL;
        $parsed_json['solicita_contacto'] = true;
    }
    $parsed_json['escalar_a_humano'] = $escalar;
    unset($parsed_json['pide_ticket_sin_contexto']);

    if ($escalar && $zoho_refresh_token && $zoho_client_id && $zoho_client_secret && $zoho_org_id && $zoho_department_id) {
        // 1. Obtener Access Token mediante Refresh Token
        $auth_url = "https://accounts.zoho.com/oauth/v2/token";
        $auth_data = [
            "refresh_token" => $zoho_refresh_token,
            "client_id" => $zoho_client_id,
            "client_secret" => $zoho_client_secret,
            "grant_type" => "refresh_token"
        ];
        
        $ch_auth = curl_init($auth_url);
        curl_setopt($ch_auth, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_auth, CURLOPT_POST, true);
        curl_setopt($ch_auth, CURLOPT_POSTFIELDS, http_build_query($auth_data));
        curl_setopt($ch_auth, CURLOPT_TIMEOUT, 30);
        curl_setopt_array($ch_auth, tls_curl_options());

        $auth_response = curl_exec($ch_auth);
        // Sin esto, un fallo de red o de certificado se vería solo como
        // "error de autenticación con Zoho" y no habría forma de distinguirlo.
        if (curl_errno($ch_auth) !== 0) {
            error_log('[resolver] Zoho OAuth cURL ' . curl_errno($ch_auth) . ': ' . curl_error($ch_auth));
        }
        
        $auth_result = json_decode($auth_response, true);
        $access_token = $auth_result['access_token'] ?? null;
        
        if ($access_token) {
            // 2. Crear ticket en Zoho Desk
            $zoho_url = "https://desk.zoho.com/api/v1/tickets";
            
            // Ya validado arriba: sin un correo real de la persona no se llega aquí.
            $correo_cliente = $correo_valido;
            $nombre_comercio = !empty($parsed_json['nombre_comercio']) ? $parsed_json['nombre_comercio'] : "No especificado";

            $ticket_data = [
                "subject" => tappy_ticket_subject($nombre_comercio),
                "departmentId" => $zoho_department_id,
                "contact" => [
                    "lastName" => $nombre_comercio,
                    "email" => $correo_cliente
                ],
                "description" => tappy_ticket_description($history)
            ];

            $zc = curl_init($zoho_url);
            curl_setopt($zc, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($zc, CURLOPT_POST, true);
            curl_setopt($zc, CURLOPT_POSTFIELDS, json_encode($ticket_data));
            curl_setopt($zc, CURLOPT_HTTPHEADER, [
                "Authorization: Zoho-oauthtoken " . $access_token,
                "orgId: " . $zoho_org_id,
                "Content-Type: application/json"
            ]);
            curl_setopt($zc, CURLOPT_TIMEOUT, 30);
            curl_setopt_array($zc, tls_curl_options());

            $zoho_response = curl_exec($zc);
            if (curl_errno($zc) !== 0) {
                error_log('[resolver] Zoho Desk cURL ' . curl_errno($zc) . ': ' . curl_error($zc));
            }
            // Sin curl_close(): no hace nada desde PHP 8.0 y en 8.5 emite un aviso de
            // obsolescencia en cada llamada.
            
            $zoho_result = json_decode($zoho_response, true);
            $ticket_id = $zoho_result['ticketNumber'] ?? 'N/A';
            
            // Adjuntamos el aviso del ticket a la respuesta para el usuario
            $parsed_json['diagnostico'] .= "\n\n🎫 He levantado el ticket de soporte #" . $ticket_id . " para que un agente revise tu caso. Se pondrán en contacto contigo a la brevedad.";
        } else {
            $parsed_json['diagnostico'] .= "\n\n⚠️ Intenté levantar un ticket de soporte, pero hubo un error de autenticación con Zoho.";
        }
    }
    
    // Retornamos el JSON directamente para que el chat lo renderice
    echo json_encode([
        "success" => true,
        "reply" => $parsed_json
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error procesando respuesta IA: " . $e->getMessage()]);
}
