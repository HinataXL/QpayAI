# Límite de consultas a Tappy

Cada mensaje que se le manda a Tappy es una llamada a Gemini que se factura, y
puede terminar en un ticket de Zoho Desk. `resolver.php` limita cuántas consultas
acepta de cada IP antes de hacer cualquier trabajo, para que nadie pueda inflar la
factura ni llenar la bandeja de soporte con un script.

La lógica vive en [`lib/rate_limit.php`](lib/rate_limit.php) y está pensada para
reutilizarse en otros endpoints.

## Límites

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

## Qué ve el cliente

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

## Configuración del proxy — importante

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

### IPv6

Un cliente IPv6 recibe normalmente un bloque `/64` completo y puede estrenar
dirección en cada petición. Contar la dirección exacta dejaría el límite sin efecto,
así que las IPv6 se agrupan por su prefijo `/64`. Las IPv4 se cuentan por dirección.

## Almacenamiento

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
