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

$gemini_api_key = getenv('GEMINI_API_KEY');
$gemini_model = getenv('GEMINI_MODEL') ?: 'gemini-flash-latest';

// Si no están en el entorno (ej. en Render), intentar leer del .env local
if (!$gemini_api_key) {
    $env_path = __DIR__ . '/.env';
    if (file_exists($env_path)) {
        $env = parse_ini_file($env_path);
        $gemini_api_key = $env['GEMINI_API_KEY'] ?? '';
        $gemini_model = $env['GEMINI_MODEL'] ?? 'gemini-flash-latest';
    } else {
        echo json_encode(["error" => "No se encontró GEMINI_API_KEY en el entorno ni en un archivo .env."]);
        exit;
    }
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
$system_prompt = "Eres el Ingeniero de Soporte Senior de Qpaypro. Tu objetivo es resolver bloqueos de integración en tiempo real basándote ÚNICAMENTE en la siguiente documentación:

DOCUMENTACIÓN DE REFERENCIA:
$contexto_recuperado

REGLAS ESTRICTAS:
1. Analiza el JSON o mensaje proporcionado por el usuario y compáralo con la documentación de referencia.
2. Identifica el error específico (ej. falta un campo obligatorio, formato de arreglo incorrecto). No inventes parámetros fuera de la documentación.
3. Devuelve ESTRICTAMENTE un JSON válido con esta estructura exacta, sin texto adicional:
{
  \"diagnostico\": \"Explicación técnica en 1 frase de por qué falló basándose en la documentación. Si el usuario te saluda, salúdalo de vuelta aquí y deja codigo_corregido vacío.\",
  \"codigo_corregido\": \"El bloque completo de código reparado (preferiblemente JSON puro listo para copiar). Si no hay código que corregir, devuélvelo vacío.\"
}";

// 4. Llamada a la API de Gemini (cURL)
$gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/{$gemini_model}:generateContent?key=" . $gemini_api_key;

$data = [
    "systemInstruction" => [
        "parts" => [
            ["text" => $system_prompt]
        ]
    ],
    "contents" => $history,
    "generationConfig" => [
        "temperature" => 0.1,
        "responseMimeType" => "application/json"
    ]
];

$ch = curl_init($gemini_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
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

// 5. Salida al Frontend
try {
    $parsed_json = json_decode($ia_reply, true);
    if (!$parsed_json || !isset($parsed_json['diagnostico'])) {
        throw new Exception("El formato devuelto por la IA no fue el esperado JSON estricto.");
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
