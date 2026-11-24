# 04 · CI/CD: deploy automático a producción

Workflow: [`.github/workflows/deploy.yml`](../../.github/workflows/deploy.yml)

## 1. Cómo funciona

```
push / merge a `main`
        │
        ▼
 ┌─────────────┐   ┌────────────────────────────────────────────┐   ┌──────────────┐
 │ Job: test   │──►│ Job: deploy                                │──►│ Smoke test   │
 │ phpunit     │   │ composer --no-dev → verifica build → FTPS  │   │ /health  200 │
 │ (1084 tests)│   │ (solo archivos que cambiaron)              │   │ /login   401 │
 └─────────────┘   └────────────────────────────────────────────┘   └──────────────┘
   si falla: NO se sube nada
```

| Elemento | Valor |
|----------|-------|
| Disparador | `push` a `main` (un merge de PR es un push), salvo cambios solo de docs/specs/migraciones (`paths-ignore`) |
| Disparo manual | `workflow_dispatch` con opción **dry-run** (marcada por defecto) |
| Concurrencia | Un deploy a la vez; uno en curso nunca se cancela |
| Mecanismo | `SamKirkland/FTP-Deploy-Action@v4.4.0`, FTPS puerto 21 (igual que `api.devrob.net`) |
| Destino | `api-not.devrob.net/` en el home del usuario FTP |
| Environment de GitHub | `production` (aparece en la pestaña *Deployments* con la URL) |

### Diferencias con los workflows de `api.devrob.net` / `BarberApp-backend`

| | Repos hermanos | Este repo | Por qué |
|---|----------------|-----------|---------|
| Rama | `main` | `main` | Misma |
| Tests antes de subir | No | **Sí** | Hay 1084 tests unitarios; un deploy roto no debe salir |
| `vendor/` | Ya está en el repo / no usan composer | **Se construye en CI** (`composer install --no-dev -o`) | Está en `.gitignore`; el servidor no ejecuta composer |
| Verificación posterior | No | **Smoke test** | Detecta `.env`/BD/JWT mal configurados al instante |
| Dry-run | No | Sí (manual) | Primer ensayo sin riesgo |

## 2. Qué sube y qué NO sube

El análisis se hizo sobre los 2710 archivos que hay en el checkout + `vendor/`, y se comprobó con una simulación de los patrones `exclude` (minimatch, la misma librería del action).

### Sube (≈ 680 archivos, ≈ 12 MB)

| Ruta | Archivos | Motivo |
|------|----------|--------|
| `public/` | 2 | `index.php`, `.htaccess`: único contenido accesible desde internet |
| `src/` | 205 | Código de la app (incluye `templates/statement.php` que usa dompdf) |
| `vendor/` | ≈ 470 | Solo runtime: `firebase/php-jwt`, `vlucas/phpdotenv`, `dompdf/*`, `sabberworm/php-css-parser`, `masterminds/html5`, `chillerlan/*`, `symfony/polyfill-*`, `graham-campbell/result-type`, `phpoption`, `composer/*` |

### No sube

| Excluido | Por qué |
|----------|---------|
| `.git*`, `.gitignore`, `.gitattributes` | Control de versiones |
| `.github/` | CI/CD |
| `.idea/`, `.vscode/` | IDE |
| `.claude/`, `.mcp*` | Claude Code |
| `.env`, `.env.*` (incluye `.env.example`) | **Secretos.** El `.env` de producción vive solo en el servidor |
| `docker/`, `docker-compose.yml` | Entorno local; en cPanel no se usa (y trae un CORS `*` de desarrollo) |
| `docs/` (`openapi.yaml`) | Contrato de la API; no es runtime |
| `documentation/` | Esta documentación de releases |
| `legacydocs/` | Documentación de la v1 |
| `specs/` | Specs de producto/arquitectura/QA (incluyen logs y scripts de pruebas) |
| `README.md` | Documentación |
| `.db/` | **Migraciones**: se ejecutan a mano en la BD, no en el servidor web |
| `tests/`, `.test_data/`, `.phpunit.cache/`, `phpunit.xml.dist` | Pruebas |
| `composer.json`, `composer.lock` | El servidor no corre composer; `vendor/composer/installed.json` ya describe lo instalado |
| `vendor/bin/`, `vendor/**/tests/`, `vendor/**/Tests/`, `vendor/**/test/` | Peso muerto de las dependencias |

