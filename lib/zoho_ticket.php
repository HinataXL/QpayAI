<?php
/**
 * Asunto y cuerpo del ticket de Zoho Desk que abre Tappy al escalar a soporte.
 */

// Es una librería: pedirla directamente por HTTP no debe hacer nada.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/* ---------------------------------------------------------------------------
   Requisitos para abrir un ticket
   ---------------------------------------------------------------------------
   Un ticket sin correo no se puede responder, y uno sin contexto obliga a soporte
   a preguntar primero qué pasa: las dos cosas alargan el SLA. La IA decide cuándo
   escalar, pero estas reglas se aplican en el servidor y no dependen de ella.
   --------------------------------------------------------------------------- */

/** Letras y dígitos con significado que debe sumar lo escrito por la persona. */
const TAPPY_CONTEXT_MIN_CHARS = 12;

const TAPPY_MSG_NEED_CONTEXT = "¡Espera! Antes de abrir un ticket necesito entender qué está pasando, " .
    "así soporte puede ayudarte sin tener que volver a preguntarte.\n\nCuéntame:\n" .
    "• Qué endpoint o servicio estás usando\n" .
    "• Qué enviaste (el JSON o la petición, sin llaves reales)\n" .
    "• Qué error o respuesta recibiste\n\n" .
    "Con eso intento resolverlo y, si hace falta, abro el ticket con toda la información.";

const TAPPY_MSG_NEED_EMAIL = "Para abrir el ticket necesito un **correo electrónico válido** donde soporte " .
    "pueda contactarte (por ejemplo, `nombre@tucomercio.com`). ¿Me lo compartes junto con el nombre de tu comercio?";

/** Tappy solo atiende integración y pagos; esto responde a todo lo demás. */
const TAPPY_MSG_OFF_TOPIC = "Eso se sale de lo mío 😅 Soy Tappy y solo te puedo ayudar con la " .
    "**integración de QPayPro y temas de pagos**.\n\nPor ejemplo:\n" .
    "• Integrar el API en tu lenguaje o framework (PHP, JavaScript, Flutter, Java…)\n" .
    "• Entender un error o una respuesta del API\n" .
    "• Checkout alojado, tokenización, 3D-Secure o webhooks\n\n" .
    "¿Qué necesitas de tu integración?";

/** Palabras que no describen el problema: saludos, pedir ayuda o un ticket, relleno. */
const TAPPY_CONTEXT_STOPWORDS = [
    'hola', 'buenas', 'buenos', 'dias', 'días', 'tardes', 'noches', 'gracias', 'favor', 'porfa', 'please', 'ok', 'vale',
    'quiero', 'quisiera', 'necesito', 'ocupo', 'puedes', 'pueden', 'podrias', 'podrías', 'podrian', 'podrían', 'me', 'nos',
    'abrir', 'abre', 'abran', 'abras', 'abranme', 'ábranme', 'crear', 'crea', 'creen', 'levantar', 'levanta', 'levanten',
    'ticket', 'tickets', 'caso', 'reporte', 'soporte', 'ayuda', 'ayudame', 'ayúdame', 'ayudar', 'urgente', 'ya', 'ahora',
    'humano', 'humana', 'persona', 'agente', 'asesor', 'asesora', 'ejecutivo', 'alguien', 'real', 'equipo',
    'hablar', 'contactar', 'contacto', 'comunicar', 'comunicarme', 'llamar', 'llamada', 'escalar', 'escala', 'escalen',
    'correo', 'email', 'mail', 'comercio', 'empresa', 'tienda', 'negocio', 'nombre', 'soy', 'es', 'mi', 'mis',
    'tengo', 'tenemos', 'hay', 'problema', 'problemas', 'error', 'errores', 'falla', 'fallas', 'duda', 'dudas',
    'no', 'si', 'sí', 'un', 'una', 'uno', 'el', 'la', 'los', 'las', 'lo', 'le', 'de', 'del', 'al', 'con', 'para', 'por',
    'que', 'qué', 'y', 'o', 'a', 'en', 'se', 'su', 'sus', 'tu', 'te', 'esto', 'eso', 'este', 'esta', 'está', 'todo',
];

