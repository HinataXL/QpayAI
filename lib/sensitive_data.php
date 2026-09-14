<?php
/**
 * ============================================================================
 *  Detección de datos sensibles en los mensajes a Tappy
 * ----------------------------------------------------------------------------
 *  Lo que se escribe en el chat viaja a Gemini y, si se escala, termina en un
 *  ticket de Zoho. Un desarrollador que pega su JSON real puede estar
 *  compartiendo sus llaves de producción o la tarjeta de un cliente sin darse
 *  cuenta. Este filtro corre antes de todo eso y bloquea el mensaje.
 *
 *  Se permite exactamente lo que aparece en la documentación (credenciales de
 *  sandbox y tarjetas de prueba) y los marcadores de posición obvios, para no
 *  estorbar a quien pega un ejemplo.
 *
 *  La misma lógica existe en chat.html, que avisa antes de enviar. Si cambias
 *  una regla aquí, cámbiala allá también.
 * ============================================================================
 */

// Es una librería: pedirla directamente por HTTP no debe hacer nada.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** Campos de autenticación de QPayPro. */
const SENSITIVE_KEY_FIELDS = ['x_login', 'x_private_key', 'x_api_secret', 'x_api_key', 'public_key'];

/** Valores publicados en la documentación: compartirlos no expone nada. */
const SENSITIVE_DOC_KEY_VALUES = [
    'visanetgt_qpay', 'visanetgt_qpay_testings', 'pk_test_123456789',
    '88888888888', '99999999999',
    '5gnwbpoqmft16860', 'o1gn8sgilkv16860', 'dccymkkspqg16860',
];

/** Tarjetas de prueba de la documentación (la segunda es la del flujo 3DS2). */
const SENSITIVE_DOC_CARDS = ['4111111111111111', '4000000000001091'];

/** Valores que obviamente no son una llave: vacíos, enmascarados o de relleno. */
function sensitive_is_placeholder(string $value, string $field): bool
{
    $v = trim($value);
    $l = strtolower($v);
    if ($v === '' || $l === $field) {
        return true;
    }
    if (preg_match('/^[*x.•_#\-0]+$/iu', $v)) {                         // ****  xxxx  ...  0000
        return true;
    }
    if (preg_match('/^(<.*>|\{\{.*\}\}|\$\{.*\}|\[.*\]|%.*%)$/u', $v)) { // <TU_LLAVE> {{x}} ${X}
        return true;
    }
    if (preg_match('/\*{3,}|x{4,}|•{3,}/iu', $v)) {                      // enmascaradas a medias
        return true;
    }
    if (preg_match('/^(tu|tus|your)[_\- ]/', $l)) {                      // tu_public_key, YOUR_KEY
        return true;
    }
    // Palabras de relleno solo como palabra separada (TU_PUBLIC_KEY, API_SECRET_AQUI):
    // incrustadas en el valor, como en "realKey123", pueden ser parte de una llave real.
    return (bool) preg_match('/(^|[_\-\s.])(ejemplo|example|placeholder|redacted|oculto|hidden|aqui|aquí|here|llave|clave|key|secret|login)([_\-\s.]|$)/u', $l);
}

/** Referencias de código (variables, llamadas), no el valor en sí. */
function sensitive_is_reference(string $value): bool
{
    return (bool) preg_match('/^[$@]|\(|^(process|env|os|config|settings)\./i', trim($value));
}

function sensitive_luhn(string $digits): bool
{
    $sum = 0;
    $alt = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int) $digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

/**
 * Busca llaves de integración reales y números de tarjeta.
 *
 * @return list<array{type:string, field?:string, last4?:string}>
 *         Nunca devuelve el valor detectado: solo el campo o los 4 últimos dígitos.
 */
