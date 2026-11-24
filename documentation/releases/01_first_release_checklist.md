# 01 · Checklist de la primera liberación

La liberación va en **dos etapas**, porque la v1 sigue en uso y las migraciones deben esperar al frontend:

| Etapa | Qué | Cuándo |
|-------|-----|--------|
| **1 · Deploy "a oscuras"** | Se sube la API a `api-not.devrob.net` **sin tocar la BD**. Nadie la usa todavía | Ahora |
| **2 · Día de migraciones** | Backup, resolver datos, aplicar las 13 migraciones, activar el smoke test estricto, conectar el frontend | Cuando el frontend esté listo |

Mientras la BD no esté migrada, el **login de la v3 responde 500** (falta `usuarios_notaria.password_hash`). Es lo esperado: la v1 no se ve afectada y nadie debe usar la v3 aún. Detalle en [04 · Deploy a oscuras](04_ci_cd_deploy.md).

Detalle de cada punto: [02 servidor](02_server_configuration.md) · [03 base de datos](03_database_migrations.md) · [04 CI/CD](04_ci_cd_deploy.md)

---

# ETAPA 1 — Deploy a oscuras (solo la API)

## Fase 0 — Decisiones previas

- [x] Rama de release: **`main`** (el workflow escucha `main`; los secrets FTP ya están cargados)
- [x] Hosting cPanel: `/home/devrobne/api-not.devrob.net` (FTP con home en `/home/devrobne`, `server-dir: api-not.devrob.net/`)
- [x] BD de producción: **la legacy de la v1**, `devrobne_sisnot2018` (MariaDB 11.4.13, 15 tablas de la API, todas MyISAM)
- [x] Orígenes CORS: `https://notarialv2.devrob.net` (frontend actual) y `https://notarial.devrob.net` (definitivo)

## Fase 1 — Servidor cPanel

- [x] DNS y subdominio `api-not.devrob.net` con **Document Root = `api-not.devrob.net/public`** (B3, verificado: `/.env` → 403)
- [x] SSL activo
- [x] PHP **8.5**, extensiones e INI (02 §3)
- [x] Logo de las notarías copiado a `public/assets/logo_notaria_24.png` (B7)
- [ ] `mod_headers` y `mod_rewrite` disponibles (estándar en cPanel; se comprueba en la fase 4)

## Fase 2 — `.env` en el servidor

- [x] `~/api-not.devrob.net/.env` creado desde [`.env.production.example`](.env.production.example), con `chmod 600`
- [ ] Revisar que tenga: `DB_DATABASE=devrobne_sisnot2018`, `DB_USERNAME=devrobne_notarial_v3` (usuario propio con SELECT/INSERT/UPDATE/DELETE), `APP_DEBUG=false`, `JWT_SECRET` generado con `openssl rand -base64 48`

## Fase 3 — Cambios de código (PR a `develop`)

Estos cambios **no están hechos** en el repo; sin ellos el frontend no funciona.

- [ ] `public/.htaccess`: agregar CORS y `SetEnvIf Authorization` (02 §5), hallazgos B1 y B2
- [ ] `docs/openapi.yaml`: servidor de producción → `https://api-not.devrob.net/api/v3`
- [ ] Incluir `.github/workflows/deploy.yml`, `documentation/releases/` y el arreglo de `MoneyFormatterTest` (PHP 8.5)
- [ ] PR revisado y mergeado a `develop` (flujo habitual: dev crea, QA cierra)
- [x] Protección de `main`: requerir PR, sin push directo ni force-push (aplicada con `gh api`, también a admins; sin status checks porque no hay CI en PR)

## Fase 4 — Release de la API

- [ ] (Opcional, recomendado) Ejecutar el workflow a mano con **dry-run**: Actions → *Deploy Production* → *Run workflow* (`dry-run` = true) y revisar la lista de archivos
- [ ] Abrir PR `develop → main`, revisar y hacer **merge** → dispara el workflow
- [ ] Seguir la ejecución: `gh run watch -R robertdroid/api-notarial`
- [ ] **Tests → Deploy → Smoke test** en verde. En el smoke test, el paso 2 (login/BD) puede salir con aviso: es lo esperado hasta migrar
- [ ] Primer deploy: ~680 archivos (~12 MB); puede tardar varios minutos por FTPS

