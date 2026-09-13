<?php
/**
 * ============================================================================
 *  Opciones TLS para las llamadas salientes con cURL
 * ----------------------------------------------------------------------------
 *  Las peticiones a Gemini y a Zoho llevan la API key y el refresh token. Sin
 *  verificar el certificado, cualquiera en medio de la conexión (una red
 *  comprometida, un DNS envenenado) podría hacerse pasar por Google o Zoho,
 *  quedarse con esas credenciales y usar la cuenta a nuestro nombre.
 *
 *  La verificación nunca se desactiva. Lo que sí se resuelve aquí es de dónde
 *  sacar los certificados raíz, porque hay entornos (PHP en Windows, imágenes
 *  mínimas) donde cURL no trae ninguno configurado y toda petición fallaría.
 * ============================================================================
 */

// Es una librería: pedirla directamente por HTTP no debe hacer nada.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Almacén de certificados, en este orden:
 *   1. Si php.ini define curl.cainfo u openssl.cafile, se respeta.
 *   2. Si hay un bundle en una ruta conocida, se usa. Un cacert.pem en la raíz
 *      del proyecto tiene prioridad.
 *   3. Si no, el almacén nativo del sistema operativo (Windows/macOS).
 */
function tls_ca_options(): array
{
    if (trim((string) ini_get('curl.cainfo')) !== '' || trim((string) ini_get('openssl.cafile')) !== '') {
        return [];
    }

    $candidates = [
        dirname(__DIR__) . '/cacert.pem',        // bundle propio, si se coloca uno
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

/** Opciones para aplicar con curl_setopt_array() a cualquier llamada saliente. */
function tls_curl_options(): array
{
    return [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ] + tls_ca_options();
}

/** ¿El fallo de cURL es de verificación de certificado? */
function tls_is_cert_error(int $errno): bool
{
    // 60: CA desconocida o certificado inválido; 51: nombre no coincide (cURL antiguo);
    // 77: no se pudo leer el bundle de CA.
    return in_array($errno, [51, 60, 77], true);
}

function tls_cert_error_message(): string
{
    return 'No se pudo verificar el certificado TLS del servicio externo. Suele indicar que al ' .
        'servidor le falta el paquete ca-certificates o la opción curl.cainfo en php.ini.';
}
