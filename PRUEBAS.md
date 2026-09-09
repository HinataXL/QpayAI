# Consola de pruebas en la documentación

Cada endpoint de `index.html` tiene un botón **Probar** que abre una consola para
lanzar la petición sin salir de la página ni abrir Postman.

## Por qué existe `try.php`

El navegador no puede llamar a `sandboxpayments.qpaypro.com` (ni a ningún dominio
de QPayPro) directamente: esos servidores no devuelven cabeceras CORS, así que el
navegador bloquea la respuesta antes de que el JavaScript la vea. `try.php` recibe
la petición del navegador, la reenvía desde el servidor y devuelve el resultado
crudo: estado, cabeceras, cuerpo, milisegundos y tamaño.

**No es un proxy abierto.** Solo acepta estos destinos y rechaza cualquier otro:

```
api-sandboxpayments.qpaypro.com   sandboxpayments.qpaypro.com
api-payments.qpaypro.com          payments.qpaypro.com
devbilling.qpaypro.com            billing.qpaypro.com
api.qpaypro.com
```

Además: solo `https`, sin seguir redirecciones (una redirección podría saltarse la
lista blanca), rechaza destinos que resuelvan a IP internas, verifica el
certificado TLS, limita el cuerpo saliente a 256 KB y la respuesta a 1 MB, y
permite 60 pruebas cada 5 minutos por IP.

## Qué ofrece la consola

- **Entorno**: alterna la URL entre sandbox y producción cuando el endpoint tiene
  ambos. Los de `api.qpaypro.com` (cobros automáticos) no tienen equivalente en
  sandbox, así que ahí no aparece el selector.
- **Variables de ruta**: los endpoints con `{id}` muestran un campo por variable y
  no dejan enviar hasta llenarlo.
- **Body editable** con botón *Formatear* (avisa si el JSON es inválido).
- **Mis credenciales**: guarda tus llaves una sola vez (ícono de llave en la barra
  superior) y sustitúyelas en cualquier endpoint con un clic, en vez de escribirlas
  en las 39 consolas. Se guardan en el `localStorage` del navegador de cada quien,
  nunca en el servidor.
- **Copiar cURL**: el comando equivalente a lo que hay en pantalla, listo para la
  terminal o para compartir con un integrador.
- **Respuesta**: estado con color según 2xx/4xx/5xx, tiempo, tamaño, JSON
  formateado y coloreado, pestaña de cabeceras y botón de copiar.
- `Ctrl + Enter` envía desde el body.

## Advertencia sobre credenciales

Las peticiones salen desde tu servidor con las llaves que estén en el body. En una
documentación pública eso significa que cualquier visitante puede lanzar llamadas
al sandbox de QPayPro desde tu servidor — para eso está el límite por IP. La
consola muestra un aviso de no usar llaves de producción, pero si publicas la
documentación en internet abierto conviene decidir si `try.php` debe quedar detrás
de la misma autenticación que `admin.php`.

## Certificados TLS

`try.php` **nunca** desactiva la verificación del certificado. Como hay entornos
donde cURL no trae configurado ningún almacén de certificados (PHP en Windows,
imágenes mínimas) y ahí toda petición fallaría, el proxy resuelve el almacén solo,
en este orden:

1. Si `php.ini` define `curl.cainfo` u `openssl.cafile`, se respeta esa configuración.
2. Si existe un bundle en una ruta conocida (`/etc/ssl/certs/ca-certificates.crt`
   en la imagen `php:8.2-apache`, entre otras), lo usa. También puedes colocar tu
   propio `cacert.pem` junto a `try.php` y tendrá prioridad.
3. Si no encuentra ninguno, usa el almacén nativo del sistema operativo
   (`CURLSSLOPT_NATIVE_CA`), que es lo que hace funcionar `php -S` en Windows sin
   configuración extra.

Si aun así aparece *"Falló la verificación del certificado TLS"*, al entorno le
falta el paquete `ca-certificates` y conviene instalarlo.
