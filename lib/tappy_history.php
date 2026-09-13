<?php
/**
 * ============================================================================
 *  Saneamiento del historial que el chat le manda a Tappy
 * ----------------------------------------------------------------------------
 *  El límite por IP acota cuántas consultas llegan, pero no cuánto cuesta cada
 *  una: Gemini factura por token, y el historial lo arma el navegador. Sin
 *  control, una sola petición podría traer un historial enorme, adjuntar
 *  imágenes o PDFs en base64 (`inlineData`) o repetir partes, y costar lo que
 *  cientos de consultas normales.
 *
 *  Aquí el historial no se "valida y deja pasar": se reconstruye desde cero con
 *  solo lo que el chat legítimo envía (rol + un texto), y se recorta a un
 *  presupuesto fijo. Lo que no encaja en ese molde nunca llega a Gemini.
 * ============================================================================
 */

// Es una librería: pedirla directamente por HTTP no debe hacer nada.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** Longitud en caracteres. mbstring no siempre está instalado (PHP en Windows). */
function tappy_strlen(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    $count = preg_match_all('/./su', $text);
    return $count === false ? strlen($text) : $count;
}

/**
 * @param mixed $history Lo que llegó en `history`, sin confiar en su forma.
 * @param array{message_chars:int, history_chars:int, history_turns:int} $limits
 * @return array{ok:bool, error:string, code:int, history:array, dropped:int}
 *
 * - El último mensaje debe ser del usuario y no pasar de `message_chars`; si
 *   pasa, se rechaza (recortarlo cambiaría la pregunta sin avisar).
 * - Los turnos anteriores se recortan desde el más antiguo hasta caber en
 *   `history_chars` y `history_turns`. Una conversación larga pierde contexto
 *   viejo, pero sigue funcionando.
 */
function tappy_sanitize_history($history, array $limits): array
{
    $fail = static fn (string $error, int $code = 400) =>
        ['ok' => false, 'error' => $error, 'code' => $code, 'history' => [], 'dropped' => 0];

    // Si la configuración dejara el presupuesto por debajo de un mensaje válido,
    // la pregunta actual no cabría y a Gemini le llegaría una conversación vacía.
    $limits['message_chars'] = max(1, (int) $limits['message_chars']);
    $limits['history_chars'] = max($limits['message_chars'], (int) $limits['history_chars']);
    $limits['history_turns'] = max(1, (int) $limits['history_turns']);

    if (!is_array($history) || $history === [] || !array_is_list($history)) {
        return $fail('El historial (history) es requerido.');
    }

    // Reconstrucción: solo rol y el texto de la primera parte. Todo lo demás
    // (inlineData, fileData, partes extra, campos desconocidos) se descarta.
    $clean = [];
    foreach ($history as $turn) {
        $role = is_array($turn) ? ($turn['role'] ?? null) : null;
        $text = is_array($turn) && isset($turn['parts'][0]) && is_array($turn['parts'][0])
            ? ($turn['parts'][0]['text'] ?? null)
            : null;

        if (($role !== 'user' && $role !== 'model') || !is_string($text)) {
            return $fail('El historial tiene un formato inválido.');
        }
        $clean[] = ['role' => $role, 'text' => $text, 'len' => tappy_strlen($text)];
    }

    $last = end($clean);
    if ($last['role'] !== 'user') {
        return $fail('El último mensaje del historial debe ser del usuario.');
    }
    if (trim($last['text']) === '') {
        return $fail('El mensaje está vacío.');
    }
    if ($last['len'] > $limits['message_chars']) {
        return $fail(sprintf(
            'Tu mensaje tiene %s caracteres y el máximo es %s. Recórtalo o envía solo la parte relevante ' .
            '(por ejemplo, el JSON de la petición y el error que devuelve).',
            number_format($last['len'], 0, ',', '.'),
            number_format($limits['message_chars'], 0, ',', '.')
        ), 413);
    }

    // Recorte desde el final hacia atrás: se conserva lo más reciente que quepa.
    $kept  = [];
    $chars = 0;
    for ($i = count($clean) - 1; $i >= 0; $i--) {
        $turn = $clean[$i];
        if (count($kept) >= $limits['history_turns'] || $chars + $turn['len'] > $limits['history_chars']) {
            break;
        }
        $chars += $turn['len'];
        array_unshift($kept, $turn);
    }

    // Gemini espera que la conversación empiece por el usuario.
    while ($kept !== [] && $kept[0]['role'] !== 'user') {
        array_shift($kept);
    }

    $contents = array_map(
        static fn ($t) => ['role' => $t['role'], 'parts' => [['text' => $t['text']]]],
        $kept
    );

    return [
        'ok'      => true,
        'error'   => '',
        'code'    => 200,
        'history' => $contents,
        'dropped' => count($clean) - count($kept),
    ];
}
