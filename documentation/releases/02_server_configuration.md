# 02 · Configuración del servidor (cPanel)

Todo lo que hay que configurar **una sola vez** en el servidor antes del primer deploy. El pipeline nunca toca nada de esto.

## 1. Estructura final en el servidor

```
/home/devrobne/
└── api-not.devrob.net/              ← server-dir del workflow (raíz del proyecto)
    ├── .env                         ← lo creas tú a mano. NUNCA lo sube el pipeline
    ├── .ftp-deploy-sync-state.json  ← lo crea el pipeline (estado de lo ya subido)
    ├── public/                      ← DOCUMENT ROOT del subdominio
    │   ├── index.php                ← subido por el pipeline
    │   ├── .htaccess                ← subido por el pipeline
    │   └── assets/{notaryId}/logo/  ← lo crea la app en runtime (logos). El pipeline no lo toca
    ├── src/                         ← subido por el pipeline
    └── vendor/                      ← subido por el pipeline (solo dependencias de runtime)
```

`BASE_PATH` en `public/index.php` es `dirname(__DIR__)`, o sea la carpeta que contiene `public/`. Por eso el `.env` va en esa carpeta y **no** dentro de `public/`.

## 2. Subdominio y Document Root  *(hallazgo B3)*

cPanel → **Domains** → **Create A New Domain** (o editar el existente):

| Campo | Valor |
|-------|-------|
| Domain | `api-not.devrob.net` |
| Document Root | `api-not.devrob.net/public` |

Con el Document Root en `public/`, el `.env`, `src/`, `vendor/` y el archivo de estado del deploy quedan **fuera del alcance de internet**, que es el único aislamiento real (la app no tiene reglas que bloqueen esos archivos).

> `api.devrob.net` usa un `.htaccess` en la raíz que reenvía todo a `/public/`. **No lo copies aquí**: serviría la raíz del proyecto como Document Root y expondría `.env` y `.ftp-deploy-sync-state.json`. Si cPanel no te deja cambiar el Document Root, avísame y preparo un `.htaccess` raíz endurecido como alternativa.

También:
- **SSL**: SSL/TLS Status → AutoSSL para `api-not.devrob.net`.
- **Force HTTPS Redirect**: Domains → interruptor del dominio. Es obligatorio: viajan JWT y contraseñas.

## 3. PHP

cPanel → **MultiPHP Manager**: asignar PHP **8.5** al dominio (el servidor ya lo tiene; mínimo soportado 8.2, ver `composer.lock`). La suite completa (1084 tests) se verificó en PHP 8.5.13 con las extensiones `gd`, `pdo_mysql`, `mbstring`, `zip` y `fileinfo`; se corrigió un test incompatible con 8.5 (`setlocale(LC_NUMERIC, 0)`). El código de `src/` no requirió cambios.

> El `Dockerfile` local sigue en PHP 8.3 (`FROM php:8.3-apache`). Conviene subirlo a 8.5 para que desarrollo y producción coincidan; no es bloqueante.

### Extensiones (MultiPHP / Select PHP Version)

| Extensión | Para qué |
|-----------|----------|
| `pdo_mysql` | Conexión a MariaDB |
| `mbstring` | Validaciones (`mb_strlen`), dompdf |
| `gd` (con **JPEG y PNG**) | QR de los recibos (`QRGdImagePNG`), logos |
| `fileinfo` | `finfo` para validar el MIME del logo |
| `dom`, `xmlwriter`, `libxml` | dompdf / php-svg-lib |
| `iconv`, `zlib`, `ctype`, `json`, `filter` | dompdf y polyfills |
| `sodium` | Opcional |

Local (Docker) tiene: `dom fileinfo gd iconv mbstring pdo_mysql sodium xmlwriter zip zlib`. Si el servidor tiene esas, está cubierto.

### Valores de `php.ini` (MultiPHP INI Editor → Editor Mode)

