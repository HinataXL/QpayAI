# Límites y seguridad de Tappy

Cada mensaje que se le manda a Tappy es una llamada a Gemini que se factura, y
puede terminar en un ticket de Zoho Desk. `resolver.php` aplica tres defensas antes
de gastar un solo token:

1. **[Límite por IP](#límite-de-consultas-por-ip)**: cuántas consultas acepta de cada
   visitante. Vive en [`lib/rate_limit.php`](lib/rate_limit.php).
2. **[Límite de tamaño](#límite-de-tamaño)**: cuánto puede costar cada consulta. Vive en
   [`lib/tappy_history.php`](lib/tappy_history.php).
3. **[TLS verificado](#tls)** en las llamadas a Gemini y Zoho, para que nadie pueda
   interceptar la API key. Vive en [`lib/tls.php`](lib/tls.php).

Las tres hacen falta juntas: limitar cuántas consultas llegan no sirve si una sola
puede costar como cientos, y ninguno de los dos límites protege la factura si la
API key se puede robar en tránsito.

## Límite de consultas por IP

### Tramos

Se aplican tres tramos a la vez sobre una ventana deslizante. Basta con superar uno
para que la consulta se rechace.

| Variable                | Por defecto | Ventana    |
|-------------------------|-------------|------------|
| `RATE_LIMIT_PER_MINUTE` | 8           | 60 s       |
| `RATE_LIMIT_PER_HOUR`   | 40          | 1 hora     |
| `RATE_LIMIT_PER_DAY`    | 150         | 24 horas   |

Poner un tramo en `0` lo desactiva. Se leen del entorno o del `.env`, igual que
`GEMINI_API_KEY`.

Los valores por defecto dejan de sobra a un desarrollador que está depurando (un
mensaje cada pocos segundos durante horas), y cortan a un script en segundos.

### Qué ve el cliente

Al pasarse, `resolver.php` responde **HTTP 429** con la cabecera `Retry-After`
(segundos) y este cuerpo:

```json
{
  "error": "Estás enviando consultas muy seguido. Espera 45 segundos y vuelve a intentarlo.",
  "rate_limited": true,
  "retry_after": 45
}
```

En el chat, Tappy lo muestra como un aviso en ámbar (no como error), retira el
mensaje que no se procesó y lo devuelve al cuadro de texto, y bloquea el envío con
una cuenta regresiva en el pie del compositor. Se puede seguir escribiendo mientras
tanto; el botón vuelve solo al terminar la espera.

Las respuestas aceptadas incluyen `X-RateLimit-Remaining`, útil para depurar.

Las consultas rechazadas **no cuentan**: reintentar durante la espera no la alarga.

### Configuración del proxy — importante

Detrás de un proxy, la conexión que ve PHP es la del proxy, no la del visitante: sin
configurarlo, todos los visitantes compartirían una sola IP y un mismo cupo. La IP
real viaja en un encabezado, pero **ese encabezado lo puede escribir cualquiera**:
si se creyera siempre, bastaría con mandar una IP inventada en cada petición para no
toparse nunca con el límite.

Por eso el encabezado solo se lee cuando la conexión llega desde un proxy de
confianza, y solo el encabezado que se configure:

| Despliegue                               | `TRUSTED_PROXY_HEADER`       |
|------------------------------------------|------------------------------|
| Cloudflare Tunnel (`docker-compose.yml`) | `CF-Connecting-IP` (defecto) |
| Render, nginx u otro balanceador         | `X-Forwarded-For`            |
| Servidor expuesto directo a internet     | `none`                       |

`TRUSTED_PROXIES` define desde qué direcciones se aceptan esos encabezados. Por
defecto son las redes privadas y loopback
(`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7`), que es
donde viven `cloudflared` y los balanceadores de plataforma. Un cliente que llega
directo desde internet trae IP pública y sus encabezados se ignoran.

> **Si se despliega en Render**, hay que poner `TRUSTED_PROXY_HEADER=X-Forwarded-For`.
> Con el valor por defecto, un atacante podría mandar su propio `CF-Connecting-IP`
> (Render no lo borra) y saltarse el límite.

> **En producción con Cloudflare Tunnel**, conviene quitar `ports: "8001:80"` de
> `docker-compose.yml`, o limitarlo a `127.0.0.1:8001:80`. El túnel no lo necesita, y
> en instalaciones donde Docker publica el puerto a través de su proxy interno
> (Docker Desktop, clientes IPv6) la conexión directa llega con una IP privada que
> pasaría por proxy de confianza.

Con `X-Forwarded-For` la cadena se recorre **desde la derecha**: cada proxy añade a
quien le habló, así que lo de la izquierda lo pudo inventar el cliente.

#### IPv6

Un cliente IPv6 recibe normalmente un bloque `/64` completo y puede estrenar
dirección en cada petición. Contar la dirección exacta dejaría el límite sin efecto,
así que las IPv6 se agrupan por su prefijo `/64`. Las IPv4 se cuentan por dirección.

### Almacenamiento

Los conteos se guardan en SQLite, por defecto en el directorio temporal del sistema
(`/tmp/qpayai-rate-limit.sqlite` en Docker), **fuera de la raíz web**: el archivo
contiene IPs de visitantes. Se puede cambiar con `RATE_LIMIT_DB`. Los registros se
purgan solos al salir de la ventana más larga.

Se usa SQLite y no un JSON porque el conteo tiene que ser atómico: con un archivo
que se lee y se escribe en dos pasos, una ráfaga de peticiones simultáneas —que es
justo el ataque del que nos defendemos— pierde incrementos y el límite se filtra.
Cada comprobación corre dentro de una transacción `BEGIN IMMEDIATE`. En pruebas con
40 procesos PHP simultáneos contra un límite de 5, pasaron exactamente 5 en todas
las rondas.

Al estar en `/tmp`, los conteos se reinician si se recrea el contenedor. Es
aceptable para un límite de abuso; si alguna vez hay varias instancias detrás de un
balanceador, cada una contaría por separado y habría que mover el almacén a algo
compartido (Redis, una base de datos común).

Si el almacén falla (disco lleno, permisos), la consulta **se deja pasar** y el error
queda en el log de PHP con el prefijo `[rate_limit]`: un límite roto no debe tumbar
el soporte. Conviene revisar el log tras desplegar.

## Límite de tamaño

El límite por IP acota cuántas consultas llegan, pero Gemini factura por token y el
historial de la conversación lo arma el navegador. Sin tope, una sola petición
podía mandar un historial enorme y costar lo que cientos de consultas normales.

| Variable                   | Por defecto | Qué limita                                      |
|----------------------------|-------------|-------------------------------------------------|
| `TAPPY_MAX_BODY_BYTES`     | 262144      | Tamaño bruto de la petición (256 KB)            |
| `TAPPY_MAX_MESSAGE_CHARS`  | 10000       | Caracteres del mensaje actual                   |
| `TAPPY_MAX_HISTORY_CHARS`  | 40000       | Caracteres de toda la conversación enviada      |
| `TAPPY_MAX_HISTORY_TURNS`  | 20          | Turnos de la conversación enviada               |
| `GEMINI_MAX_OUTPUT_TOKENS` | 4096        | Tokens de la respuesta (se facturan más caros)  |

Con esto, lo más que puede costar una consulta queda fijo: la documentación del
prompt de sistema más, como mucho, 40.000 caracteres de conversación y 4.096 tokens
de respuesta. Antes el techo era el tamaño máximo que aceptara PHP (8 MB) o la
ventana de contexto del modelo.

**Se cuentan caracteres, no bytes**, así que un mensaje con acentos o `ñ` no se
penaliza. No depende de `mbstring`, que no siempre está instalado.

### El historial se reconstruye, no solo se mide

La API de Gemini acepta en cada mensaje partes con imágenes o PDFs en base64
(`inlineData`), referencias a archivos (`fileData`) y varias partes por turno. Todo
eso se factura. Como el historial llega tal cual desde el navegador, no basta con
medirlo: `resolver.php` lo **rearma desde cero** con solo lo que el chat legítimo
envía — el rol (`user` o `model`) y un texto. Cualquier otra cosa se descarta sin
llegar a Gemini.

Se comprobó contra el servicio real: un mensaje con un `inlineData` inválido, que
Gemini rechaza con HTTP 400 si le llega directo, pasó por `resolver.php` y obtuvo una
respuesta normal, porque la parte se descartó antes.

### Qué pasa en cada caso

- **Mensaje actual demasiado largo** → HTTP **413** con `"too_large": true` y un texto
  que dice cuántos caracteres tiene y el máximo. No se recorta en silencio: cambiaría
  la pregunta sin avisar. El chat devuelve el texto al compositor para que lo acorte.
- **Conversación larga** → no se rechaza. Se descartan los turnos **más antiguos**
  hasta caber en el presupuesto, así una charla larga pierde contexto viejo pero sigue
  funcionando. El chat hace el mismo recorte antes de enviar, de modo que la petición
  no crece sin fin (en una prueba con 25 intercambios y respuestas de 3.000
  caracteres, la última petición llevó 19 turnos y 27.495 caracteres).
- **Historial con formato inválido** (roles inventados, sin texto, JSON roto) →
  HTTP **400**. Antes producía avisos de PHP mezclados con la respuesta.
- **Respuesta cortada por `GEMINI_MAX_OUTPUT_TOKENS`** → un mensaje claro pidiendo una
  pregunta más acotada, en vez de un error de formato por JSON truncado.

El chat también avisa **antes** de enviar: el contador del compositor muestra
`9.000 / 10.000` al acercarse al tope y se pone en rojo y bloquea el envío al pasarlo.

> Los topes del chat están fijos en `chat.html` (`MAX_MESSAGE_CHARS` y compañía). Si
> se cambian las variables del servidor, conviene actualizarlos también. Si el
> servidor queda **más estricto** que el chat, igual funciona: responde 413 y el chat
> muestra el aviso con el texto recuperado.

## TLS

`resolver.php` desactivaba la verificación de certificados
(`CURLOPT_SSL_VERIFYPEER => false`) en las tres llamadas salientes: Gemini, el
token OAuth de Zoho y la creación de tickets. Esas llamadas llevan la **API key de
Gemini** y el **refresh token de Zoho**. Sin verificar, cualquiera en medio de la
conexión (una red comprometida, un DNS envenenado) podía presentar un certificado
falso, hacerse pasar por Google o Zoho y quedarse con las credenciales — y con ellas
facturar a nombre de la cuenta sin pasar por ningún límite.

Se comprobó contra servidores con certificados deliberadamente rotos:

| Certificado                  | Configuración anterior | Ahora      |
|------------------------------|------------------------|------------|
| Autofirmado                  | **aceptado**           | rechazado  |
| Vencido                      | **aceptado**           | rechazado  |
| Raíz no confiable            | **aceptado**           | rechazado  |
| Google, Zoho Accounts, Desk  | aceptado               | verificado |

La verificación ya no se desactiva nunca. Lo que resuelve
[`lib/tls.php`](lib/tls.php) es **de dónde** sacar los certificados raíz, porque en
algunos entornos cURL no trae ninguno y toda llamada fallaría. Usa la misma lógica
que `try.php`:

1. Si `php.ini` define `curl.cainfo` u `openssl.cafile`, se respeta.
2. Si hay un bundle en una ruta conocida (`/etc/ssl/certs/ca-certificates.crt` en la
   imagen `php:8.2-apache`), lo usa. Un `cacert.pem` en la raíz del proyecto tiene
   prioridad.
3. Si no, el almacén nativo del sistema operativo, que es lo que hace funcionar
   `php -S` en Windows.

Si falla la verificación, Tappy responde *"No se pudo verificar el certificado TLS del
servicio externo…"* y el detalle queda en el log con el prefijo `[resolver]`. Los
fallos de red con Zoho también se registran ahí; antes se veían solo como "error de
autenticación con Zoho".

## Probarlo en local

```bash
RATE_LIMIT_PER_MINUTE=3 php -S 127.0.0.1:8000
```

Una petición sin `history` cuenta para el límite pero se rechaza antes de llamar a
Gemini, así que sirve para probar sin gastar:

```bash
curl -i -X POST -H "Content-Type: application/json" -d '{}' http://127.0.0.1:8000/resolver.php
```

A la cuarta devuelve `429`.

Para el límite de tamaño, un mensaje de 10.001 caracteres también se rechaza antes de
Gemini:

```bash
node -e "console.log(JSON.stringify({history:[{role:'user',parts:[{text:'a'.repeat(10001)}]}]}))" > largo.json
curl -i -X POST -H "Content-Type: application/json" --data-binary @largo.json http://127.0.0.1:8000/resolver.php
```

Devuelve `413`.
