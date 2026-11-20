# Plan de Implementación: Eliminar Pago (Abono)

## Resumen
Agregar `DELETE /api/v3/payments/{id}` con borrado **físico** de una fila de `payments_payments`, filtrado por `id_notary` del JWT. Una sola sentencia SQL, sin transacción. Sigue el patrón del módulo `Payment` (spec 17): Controller → Service → Repository. Sin clases nuevas, sin migraciones, sin cambios en `bootstrap.php`.

## Arquitectura Propuesta
```
DELETE /api/v3/payments/{id}  (middleware auth)
  └─ PaymentController::destroy
       ├─ parseId()  → 400 "Invalid ID format" | 404 si id <= 0
       └─ PaymentService::delete(id, notaryId)
            └─ PaymentRepository::delete(id, notaryId): bool
                 DELETE FROM payments_payments WHERE id_payment = :id AND id_notary = :notaryId
                 return rowCount() > 0
            false → PaymentException(PaymentError::PaymentNotFound) → 404
  └─ Response::noContent()  → 204
```

## Módulos Afectados
- [x] `Module/Payment/` - Controller (`destroy`), Service (`delete`), Repository (`delete`)
- [x] `Config/routes.php` - ruta DELETE
- [x] `docs/openapi.yaml` - documentar `delete` en `/payments/{id}`
- [ ] `PaymentStatement`, `Receipt`, `PaymentClient`, `PaymentTransaction` - **sin cambios** (ver efectos abajo)

### Efectos en otros módulos (verificados en código)
- **Estado de cuenta (spec 19):** lista y `SUM(amount)` se recalculan en cada consulta; sin cambios.
- **Recibos PDF (spec 18):** `ReceiptService` obtiene el pago vía `PaymentService::getById/getByUuid`, que lanza `PaymentNotFound` → 404. Sin cambios.
- **Idempotencia por UUID (spec 17):** el uuid queda libre tras el borrado; un `POST` con ese uuid crea un abono nuevo. Riesgo aceptado por el PO.

## Tareas de Implementación

### Fase 1: Repository + Service
Estimación: 30 min

#### 1.1 `PaymentRepository::delete`
- **Archivo(s):** `src/Module/Payment/Repository/PaymentRepository.php`
- **Tipo:** Modificar
- **Descripción:** `public function delete(int $id, int $notaryId): bool`. Prepared statement `DELETE FROM payments_payments WHERE id_payment = :id AND id_notary = :notaryId`; retorna `$stmt->rowCount() > 0`. Docblock: a diferencia de `update`, aquí 0 filas significa "no existe o es de otra notaría".
- **Dependencias:** ninguna

#### 1.2 `PaymentService::delete`
- **Archivo(s):** `src/Module/Payment/Service/PaymentService.php`
- **Tipo:** Modificar
- **Descripción:** `public function delete(int $id, int $notaryId): void`. Si `repository->delete()` retorna `false` → `throw new PaymentException(PaymentError::PaymentNotFound)`. No valida cliente activo (escenario 8).
- **Dependencias:** 1.1

### Fase 2: Controller + ruta
Estimación: 20 min

#### 2.1 `PaymentController::destroy`
- **Archivo(s):** `src/Module/Payment/Controller/PaymentController.php`
- **Tipo:** Modificar
- **Descripción:** Reutiliza `parseId()` y `errorResponse()`. `try { service->delete($id, $request->getNotaryId()); } catch (PaymentException $e) { return errorResponse($e->error); }` y `return Response::noContent();`. No devolver cuerpo.
- **Dependencias:** 1.2

#### 2.2 Ruta
- **Archivo(s):** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** `$router->delete('/api/v3/payments/{id}', [PaymentController::class, 'destroy'])->withMiddleware('auth');` junto a las rutas de Payments (después de `put`, ~línea 103).
- **Dependencias:** 2.1

### Fase 3: Tests unitarios
Estimación: 40 min

