# Resultados de Pruebas: Payment Clients CRUD

## Resumen Ejecutivo
- **Estado:** APROBADO
- **Tests Ejecutados:** 241 unitarios + 79 casos de API (+ verificaciones de BD)
- **Tests Pasados:** todos
- **Tests Fallidos:** 0
- **Bugs Encontrados:** 0

## Pruebas Unitarias
- **Resultado:** PASS (`phpunit`: 241 tests, 444 assertions)
- **Cobertura:** no medida (sin driver de cobertura); la suite del módulo cubre `ClientFieldRules`, ambos Request DTO, `PaymentClientResponse`, Entity y Service
- **Detalles:** 1 PHPUnit deprecation preexistente, ajena al feature. Sin regresiones.

## Validación de Criterios de Aceptación

### Criterios del PO

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | Listar clientes de la notaría → 200 | PASS | Notaría 1: 21 filas; notaría 3: solo su cliente |
| 2 | Filtro `status=1` | PASS | Solo activos; `status=2` solo inactivos |
| 3 | Filtro `name` case-insensitive | PASS | `name=lopez` → "Julieta López Perdomo"; `perez` y `PÉREZ` coinciden con "Pérez" |
| 4 | GET por ID | PASS | `GET /15` con token notaría 3 → 200 |
| 5 | GET de otra notaría → 404 | PASS | 404 `Client not found`, idéntico a ID inexistente |
| 6 | Crear con todos los campos → 201 | PASS | Creado con `id_notary` del token y `status=1` |
| 7 | Crear solo con nombre | PASS | `phone=''`, `email=''` en BD |
| 8 | `fullName` vacío / ausente → 400 | PASS | `""`, `{}`, solo espacios, body vacío, JSON inválido → 400 |
| 9 | Email inválido → 400 | PASS | `errors.email: Email format is invalid` |
| 10 | Nombre duplicado permitido | PASS | Dos POST con el mismo nombre → 201 + 201 (IDs distintos) |
| 11 | Actualizar → éxito | PASS | 204 (por ADR-004) y datos persistidos |
| 12 | Desactivar con `status: 2` | PASS | 204; GET muestra `status: 2`; reactivación con `status: 1` funciona |
| 13 | PUT de otra notaría → 404 | PASS | 404; el registro no cambió en BD (0 filas "HACK") |
| 14 | Sin token / inválido / expirado → 401 | PASS | Probado en GET, POST y PUT; también rechaza token refresh |

### Validaciones y casos edge del PO

| Caso | Estado | Evidencia |
|------|--------|-----------|
| `fullName` 250 chars → OK / 251 → 400 | PASS | 201 / `must not exceed 250 characters` |
| `phone` 20 chars → OK / 21 → 400 | PASS | 201 / `must not exceed 20 characters` |
| Email solo espacios → vacío válido | PASS | 201, `email=''` |
| Trim en todos los campos | PASS | `"  QA Trim  "` → `QA Trim`; phone `12`; email `t@x.com` |
| ID no numérico (`abc`, `1.5`) → 400 | PASS | `Invalid ID format` |
| ID inexistente → 404 | PASS | 404 `Client not found` |
| `status=3` en filtro | PASS | 400 `Invalid status value` (ADR-006) |
| `status` POST: `"1"` (string) y `3` → 400; `2` → 201 | PASS | `Status must be 1 or 2` |

### Criterios de Rechazo del PO

| Criterio de rechazo | Estado |
|---------------------|--------|
| Acceso a clientes de otra notaría | No ocurre |
| No aplica trim | No ocurre |
| Expone `created_at`, `modified_at`, `notaryId` | No ocurre (solo `clientId`, `fullName`, `phone`, `email`, `status`) |
| Permite escribir sin token válido | No ocurre |
| Acepta email inválido | No ocurre |

### Criterios Técnicos