function sensitive_scan(string $text): array
{
    // El JSON pegado dentro de un string llega con comillas escapadas: \"x_login\".
    $t = preg_replace('/\\\\(["\'])/', '$1', $text);
    $findings = [];

    // Llaves: "x_login": "valor" (JSON/JS), 'x_login' => 'valor' (PHP), x_login=valor (form/env).
    $keyRe = '/["\'`]?\b(' . implode('|', SENSITIVE_KEY_FIELDS) . ')\b["\'`]?\s*(:|=>|=)\s*'
        . '(?:"([^"\n]*)"|\'([^\'\n]*)\'|`([^`\n]*)`|([^\s,;&}\])"\'`]+))/i';

    if (preg_match_all($keyRe, $t, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $field = strtolower($m[1]);
            $sep = $m[2];
            $quoted = ($m[3] ?? '') !== '' || ($m[4] ?? '') !== '' || ($m[5] ?? '') !== '';
            $value = $quoted ? ($m[3] ?: ($m[4] ?: $m[5])) : ($m[6] ?? '');

            if (!$quoted) {
                // Sin comillas: con "=" es un formulario o un .env y cuenta; con ":" o "=>"
                // solo cuenta si es un número (JSON), porque si no es un nombre de variable.
                if (sensitive_is_reference($value)) {
                    continue;
                }
                if ($sep !== '=' && !preg_match('/^\d+$/', $value)) {
                    continue;
                }
            }
            if (sensitive_is_placeholder($value, $field)) {
                continue;
            }
            if (in_array(strtolower(trim($value)), SENSITIVE_DOC_KEY_VALUES, true)) {
                continue;
            }
            $findings['key:' . $field] = ['type' => 'key', 'field' => $field];
        }
    }

    // Tarjetas: 14 a 19 dígitos (con espacios o guiones opcionales), prefijo de red de
    // tarjetas y dígito verificador Luhn válido. Con 13 dígitos serían CUI/NIT.
    if (preg_match_all('/(?<!\d)(?:\d[ \-]?){13,18}\d(?!\d)/', $t, $cards)) {
        foreach ($cards[0] as $raw) {
            $digits = preg_replace('/\D/', '', $raw);
            $len = strlen($digits);
            if ($len < 14 || $len > 19 || !preg_match('/^[2-6]/', $digits) || !sensitive_luhn($digits)) {
                continue;
            }
            if (in_array($digits, SENSITIVE_DOC_CARDS, true)) {
                continue;
            }
            $findings['card:' . $digits] = ['type' => 'card', 'last4' => substr($digits, -4)];
        }
    }

    return array_values($findings);
}

/** Mensaje amable para el usuario, sin repetir el dato sensible. */
function sensitive_message(array $findings): string
{
    $keys = [];
    $cards = [];
    foreach ($findings as $f) {
        if ($f['type'] === 'key') {
            $keys[] = '`' . $f['field'] . '`';
        } else {
            $cards[] = $f['last4'];
        }
    }

    $parts = [];
    if ($keys) {
        $parts[] = (count($keys) === 1 ? 'una llave de integración real (' : 'llaves de integración reales (')
            . implode(', ', $keys) . ')';
    }
    if ($cards) {
        $parts[] = (count($cards) === 1 ? 'un número de tarjeta terminado en ' : 'números de tarjeta terminados en ')
            . implode(', ', $cards);
    }

    $msg = "¡Alto ahí! Por tu seguridad no compartas datos sensibles en el chat.\n\n"
        . 'Detecté ' . implode(' y ', $parts) . ', así que **no envié tu mensaje**. '
        . 'Usa en su lugar los datos de prueba de la documentación';
    $msg .= $cards ? ' (por ejemplo, la tarjeta `4111 1111 1111 1111`)' : ' (las credenciales de sandbox)';
    $msg .= ' y vuelve a enviarlo.';
    if ($keys) {
        $msg .= "\n\nSi compartiste llaves de producción en algún otro lugar, lo más seguro es rotarlas desde tu panel de QPayPro.";
    }
    return $msg;
}
