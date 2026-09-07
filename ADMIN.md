# Panel de administración de la base de conocimiento

Editor web para actualizar `qpaypro_docs.txt`, el archivo que `resolver.php` inyecta
como contexto en **cada** consulta al asistente de IA. Lo que se publica aquí lo usa
la IA en la siguiente pregunta, sin reiniciar nada.

| Archivo | Rol |
|---|---|
| `admin.html` | Interfaz (login + editor). Enlace desde el ícono de lápiz en la barra superior de `index.html`. |
| `admin.php` | API JSON: sesión, lectura, guardado, versionado y restauración. |
| `docs_versions/` | Respaldos automáticos (se crea solo, está en `.gitignore`). |

## Acceso

`https://TU-DOMINIO/admin.html`

La contraseña vive en `.env` como hash bcrypt:

```
ADMIN_PASSWORD_HASH="$2y$12$..."
```

Las comillas son obligatorias y los comentarios en `.env` deben empezar con `;`
(PHP usa `parse_ini_file`, donde `#` provoca un error de sintaxis que rompe
**todas** las variables, incluida `GEMINI_API_KEY`).

### Cambiar la contraseña

Desde el panel: menú `⋮` → *Generar hash* → copia la línea completa a `.env` y
reinicia el contenedor. También sirve desde la línea de comandos:

```bash
php -r 'echo password_hash("tu-nueva-contrasena", PASSWORD_BCRYPT), PHP_EOL;'
```

En Render se puede definir `ADMIN_PASSWORD_HASH` como variable de entorno; tiene
prioridad sobre el archivo `.env`.

## Qué hace el editor

- **Secciones**: índice navegable generado de los encabezados `#`, `##`, `###`.
- **Historial**: cada guardado respalda la versión anterior. *Comparar* muestra un
  diff línea por línea contra lo que hay en el editor; *Restaurar* la vuelve activa
  (y respalda antes lo actual, así que restaurar nunca destruye nada).
- **Probar IA**: envía una pregunta real a `resolver.php` para confirmar que el
  asistente responde con la documentación ya publicada.
- **Estado**: líneas, palabras, caracteres y una estimación de *tokens de contexto*.
  Ese número importa: el archivo completo viaja a Gemini en cada consulta, así que
  el contador se pone ámbar sobre ~60 000 tokens y rojo sobre ~120 000.
- `Ctrl+S` guarda, `Ctrl+F` busca, y un borrador local se recupera si se cierra el
  navegador sin publicar.

## Protecciones

- Contraseña con bcrypt; 5 intentos fallidos bloquean la IP por 15 minutos.
- Cookie de sesión `HttpOnly` + `SameSite=Strict`, y cierre por 2 h de inactividad.
- Token CSRF en toda operación de escritura.
- Bloqueo optimista: si alguien más guardó mientras editabas, el guardado se detiene
  y avisa antes de pisar su trabajo.
- Escritura atómica (archivo temporal + `rename`): no queda un `qpaypro_docs.txt`
  a medias si algo falla.
- Nombre de versión validado contra un patrón estricto (sin `../`).
- `.htaccess` niega el acceso web a `.env`, `*.sqlite`, `*.sql` y `docs_versions/`.
  Requiere `AllowOverride All`, que el `Dockerfile` ya configura — **si despliegas
  sin ese Dockerfile, `.env` queda expuesto por HTTP**.

## Requisitos de despliegue

El directorio de la app debe ser escribible por el usuario de Apache (`www-data`),
porque el panel guarda `qpaypro_docs.txt` y crea `docs_versions/`.

```bash
docker compose up -d --build   # el --build es necesario: ahora se compila el Dockerfile
```

En Render, el sistema de archivos es efímero: los cambios publicados desde el panel
se pierden al redesplegar. Para que persistan hay que montar un disco o versionar
`qpaypro_docs.txt` en el repositorio después de editarlo (el botón *Descargar* del
panel entrega el archivo listo para hacer commit).