| # | Criterio | Estado | Evidencia |
|---|----------|--------|-----------|
| 1 | POST → 201 sin body; PUT → 204 sin body | PASS | Verificado en respuestas |
| 2 | ID `<= 0` → 404; no numérico → 400 | PASS | `0` y `-1` → 404; `abc`, `1.5` → 400 |
| 3 | Validación del body antes de buscar el registro | PASS | PUT inválido a ID 99999 → 400; válido → 404 |
| 4 | `DELETE` no registrado → 405 | PASS | `405 Method not allowed` |
| 5 | Todas las rutas con middleware `auth` | PASS | `routes.php:72-75` y 401 verificados |
| 6 | `notaryId` del token, nunca del body | PASS | POST/PUT con `notaryId: 1` en body → fila queda en notaría 3 |
| 7 | PUT parcial conserva campos ausentes | PASS | `{"phone":"111"}` no altera nombre/email/status |
| 8 | `""` / `null` limpian phone/email | PASS | `phone:""` y `email:null` → `''` |
| 9 | `fullName: null` en PUT → 400 | PASS | `Full name must be a string` |
| 10 | PUT `{}` o solo campos desconocidos → 400 | PASS | `At least one field ... is required` |
| 11 | DTOs sin `TypeError` ante tipos inesperados | PASS | número, array, objeto → 400 con mensaje, sin 500 |
| 12 | Rechazo de caracteres de 4 bytes (emoji) | PASS | `fullName` y `phone` → 400 `contains unsupported characters` |
| 13 | Búsqueda insensible a acentos con `LIKE ... ESCAPE '!'` | PASS | `%`, `_`, `!` tratados como literales (0 resultados) |
| 14 | Orden alfabético natural + desempate por ID | PASS | "ALM…", "Automotores…", "CALIDAD…", … |
| 15 | Repository: todas las queries filtran por `id_notary`; prepared statements | PASS | Revisión de `PaymentClientRepository.php` |
| 16 | `SELECT` con columnas explícitas | PASS | Constante `COLUMNS` |
| 17 | Service sin PDO ni Request | PASS | Solo DTOs, repositorio y entidad |
| 18 | `declare(strict_types=1)` en todos los archivos | PASS | Verificado en `src/` y `tests/` del módulo |
| 19 | Índice `idx_payments_clients_notary_status` vía migración | PASS | Presente en `SHOW INDEX` |
| 20 | `EXPLAIN` usa el índice | OBSERVACIÓN | Con 33 filas el optimizador elige `ALL` (el índice figura en `possible_keys`); no es un defecto, se confirmará con más volumen |
| 21 | OpenAPI: tag, paths y schemas | PASS | `docs/openapi.yaml` contiene `PaymentClients` y las 4 operaciones |

## Pruebas de API (resumen)

| Endpoint | Casos | Resultado |
|----------|-------|-----------|
| Auth (GET/POST/PUT sin token, token malformado, expirado, refresh) | 6 | PASS |
| `GET /payment-clients` (listado, status, name, comodines, status inválido) | 13 | PASS |
| `GET /payment-clients/{id}` (propio, otra notaría, inexistente, `abc`, `1.5`, `0`, `-1`) | 8 | PASS |
| `DELETE /payment-clients/{id}` | 1 | PASS (405) |
| `POST /payment-clients` (válidos, trim, límites, tipos, emoji, JSON inválido, multi-error) | 29 | PASS |
| `PUT /payment-clients/{id}` (completo, parcial, limpieza, soft delete, inválidos, 404) | 18 | PASS |
| Cross-tenant PUT | 4 | PASS |

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Listado solo con datos propios | PASS | Notaría 3 ve 1 cliente; notaría 1 ve 21 |
| GET de cliente ajeno | PASS | 404 en ambas direcciones (1↔3) |
| PUT de cliente ajeno (nombre y status) | PASS | 404 y BD sin cambios |
| `notaryId` inyectado en el body | PASS | Ignorado; se usa el del token |

## Conclusión
La implementación cumple los 14 escenarios del PO, las validaciones, los casos edge y los criterios técnicos del arquitecto, con las desviaciones documentadas en los ADR (201/204 sin body, 400 por status inválido). No se encontraron bugs. Aislamiento entre notarías verificado. Se recomienda **APROBAR** y mergear el PR #8.

Observación no bloqueante: un cuerpo JSON malformado responde 400 con `Full name is required` en lugar de un mensaje de JSON inválido; es el comportamiento común del `Request` base y no afecta este feature.

## Fecha de Validación
2026-11-10
