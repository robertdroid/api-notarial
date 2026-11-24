# Resultados de Pruebas: CRUD de Actos Jurídicos

## Resumen Ejecutivo

- **Estado:** APROBADO ✅
- **Feature:** 02_acts_crud
- **PR:** https://github.com/robertdroid/api-notarial/pull/2
- **Rama:** feature/02-acts-crud
- **Fecha:** 2026-11-09
- **QA:** Claude Code

## Métricas

| Métrica | Valor |
|---------|-------|
| Tests Unitarios | 52/52 PASS |
| Criterios PO | 16/16 PASS |
| Criterios Técnicos | 100% |
| Bugs Encontrados | 0 |

---

## Pruebas Unitarias

- **Resultado:** PASS ✅
- **Total:** 52 tests, 93 assertions
- **Cobertura módulo Act:** 37 tests específicos
- **Tiempo:** 1.447s

```
PHPUnit 10.5.66
OK, but there were issues!
Tests: 52, Assertions: 93, PHPUnit Deprecations: 1.
```

---

## Validación de Criterios de Aceptación del PO

### Escenarios Funcionales

| # | Escenario | Estado | Evidencia |
|---|-----------|--------|-----------|
| 1 | Listar todos los actos | ✅ PASS | GET /acts retorna array con actos de la notaría |
| 2 | Filtrar por status | ✅ PASS | GET /acts?status=1 retorna solo activos |
| 3 | Filtrar por nombre | ✅ PASS | GET /acts?name=compra retorna búsqueda parcial case-insensitive |
| 4 | Combinar filtros | ✅ PASS | GET /acts?status=1&name=venta funciona correctamente |
| 5 | Obtener acto por ID | ✅ PASS | GET /acts/1 retorna detalle con status 200 |
| 6 | Acto inexistente | ✅ PASS | GET /acts/99999 retorna 404 "Act not found" |
| 7 | Acto de otra notaría | ✅ PASS | GET /acts/58 (notaría 2) retorna 404 (no 403) |
| 8 | Crear acto | ✅ PASS | POST /acts retorna 201 sin body |
| 9 | Nombre duplicado en crear | ✅ PASS | POST retorna 400 "Act name already exists" |
| 10 | Validación datos inválidos | ✅ PASS | POST sin nombre retorna 400 con errores de validación |
| 11 | Actualizar acto | ✅ PASS | PUT /acts/{id} retorna 204 sin body |
| 12 | Soft delete (status=2) | ✅ PASS | PUT con status=2 desactiva el acto |
| 13 | Actualizar acto otra notaría | ✅ PASS | PUT /acts/58 retorna 404 |
| 14 | Nombre duplicado en update | ✅ PASS | PUT retorna 400 "Act name already exists" |
| 15 | Acceso sin token | ✅ PASS | Retorna 401 "Token no proporcionado" |
| 16 | Token inválido | ✅ PASS | Retorna 401 "Token inválido o expirado" |

### Casos Edge

| Caso | Estado | Evidencia |
|------|--------|-----------|
| ID no numérico | ✅ PASS | GET /acts/abc retorna 400 "Invalid ID format" |
| Trim de espacios | ✅ PASS | "  Acto con espacios  " se guarda como "Acto con espacios" |
| Nombre vacío/espacios | ✅ PASS | Validación rechaza nombres con solo espacios |
| Nombre > 200 chars | ✅ PASS | Validación rechaza en DTO |
| Descripción > 500 chars | ✅ PASS | Validación rechaza en DTO |
| Status != 1 o 2 | ✅ PASS | Validación rechaza valores inválidos |

---

## Pruebas de Multi-tenancy

| Escenario | Estado | Observación |
|-----------|--------|-------------|
| Listar solo actos propios | ✅ PASS | Usuario notaría 1 solo ve actos de notaría 1 |
| GET acto otra notaría | ✅ PASS | Retorna 404 (no revela existencia) |
| PUT acto otra notaría | ✅ PASS | Retorna 404 (no modifica) |
| notaryId del token | ✅ PASS | Se extrae del JWT, no del body |

---

## Pruebas de API - Resumen por Endpoint

### GET /api/v3/acts

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Lista completa | - | 200 + array | 200 + array | ✅ |
| Filtro status | ?status=1 | 200 + filtrado | 200 + filtrado | ✅ |
| Filtro nombre | ?name=compra | 200 + parcial | 200 + parcial | ✅ |
| Status inválido | ?status=3 | 400 | 400 | ✅ |
| Sin auth | - | 401 | 401 | ✅ |

### GET /api/v3/acts/{id}

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Acto existe | /acts/1 | 200 + objeto | 200 + objeto | ✅ |
| No existe | /acts/99999 | 404 | 404 | ✅ |
| Otra notaría | /acts/58 | 404 | 404 | ✅ |
| ID inválido | /acts/abc | 400 | 400 | ✅ |

### POST /api/v3/acts

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Válido | name+desc | 201 sin body | 201 sin body | ✅ |
| Duplicado | nombre existente | 400 | 400 | ✅ |
| Sin nombre | solo desc | 400 + errors | 400 + errors | ✅ |
| Sin desc | solo nombre | 400 + errors | 400 + errors | ✅ |

### PUT /api/v3/acts/{id}

| Caso | Input | Expected | Actual | Estado |
|------|-------|----------|--------|--------|
| Válido | todos campos | 204 sin body | 204 sin body | ✅ |
| Soft delete | status=2 | 204 | 204 | ✅ |
| No existe | /acts/99999 | 404 | 404 | ✅ |
| Otra notaría | /acts/58 | 404 | 404 | ✅ |
| Duplicado | nombre de otro | 400 | 400 | ✅ |

---

## Criterios Técnicos Validados

- [x] Todos los endpoints con middleware `auth`
- [x] Query params procesados correctamente
- [x] Validación de ID numérico
- [x] Responses usan métodos estáticos de Response
- [x] Repository filtra por `id_notaria` en todas las queries
- [x] Búsqueda por nombre usa LIKE case-insensitive
- [x] Prepared statements en todos los queries
- [x] `declare(strict_types=1)` en todos los archivos
- [x] Propiedades tipadas (PHP 8.x)
- [x] Return types en todos los métodos
- [x] Índice único creado para prevenir duplicados

---

## Conclusión

El feature **02_acts_crud** cumple con todos los criterios de aceptación funcionales y técnicos. La implementación sigue correctamente la arquitectura del proyecto (Controller → Service → Repository), aplica validación multi-tenant, y maneja correctamente todos los casos edge definidos.

**APROBADO para merge a develop.**

---

## Acciones Post-Aprobación

- [x] Documentación de QA completada
- [ ] Merge PR a develop
- [ ] Actualizar README.md con nuevos endpoints