### Protegido (existe en el servidor y el pipeline jamás lo toca)

| Ruta | Contenido |
|------|-----------|
| `.env` | Configuración y secretos de producción |
| `public/assets/` | **Logos de las notarías** subidos desde la app (`public/assets/{id}/logo/`). Está en `.gitignore` y además excluido explícitamente |
| `public/uploads/`, `uploads/`, `storage/`, `logs/`, `*.log` | Por si la app los usa en el futuro |
| `.ftp-deploy-sync-state.json` | Estado del deploy, lo gestiona el action |

> **Por qué el deploy no borra nada protegido:** `FTP-Deploy-Action` solo elimina archivos que *él mismo* subió antes y que ya no existen en el repo. Nunca barre carpetas. Nunca actives `dangerous-clean-slate: true`: borraría `.env` y los logos.

### Si algún día se agrega una carpeta nueva

El `exclude` es una **lista negra**: lo que no esté listado, sube. Antes de crear carpetas en la raíz (por ejemplo `scripts/`, `storage/`, `bin/`), decide si va al servidor y, si no, agrégala al `exclude` del workflow en el mismo PR. Con el Document Root en `public/` una carpeta de más no queda expuesta en internet, pero igual no debe viajar al servidor.

## 3. Secrets de GitHub

Repo `robertdroid/api-notarial` (hoy tiene **0 secrets**; los de los repos hermanos no se heredan):

```bash
gh secret set FTP_SERVER   -R robertdroid/api-notarial   # host FTP de cPanel (el mismo de api.devrob.net)
gh secret set FTP_USERNAME -R robertdroid/api-notarial
gh secret set FTP_PASSWORD -R robertdroid/api-notarial
gh secret list -R robertdroid/api-notarial
```

`gh` pide el valor de forma interactiva, así no queda en el historial del shell. No necesitas `CLAUDE_CODE_OAUTH_TOKEN` ni `SMTP_PASSWORD` (los repos hermanos los usan para otros workflows).

**Recomendado — cuenta FTP dedicada.** Si reutilizas el usuario FTP de `api.devrob.net`, este repo tendría acceso a todo ese hosting. En cPanel → **FTP Accounts** crea un usuario cuyo directorio sea `api-not.devrob.net/` y úsalo aquí. En ese caso cambia en el workflow `server-dir: api-not.devrob.net/` por `server-dir: ./`.

## 4. Primera ejecución

El primer run **sube todo** (~680 archivos), porque no existe `.ftp-deploy-sync-state.json`. Por FTPS puede tomar varios minutos; el job tiene límite de 30 min. A partir del segundo deploy solo suben los archivos con diferente hash (normalmente unos pocos de `src/` y los índices del autoloader en `vendor/composer/`).

Ensayo sin riesgo: Actions → **Deploy to cPanel (production)** → *Run workflow* con **dry-run** marcado (o `gh workflow run deploy.yml --ref main -f dry-run=true`). Lista lo que se subiría y no modifica el servidor; el smoke test se omite. Solo está disponible **después** del primer merge a `main` (el workflow tiene que existir en la rama por defecto), así que el primer deploy es real: por eso la verificación previa de servidor, `.env` y BD es obligatoria.

Orden de la primera liberación (el detalle está en [`01_first_release_checklist.md`](01_first_release_checklist.md)):

1. Servidor, `.env` y BD listos; secrets cargados (ya están).
2. Código pendiente mergeado a `develop` (CORS/`.htaccess`, workflow, docs).
3. PR `develop → main`; el merge dispara el primer deploy.

> Cada push a `main` despliega, salvo los que solo tocan `specs/`, `documentation/`, `docs/`, `legacydocs/`, `.db/`, `.claude/`, `tests/` o `README.md` (`paths-ignore` del workflow).

## 5. El smoke test

Corre tras la subida, contra `https://api-not.devrob.net`, con reintentos (hasta ~1 min). Son dos pasos:

**Paso 1 — App y header `Authorization` (estricto, no necesita BD)**

| Llamada | Esperado | Qué comprueba |
|---------|----------|---------------|
| `GET /api/v3/health` | `200` y `"status":"ok"` | Apache/DNS/SSL, document root, PHP, `vendor/` y `.env` cargados (el bootstrap falla sin `.env`) |
| `GET /api/v3/me` con `Bearer` basura | `401` con *"Token inválido o expirado"* | Que el header `Authorization` **llega a PHP** (hallazgo B2). Si responde *"Token no proporcionado"*, Apache lo descartó: falta el `SetEnvIf` de 02 §5 |