```ini
display_errors = Off
log_errors = On
memory_limit = 256M
max_execution_time = 60
upload_max_filesize = 2M
post_max_size = 4M
```

- `display_errors = Off` es importante: `src/bootstrap.php` corre **fuera** del `try/catch` de `index.php`, así que un error de arranque (por ejemplo, `.env` ausente) se mostraría en pantalla con rutas del servidor.
- `memory_limit`: dompdf renderiza los PDFs en memoria; un estado de cuenta largo puede pasar de 128 MB.
- `upload_max_filesize`: el logo admite hasta 512 KB, con holgura.
- `date.timezone` no hace falta: `index.php` ya fija `APP_TIMEZONE`.

## 4. `.env` de producción  *(hallazgo B6)*

1. Copia [`.env.production.example`](.env.production.example) a `~/api-not.devrob.net/.env` (cPanel File Manager, o `scp`).
2. Reemplaza los valores marcados `CAMBIAR…`.
3. `chmod 600 .env`.

### Variables

| Variable | Producción | ¿La usa el código? | Notas |
|----------|------------|--------------------|-------|
| `APP_ENV` | `production` | No (solo docker-compose) | Informativa |
| `APP_DEBUG` | **`false`** | Sí (`index.php`) | Con `true`, los 500 devuelven el mensaje de la excepción |
| `APP_URL` | `https://api-not.devrob.net` | No | Informativa |
| `APP_NAME` | `NotarialAPI` | No | Informativa |
| `APP_TIMEZONE` | `America/Mexico_City` | Sí | |
| `DB_HOST` | `localhost` | Sí, **requerida** | En cPanel la BD está en el mismo servidor |
| `DB_PORT` | `3306` | Sí | |
| `DB_DATABASE` | `devrobne_<nombre>` | Sí, **requerida** | cPanel antepone el usuario de la cuenta (`devrobne_`) |
| `DB_USERNAME` | `devrobne_<nombre>` | Sí, **requerida** | Con todos los privilegios sobre esa BD |
| `DB_PASSWORD` | generada | Sí | Entre comillas simples si tiene `#`, `$` o espacios |
| `JWT_SECRET` | `openssl rand -base64 48` | Sí, **requerida** | Distinta a la de desarrollo. Rotarla cierra todas las sesiones |
| `JWT_EXPIRATION` | `7200` | Sí | Access token, 2 h |
| `JWT_REFRESH_EXPIRATION` | `86400` | Sí | Refresh token, 24 h |
| `JWT_ISSUER` | `security@devrob` | Sí | Si no se define, ese es el default |
| `JWT_AUDIENCE` | `apps@devrob` | Sí | Si no se define, ese es el default |
| `DB_ROOT_PASSWORD` | **no incluir** | No | Solo lo usa docker-compose en local |

Si falta una variable **requerida** (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `JWT_SECRET`) la API responde 500 en cualquier ruta que la use. Si falta el archivo `.env` completo, falla el arranque.

Tu `.env` local no define `JWT_ISSUER` ni `JWT_AUDIENCE` (funciona por los defaults). En producción defínelas explícitamente para que el comportamiento no dependa de un default del código.

## 5. `public/.htaccess`: CORS y header Authorization  *(hallazgos B1 y B2)*

El `public/.htaccess` actual solo hace el front controller. Falta lo siguiente, y **es un cambio de código** que debe ir en el PR de la fase 5 del checklist.

### B1 — CORS

En PHP no hay ningún header CORS (`Router` solo responde 200 al `OPTIONS`). En local lo pone `docker/apache/vhost.conf` con `Access-Control-Allow-Origin: *`, pero ese archivo **no se usa en cPanel** y el `docker/` ni siquiera se sube. Sin esto, el navegador bloquea toda llamada del frontend.

### B2 — Authorization bajo FastCGI