#### 3.1 `PaymentServiceTest`
- **Archivo(s):** `tests/Module/Payment/Service/PaymentServiceTest.php`
- **Tipo:** Modificar
- **Descripción:** casos de `delete` con `PaymentRepository` mockeado (ver `technical_acceptance_criteria.md`).
- **Dependencias:** 1.2

### Fase 4: OpenAPI
Estimación: 20 min

#### 4.1 `docs/openapi.yaml`
- **Archivo(s):** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** agregar `delete` (`operationId: deletePayment`, tag `Payments`) bajo `/payments/{id}` (después de `put`, ~línea 2408). Respuestas `204`, `400`, `401`, `404`. Mencionar que el borrado es físico e irreversible y que el uuid queda reutilizable.
- **Dependencias:** ninguna

### Fase 5: Verificación manual (QA)
Fuera de este plan de desarrollo; ver "Verificación" en `technical_acceptance_criteria.md`.

## Orden de Implementación
1. `PaymentRepository::delete`
2. `PaymentService::delete`
3. Tests unitarios de Service (rojo → verde)
4. `PaymentController::destroy`
5. Ruta en `routes.php`
6. OpenAPI

**Total estimado: ~2.5 horas** (el PO estimó ~3 h; se omiten tests de Controller e integración, ver ADR-002).

## Riesgos y Mitigaciones
| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Borrado irreversible sin rastro | Media | Alto (negocio) | Aceptado por el PO; documentar en OpenAPI |
| Re-creación por `POST` retardado con uuid ya borrado | Media | Medio | Aceptado por el PO; documentar en OpenAPI |
| Olvidar el filtro `id_notary` (fuga multi-tenant) | Baja | Crítico | Criterio técnico explícito + verificación QA con JWT de otra notaría |
| Falso 404 por `rowCount()` | Baja | Bajo | PDO MySQL devuelve filas borradas reales en DELETE (`ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`); `FOUND_ROWS` solo afecta a UPDATE |

## Decisiones de Arquitectura

### ADR-001: `rowCount()` en lugar de `findByIdAndNotary` + `DELETE`
- **Contexto:** Hay que responder 404 si el abono no existe o es de otra notaría, y 204 si se borró.
- **Decisión:** Una sola sentencia `DELETE ... WHERE id_payment AND id_notary`; el repository retorna `bool` (`rowCount() > 0`) y el Service lanza `PaymentNotFound` si es `false`.
- **Consecuencias:** Una consulta en lugar de dos; sin transacción (respeta el docblock "Never opens transactions"); la carrera de dos DELETE simultáneos queda resuelta por la BD (uno 204, otro 404). No se puede distinguir "otra notaría" de "no existe", lo cual es deseado (no revelar existencia).

### ADR-002: Alcance de pruebas
- **Contexto:** El PO pide tests de Controller y de integración con BD. El proyecto no tiene ninguno (`tests/` solo contiene tests unitarios sin BD; `phpunit.xml` tiene una única suite). `PaymentController` obtiene `PaymentService` del `ServiceLocator` en el constructor y `PaymentService` es `final` (no mockeable).
- **Decisión:** Tests unitarios de `PaymentService::delete` con `PaymentRepository` mockeado. El comportamiento HTTP (204/400/401/404, aislamiento por notaría, doble borrado) se verifica en QA contra Docker, como en las specs anteriores. No se crea infraestructura de integración ni se refactoriza el Controller.
- **Consecuencias:** Cobertura automática limitada a la lógica del Service; el SQL real (incluido el filtro `id_notary`) solo se valida en QA. Si se quiere cobertura automática del SQL, requiere una spec aparte de infraestructura de tests.

### ADR-003: Sin cambios de esquema ni de `bootstrap.php`
- **Contexto:** No hay clases nuevas ni columnas nuevas.
- **Decisión:** Se extienden las clases existentes. La migración InnoDB de spec 17 ya está en `.db/applied/`, pero el `DELETE` funciona igual en MyISAM e InnoDB.
- **Consecuencias:** Cero impacto en despliegue de BD.
