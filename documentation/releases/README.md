# Releases — Notarial API v3

Documentación para llevar la API a producción: **https://api-not.devrob.net**

| # | Documento | Para qué sirve |
|---|-----------|----------------|
| 01 | [`01_first_release_checklist.md`](01_first_release_checklist.md) | **Empieza aquí.** Checklist ordenado de la primera liberación, de punta a punta |
| 02 | [`02_server_configuration.md`](02_server_configuration.md) | cPanel, Document Root, PHP, `.env` de producción, `.htaccess`, permisos |
| 03 | [`03_database_migrations.md`](03_database_migrations.md) | Las 13 migraciones: orden, pre-checks, backup, riesgos |
| 04 | [`04_ci_cd_deploy.md`](04_ci_cd_deploy.md) | GitHub Action de deploy: qué sube, qué NO sube y por qué, secrets, rollback |
| — | [`.env.production.example`](.env.production.example) | Plantilla del `.env` que vive **solo** en el servidor |

El workflow está en [`.github/workflows/deploy.yml`](../../.github/workflows/deploy.yml).

---

## Resumen ejecutivo

**Arquitectura de producción**

```
merge a `main` ──► GitHub Actions ──► tests (1084) ──► composer --no-dev ──► FTPS ──► cPanel
                                                                                       │
                                  smoke test  ◄──────────────  https://api-not.devrob.net
```

- Hosting cPanel (Apache + PHP-FPM), mismo patrón de deploy que `api.devrob.net` y `BarberApp-backend` (FTPS + `FTP-Deploy-Action`).
- Solo se suben **`public/`, `src/` y `vendor/` (runtime)**: ~680 archivos, ~12 MB en el primer deploy. Los siguientes deploys suben solo lo que cambió.
- No se suben: `.claude`, `specs`, `docs`, `documentation`, `legacydocs`, `.db` (migraciones), `tests`, `docker`, `.github`, `.env*`, `README.md`, `composer.json/lock`, etc. Detalle en el doc 04.
- Nunca se toca en el servidor: `.env`, `public/assets/` (logos de las notarías) y el estado del deploy.

## Hallazgos que bloquean el go-live

Cosas que encontré revisando el código y que **no están resueltas en el repo**. El detalle y la solución de cada una está en los documentos indicados.

| # | Hallazgo | Impacto si no se atiende | Doc |
|---|----------|--------------------------|-----|
| B1 | **No hay CORS en PHP.** Solo existe en `docker/apache/vhost.conf`, que cPanel no usa | El frontend no puede llamar a la API desde el navegador (preflight falla) | 02 §5 |
| B2 | **El header `Authorization` puede no llegar a PHP** bajo FastCGI/PHP-FPM; el código solo lee `HTTP_AUTHORIZATION` | Login funciona pero todos los endpoints protegidos responden 401 | 02 §5 |
| B3 | **El Document Root debe ser `public/`**, no la raíz del proyecto | Si apunta a la raíz quedan expuestos `.env`, `src/`, `vendor/` y el archivo de estado del deploy | 02 §2 |
| B4 | **No hay DDL base versionado** (`docker/mysql/init/` está vacío; las tablas `payment_*` solo aparecen en specs). Las migraciones asumen que las tablas ya existen | En una BD vacía las migraciones fallan; solo sirven sobre la BD legacy de la v1 | 03 §1 |
| B5 | **La v1 sigue escribiendo en las mismas tablas** y varias migraciones convierten MyISAM→InnoDB y agregan índices únicos | Bloqueo de tablas, o migración que falla por duplicados en datos reales | 03 |
| B6 | **`.env` inexistente = fatal.** `Dotenv->load()` corre en el bootstrap, fuera del `try/catch` | Error 500/fatal en todas las rutas hasta que se cree el `.env` | 02 §4 |
| B7 | **Logos:** v3 los guarda/lee en `public/assets/{id}/logo/`; la v1 usaba rutas como `/assets/logos/notaria25.png` | Notarías existentes sin logo en recibos y estados de cuenta PDF | 02 §7 |

Pendientes menores (no bloquean): `docs/openapi.yaml` apunta a `https://api.notarial.devrob.net` (debe ser `api-not.devrob.net`); el endpoint `/health` devuelve `version` fija `3.0.0`; `APP_NAME`, `APP_ENV` y `APP_URL` existen en `.env` pero el código no los lee.

## Decisiones tomadas (confirma o corrige)

| Decisión | Valor asumido | Por qué |
|----------|---------------|---------|
| Rama que dispara el deploy | `main` (confirmado) | Cada push a `main` despliega, salvo cambios solo de docs/specs/migraciones |
| Mecanismo | FTPS a cPanel con `FTP-Deploy-Action@v4.4.0` | Es lo que ya usas en `api.devrob.net` y `BarberApp-backend` |
| Carpeta en el servidor | `/home/devrobne/api-not.devrob.net` (confirmado). Document Root → `…/public` | FTP con home en `/home/devrobne` → `server-dir: api-not.devrob.net/` |
| PHP en producción | 8.5 (confirmado; mín. 8.2 por `composer.lock`) | El CI corre en 8.5. Suite verificada en 8.5.13 |
| Origen permitido por CORS | `https://notarialv2.devrob.net` (actual) y `https://notarial.devrob.net` (definitivo) | Confirmado; ambos van en la regex de `public/.htaccess` |
| Migraciones | Manuales, fuera del pipeline, **en una segunda etapa** (cuando el frontend esté listo) | La v1 sigue en uso; la API se despliega primero "a oscuras" con la BD sin migrar (login v3 da 500 hasta entonces) |
| `vendor/` | Se construye en CI y se sube | Está en `.gitignore` y el servidor no corre composer |