`Request::createFromGlobals()` arma los headers desde `$_SERVER['HTTP_*']`. Bajo PHP-FPM/FastCGI, Apache no siempre pasa `Authorization` a PHP; la regla `RewriteRule … E=HTTP_AUTHORIZATION` que ya existe a veces llega como `REDIRECT_HTTP_AUTHORIZATION`, que el código no lee. Síntoma: el login funciona y todo lo demás da **401**. No lo puedo comprobar sin el servidor; se prueba en la etapa 1, fase 5 del checklist (`GET /me` con token falso). Agregar `SetEnvIf` es inocuo aunque no hiciera falta.

### Ya implementado en `public/.htaccess`

El PR de la fase 3 del checklist incluye estos bloques al inicio de `public/.htaccess` (consulta el archivo, es la fuente de verdad):

- `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` (B2), además de la `RewriteRule` que ya existía.
- CORS con origen reflejado, nunca `*`: solo `https://notarialv2.devrob.net` (frontend actual) y `https://notarial.devrob.net` (definitivo). Regex: `^(https://(notarialv2|notarial)\.devrob\.net)$`. Los headers `Access-Control-*` solo se envían si el `Origin` coincide (`env=CORS_ORIGIN`).
- El preflight `OPTIONS` lo contesta el router de PHP con 200; Apache le agrega los headers CORS.

Notas:

- La API solo usa `GET`, `POST`, `PUT`, `DELETE` (más `OPTIONS` del preflight); por eso no se incluye `PATCH`.
- `Content-Disposition` se expone porque los PDFs salen con `inline; filename="…"` y el frontend puede necesitar el nombre.
- Para quitar `notarialv2` cuando ya no se use, o agregar otro origen, edita la regex y haz un PR normal.
- Se verifica en la etapa 1, fase 5 del checklist (preflight con `curl` y `GET /me` con token falso).

## 6. Permisos

| Ruta | Permiso | Motivo |
|------|---------|--------|
| `.env` | `600` | Contiene secretos |
| carpetas | `755` | Estándar |
| archivos | `644` | Estándar |
| `public/` | escribible por el usuario de PHP | La app crea `public/assets/{id}/logo/` con `mkdir(…, 0755, true)` |

En cPanel con PHP-FPM/suexec, PHP corre con el mismo usuario que el FTP, así que no hace falta nada especial.

## 7. Archivos persistentes: logos  *(hallazgo B7)*

- v3 guarda el logo en `public/assets/{notaryId}/logo/logo_{notaryId}.{jpg|png}` y registra en `notarias.logo` la ruta relativa `assets/{notaryId}/logo/logo_{notaryId}.ext`.
- Para leerlo usa `BASE_PATH . '/public/' . notarias.logo`.
- La v1 documentaba rutas como `/assets/logos/notaria25.png`.

Antes del release, en la BD de producción:

```sql
SELECT id_notaria, logo FROM notarias WHERE logo <> '';
```

Para cada fila, el archivo debe existir en `public/<valor de logo>` del servidor. Si hay logos de la v1 en otra ruta, cópialos a mano (o re-súbelos desde la app). El pipeline **jamás** toca `public/assets/` (está excluido y además ignorado por git), así que lo que copies ahí sobrevive a todos los deploys.

## 8. Cron y servicios externos

La API **no usa** cron jobs, colas, SMTP ni servicios externos. No hay nada más que configurar.

## 9. Referencias del código

| Qué | Dónde |
|-----|-------|
| Carga de `.env` | `src/bootstrap.php` (`Dotenv::createImmutable(BASE_PATH)`) |
| Lectura de config | `src/Core/Config/Config.php` |
| Conexión a BD | `src/Core/Database/Connection.php` |
| JWT | `src/Module/Auth/Service/JwtService.php` |
| Logos | `src/Module/Notary/Service/NotaryService.php` |
| PDFs (dompdf, temp dir) | `src/Shared/Pdf/DompdfDocumentRenderer.php` (usa `sys_get_temp_dir()/dompdf-fonts`) |
