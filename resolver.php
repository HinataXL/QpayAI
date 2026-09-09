<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
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

$input_json = file_get_contents('php://input');
$input_data = json_decode($input_json, true);

if (!$input_data || !isset($input_data['history'])) {
    echo json_encode(["error" => "El historial (history) es requerido."]);
    exit;
}
$history = $input_data['history'];

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

4. Si te falta un dato específico de QPayPro, dilo con claridad en vez de
   rellenarlo. Aun así ayuda con lo que sí puedes: explica el concepto, muestra
   cómo depurarlo, señala qué revisar o qué preguntar.

5. Haz preguntas de vuelta cuando te falte contexto para dar una buena
   respuesta (qué error exacto reciben, qué endpoint usan, qué enviaron).

CUÁNDO PASAR A UN HUMANO

6. Ofrece escalar a soporte humano cuando: el usuario lo pida, el asunto sea de
   su cuenta o comercio (activación, credenciales propias, cobros, liquidaciones,
   configuración de su pasarela), haya que revisar una transacción concreta, o
   falte información que solo QPayPro puede dar. En esos casos pide su correo
   electrónico y el nombre de su comercio.
   No escales por costumbre: si puedes resolverlo tú, resuélvelo.

7. Solo cuando el usuario YA te dio correo y nombre de comercio, pon
   "escalar_a_humano": true, colócalos en "correo_cliente" y "nombre_comercio",
   y confirma en 'diagnostico' que estás creando el ticket. Mientras falte
   alguno de los dos, "escalar_a_humano" es false.

FORMATO

8. Responde en español, directo y sin rodeos. Usa las credenciales de sandbox de
   la documentación en los ejemplos, nunca inventes llaves reales.

9. Devuelve ESTRICTAMENTE un JSON válido con esta estructura exacta, sin texto
   adicional fuera del JSON:
{
  "diagnostico": "Tu respuesta para el usuario.",
  "codigo_corregido": "El bloque completo de código corregido o de ejemplo. Vacío si no aplica.",
  "escalar_a_humano": true o false,
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
        "responseMimeType" => "application/json"
    ]
];

$ch = curl_init($gemini_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-goog-api-key: " . $gemini_api_key,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$curl_error = curl_error($ch);

if ($curl_error) {
    echo json_encode(["error" => "Error de conexión: " . $curl_error]);
    exit;
}

$gemini_result = json_decode($response, true);

if (isset($gemini_result['error'])) {
    echo json_encode(["error" => "Error API: " . $gemini_result['error']['message']]);
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
        curl_setopt($ch_auth, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_auth, CURLOPT_TIMEOUT, 30);
        
        $auth_response = curl_exec($ch_auth);
        curl_close($ch_auth);
        
        $auth_result = json_decode($auth_response, true);
        $access_token = $auth_result['access_token'] ?? null;
        
        if ($access_token) {
            // 2. Crear ticket en Zoho Desk
            $zoho_url = "https://desk.zoho.com/api/v1/tickets";
            
            // Convertimos el historial en HTML para la descripción del ticket
            $historial_texto = "<h3>Historial del chat:</h3><hr/>";
            foreach ($history as $msg) {
                $role = $msg['role'] === 'user' ? 'Usuario' : 'Agente IA';
                $texto = htmlspecialchars($msg['parts'][0]['text'] ?? '');
                $texto = nl2br($texto); // Convertir saltos de línea a <br>
                
                $color = $msg['role'] === 'user' ? '#0056b3' : '#17a2b8';
                $historial_texto .= "<p><strong style='color:$color;'>[$role]:</strong><br/> $texto</p>";
            }
            
            $correo_cliente = !empty($parsed_json['correo_cliente']) ? $parsed_json['correo_cliente'] : "chat-ia@qpaypro.com";
            $nombre_comercio = !empty($parsed_json['nombre_comercio']) ? $parsed_json['nombre_comercio'] : "No especificado";

            $ticket_data = [
                "subject" => "Escalamiento desde chatIA Comercio " . $nombre_comercio,
                "departmentId" => $zoho_department_id,
                "contact" => [
                    "lastName" => $nombre_comercio,
                    "email" => $correo_cliente
                ],
                "description" => $historial_texto
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
            curl_setopt($zc, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($zc, CURLOPT_TIMEOUT, 30);
            
            $zoho_response = curl_exec($zc);
            curl_close($zc);
            
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