**Paso 2 — Base de datos y JWT (modo advertencia hasta migrar)**

| Llamada | Esperado | Qué comprueba |
|---------|----------|---------------|
| `POST /api/v3/auth/login` con usuario inexistente | `401` | Credenciales de BD válidas, `usuarios_notaria.password_hash` (migración 1) y `JWT_SECRET` |

Este paso es `continue-on-error` mientras la variable `SMOKE_DB_STRICT` no valga `true`: si da 500 (esperado antes de migrar) el run sigue en verde con una advertencia. **Al terminar las migraciones**, actívalo:

```bash
gh variable set SMOKE_DB_STRICT --body true -R robertdroid/api-notarial
```

A partir de ahí, un 500 en el login vuelve a poner el pipeline en rojo. Lo que **no** prueba el smoke test: CORS (B1) ni la creación de transacciones; eso se verifica a mano en la fase 8 del checklist.

## Deploy "a oscuras" (API arriba, BD sin migrar)

Se puede desplegar el código antes de migrar, con estas condiciones:

- El login de la v3 responde 500 hasta aplicar la migración 1, así que **nadie puede usar la API todavía**. La v1 no se ve afectada: es otra aplicación.
- **No conectes el frontend ni des acceso a usuarios hasta terminar las migraciones.** Sin la migración 1 no hay login, y sin las de InnoDB (9, 11, 13) las escrituras multi-tabla de la v3 (transacciones, escrituras con participantes) **no son atómicas** en MyISAM.

## 6. Troubleshooting

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `530 Login authentication failed` | Secrets mal puestos | Revisa `FTP_*`; prueba las credenciales con FileZilla |
| Timeout / `ECONNRESET` a mitad de subida | FTP inestable en el primer run | **Re-run failed jobs**. Vuelve a subir lo que falte (sobrescribir es inocuo) |
| Error de certificado TLS | Certificado del host FTP no coincide con `FTP_SERVER` | Usa el hostname del certificado; como último recurso `security: loose` en el action |
| Smoke `health` ≠ 200 | Document Root mal, SSL, `.htaccess`, `vendor/` incompleto | Abre la URL en el navegador; revisa el Document Root (02 §2) |
| Smoke `login` = 500 | `.env` incompleto, credenciales de BD, falta `password_hash`, falta `JWT_SECRET` | Habilita temporalmente `APP_DEBUG=true` (y desactívalo después) o revisa *Errors* en cPanel |
| `Class … not found` | Autoloader desactualizado en el servidor | Re-run del workflow; si persiste, borra `.ftp-deploy-sync-state.json` del servidor y vuelve a correr (re-sube todo) |
| Login OK pero resto 401 | `Authorization` no llega a PHP (B2) | Agrega el `SetEnvIf` de 02 §5 |
| Frontend: error CORS | Falta CORS en `.htaccess` (B1) o dominio distinto | 02 §5 |

## 7. Rollback

El pipeline despliega lo que haya en `main`; revertir es publicar el commit anterior (por PR, con `main` protegida):

```bash
git checkout main && git pull
git checkout -b revert/release-X
git revert -m 1 <sha-del-merge>      # o git revert <sha> si fue un commit directo
git push -u origin revert/release-X     # abrir PR a main; al mergearlo se despliega la versión anterior
```

Aplican las mismas reglas: tests → deploy → smoke test. Si el problema es **de datos** (migración), el código no lo arregla: ver el rollback en [03 §8](03_database_migrations.md).

Un fallo a mitad de subida deja el servidor con una mezcla de archivos viejos y nuevos durante unos minutos; **re-ejecuta** el workflow, es idempotente.

## 8. Buenas prácticas del flujo

- Protege `main` (Settings → Branches): requerir PR, sin push directo ni force-push. Todo release entra por PR `develop → main`.
- Etiqueta cada release: `gh release create vX.Y.Z --target main` y mueve las migraciones a `.db/executed/vX.Y.Z/`.
- Si algún día agregas un entorno de staging, duplica el workflow con otra rama, otro `server-dir` y otro `API_URL`.
- Actualiza `FTP-Deploy-Action`, `checkout` y `setup-php` de forma deliberada (Dependabot o revisión manual); el workflow fija versiones.