/** Texto que escribió la persona, sin el prefijo de contexto que añade el chat. */
function tappy_user_texts(array $history): array
{
    $texts = [];
    foreach ($history as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $texts[] = preg_replace('/^\[Contexto: .*?\]\s*/su', '', (string) ($msg['parts'][0]['text'] ?? ''));
        }
    }
    return $texts;
}

/**
 * Correo para el ticket, o null si no sirve. Debe ser válido y además aparecer en
 * lo que escribió la persona: la IA no puede inventarlo ni tomar uno de ejemplo.
 */
function tappy_ticket_email(array $history, string $candidate): ?string
{
    $email = trim($candidate);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    foreach (tappy_user_texts($history) as $text) {
        if (stripos($text, $email) !== false) {
            return $email;
        }
    }
    return null;
}

/**
 * ¿La persona describió el problema? Suma las letras y dígitos de lo que escribió,
 * sin contar correos, el nombre del comercio ni palabras como "quiero un ticket".
 * "Hola, abran ticket, ana@x.com, Tienda Ana" no alcanza; "Mi pago devuelve 401" sí.
 */
function tappy_ticket_has_context(array $history, string $comercio = ''): bool
{
    $text = implode(' ', tappy_user_texts($history));
    $text = preg_replace('/[^\s@]+@[^\s@]+/u', ' ', $text);
    if (trim($comercio) !== '') {
        $text = str_ireplace(trim($comercio), ' ', $text);
    }

    $total = 0;
    foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
        if (in_array($lower, TAPPY_CONTEXT_STOPWORDS, true)) {
            continue;
        }
        $total += preg_match_all('/[\p{L}\p{N}]/u', $word);
    }
    return $total >= TAPPY_CONTEXT_MIN_CHARS;
}

/** "Escalamiento desde Tappy - Tienda Ana" */
function tappy_ticket_subject(string $nombreComercio): string
{
    // El nombre lo extrae la IA de lo que escribió el usuario: una sola línea y acotado.
    $nombre = trim(preg_replace('/\s+/u', ' ', $nombreComercio));
    if ($nombre === '') {
        $nombre = 'No especificado';
    }
    if (function_exists('mb_substr')) {
        $nombre = mb_substr($nombre, 0, 150, 'UTF-8');
    } else {
        $nombre = substr($nombre, 0, 150);
    }
    return 'Escalamiento desde Tappy - ' . $nombre;
}

/**
 * Historial en HTML. Los mensajes de Tappy van en cursiva para distinguirlos de
 * los de la persona a simple vista.
 */
function tappy_ticket_description(array $history): string
{
    $html = '<h3>Historial de la conversación con Tappy</h3>'
        . '<p style="color:#6b7280;">Los mensajes de Tappy (IA) aparecen en <em>cursiva</em>.</p><hr/>';

    foreach ($history as $msg) {
        $esUsuario = ($msg['role'] ?? '') === 'user';
        $texto = (string) ($msg['parts'][0]['text'] ?? '');
        $contexto = '';

        // El chat antepone qué página de la documentación se estaba viendo. Se muestra
        // aparte para que no parezca escrito por la persona.
        if ($esUsuario && preg_match('/^\[Contexto: (.*?)\]\s*/su', $texto, $m)) {
            $contexto = $m[1];
            $texto = substr($texto, strlen($m[0]));
        }

        // El chat guarda las respuestas como "Diagnóstico: …\n\nCódigo:\n```json…```".
        // En el ticket sobran la etiqueta y el bloque de código cuando vino vacío.
        if (!$esUsuario) {
            $texto = preg_replace('/^Diagnóstico:\s*/u', '', $texto);
            $texto = preg_replace('/\s*Código:\s*```[a-z]*\s*```\s*$/u', '', $texto);
        }

        $cuerpo = nl2br(htmlspecialchars(trim($texto), ENT_QUOTES, 'UTF-8'));

        if ($esUsuario) {
            $html .= '<p><strong style="color:#0056b3;">[Usuario]:</strong><br/>';
            if ($contexto !== '') {
                $html .= '<span style="color:#6b7280;font-size:12px;">' . htmlspecialchars($contexto, ENT_QUOTES, 'UTF-8') . '</span><br/>';
            }
            $html .= $cuerpo . '</p>';
        } else {
            $html .= '<p><strong style="color:#17a2b8;">[Tappy]:</strong><br/><em>' . $cuerpo . '</em></p>';
        }
    }

    return $html;
}