## Fase 5 — Verificación (sin BD migrada)

```bash
API=https://api-not.devrob.net/api/v3

curl -i $API/health                                           # 200 {"status":"ok",...}
curl -i $API/me -H 'Authorization: Bearer x.y.z'              # 401 "Token inválido o expirado" → el header llega a PHP (B2)
curl -i $API/me                                               # 401 "Token no proporcionado"

curl -i -X OPTIONS $API/me \
     -H 'Origin: https://notarialv2.devrob.net' \
     -H 'Access-Control-Request-Method: GET' \
     -H 'Access-Control-Request-Headers: authorization'       # trae Access-Control-Allow-Origin → B1
```

- [ ] `health` → 200
- [ ] `/me` con token falso → 401 **"inválido"** (si dice "no proporcionado", falta el `SetEnvIf`)
- [ ] Preflight CORS devuelve `Access-Control-Allow-Origin: https://notarialv2.devrob.net` (y lo mismo con el origen `notarial`)
- [ ] `https://api-not.devrob.net/.env` y `/.ftp-deploy-sync-state.json` responden **403 o 404** (nunca 200)
- [ ] La v1 sigue funcionando con normalidad (no se ha tocado la BD)

> **No conectes el frontend ni des acceso a usuarios en esta etapa.**

---

# ETAPA 2 — Día de migraciones (cuando el frontend esté listo)

SQL completo y resolución de datos en [03](03_database_migrations.md).

## Fase 6 — Antes de migrar

- [ ] Elegir ventana de baja actividad (los `ALTER … ENGINE=InnoDB` bloquean escrituras mientras dura)
- [ ] **Backup completo del mismo día** de `devrobne_sisnot2018` y descargarlo (fuera del servidor)
- [ ] Volver a correr los pre-checks A–G (03 §3): la v1 sigue escribiendo y pudo crear duplicados nuevos
- [ ] Resolver los datos ya identificados (03 §4): actos duplicados (A) y transacción duplicada (C)
- [ ] Tarea 0.1 de los specs 11 y 17: confirmar que la v1 genera `uuid` al insertar en `payments_*`

## Fase 7 — Migrar

- [x] Aplicar las 13 migraciones **en orden**, una por una (03 §5)
- [ ] Verificar el resultado (03 §6)
- [ ] Activar el smoke test estricto:
  ```bash
  gh variable set SMOKE_DB_STRICT --body true -R robertdroid/api-notarial
  ```
- [ ] Relanzar el workflow (*Run workflow*, `dry-run` = false) y comprobar que el smoke test pasa completo

## Fase 8 — Verificación con BD migrada

- [ ] Login con un usuario real → 200 (la primera vez migra su contraseña de MD5 a bcrypt)
- [ ] `GET /me` con el token → 200
- [ ] La v1 sigue permitiendo login (la columna `contrasenia` MD5 se conserva)
- [ ] Un PDF (recibo y estado de cuenta) se genera y muestra el logo
- [ ] Subir un logo de notaría funciona y queda en `public/assets/{id}/logo/`
- [ ] El frontend inicia sesión y lista datos contra la URL de producción

## Fase 9 — Cierre

- [x] Mover las migraciones aplicadas: `.db/applied/*` → `.db/executed/v1.0.0/` (según `.db/README.md`), por PR
- [ ] Crear el tag y release: `gh release create v1.0.0 -R robertdroid/api-notarial --target main`
- [ ] Guardar el backup hasta confirmar estabilidad
- [ ] Anotar en `specs/_index.md` la fecha del release

---

## Rollback rápido

| Qué falló | Qué hacer |
|-----------|-----------|
| Código con bug | `git revert` del merge en `main` → push → el workflow redepliega la versión anterior (04 §7) |
| Migración | Restaurar el backup del mismo día. Las migraciones `dedupe` y `normalize_participant_status` **no son reversibles por SQL** |
| `.env` mal | Editarlo en el servidor; no requiere deploy |
