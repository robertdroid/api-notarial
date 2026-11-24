# Plan de Implementación: Listado de Procedimientos con sus Impuestos

## Resumen
Endpoint de solo lectura `GET /api/v3/payment-procedures/taxes` que devuelve, en **una sola query**, todos los procedimientos activos de la notaría con sus impuestos activos y su `defaultValue`. Se implementa como método nuevo en el módulo `PaymentProcedureTax` (Controller → Service → Repository) con un read model nuevo (`ProcedureWithTaxes`) y un DTO de respuesta nuevo. No hay migraciones ni cambios a endpoints existentes.

Ajustes respecto a la spec del PO:
- **ADR-001**: la respuesta es un **array plano** (sin `{ "data": ... }`), igual que el resto del módulo Payments (ADR-005 de la spec 10). Decidido con el equipo.
- **ADR-004**: `defaultValue` se serializa como número JSON tal cual lo hace hoy `GET /payment-procedures/{id}/taxes` (`500`, `1200.5`, `0`); no se fuerzan 2 decimales.

## Arquitectura Propuesta
```
GET /api/v3/payment-procedures/taxes   (auth → notaryId del JWT)
        │
        ▼
PaymentProcedureTaxController::listAll(Request)
        │  notaryId
        ▼
PaymentProcedureTaxService::getAllWithTaxes(int $notaryId): ProcedureWithTaxes[]
        │
        ▼
PaymentProcedureTaxRepository::findActiveProceduresWithTaxes(int $notaryId): ProcedureWithTaxes[]
        │  1 query: payment_procedures LEFT JOIN payment_procedure_taxes LEFT JOIN payment_taxes
        │  agrupación en PHP por id_procedure (orden conservado)
        ▼
ProcedureWithTaxes { procedureId, procedureName, taxes: PaymentProcedureTax[] }
        │
        ▼
ProcedureWithTaxesResponse::fromCollection() → [{ procedureId, procedureName, taxes: [{ taxId, taxName, defaultValue }] }]
```

## Módulos Afectados
- [ ] `Module/PaymentProcedureTax/` - método nuevo en Controller/Service/Repository, Entity y DTO nuevos
- [ ] `Config/routes.php` - ruta nueva **antes** de `GET /api/v3/payment-procedures/{id}`
- [ ] `docs/openapi.yaml` - path y schemas nuevos

No se tocan `PaymentProcedure*`, `PaymentTax*`, `Core/` ni el `ServiceLocator` (el Service ya está registrado y no cambia su constructor).

## Tareas de Implementación

### Fase 1: Dominio y datos
Estimación: 1.5 horas

#### 1.1 Read model `ProcedureWithTaxes`
- **Archivo(s):** `src/Module/PaymentProcedureTax/Entity/ProcedureWithTaxes.php`
- **Tipo:** Crear
- **Descripción:** Clase `final` con propiedades `readonly`: `int $procedureId`, `string $procedureName`, `array $taxes` (`PaymentProcedureTax[]`, lista). Sin lógica.
- **Dependencias:** Ninguna (reutiliza `PaymentProcedureTax`).

#### 1.2 Query agregada en el Repository
- **Archivo(s):** `src/Module/PaymentProcedureTax/Repository/PaymentProcedureTaxRepository.php`
- **Tipo:** Modificar
- **Descripción:** Agregar `findActiveProceduresWithTaxes(int $notaryId): array` (ver query en `developer_notes.md`):
  - `FROM payment_procedures pp` con `WHERE pp.id_notary = :notaryId AND pp.id_status = 1`.
  - `LEFT JOIN payment_procedure_taxes ppt` con `ppt.id_status = 1` **en el `ON`**.
  - `LEFT JOIN payment_taxes pt` con `pt.id_notary = :notaryId2 AND pt.id_status = 1` **en el `ON`**.
  - Orden: nombre de procedimiento (collation unicode) → `id_procedure` → nombre de impuesto (collation unicode) → `id_tax`.
  - Agrupar en PHP por `id_procedure`: la primera fila de cada procedimiento crea el grupo; solo se agrega un impuesto si `pt.id_tax IS NOT NULL` (así una relación activa con impuesto inactivo/ajeno no genera tax).
- **Dependencias:** 1.1

### Fase 2: Service, DTO y Controller
Estimación: 1 hora

#### 2.1 Service
- **Archivo(s):** `src/Module/PaymentProcedureTax/Service/PaymentProcedureTaxService.php`
- **Tipo:** Modificar
- **Descripción:** `getAllWithTaxes(int $notaryId): array` → delega al repository. Sin validaciones extra (no hay id que buscar; la lista vacía es válida).
- **Dependencias:** 1.2

#### 2.2 DTO de respuesta
- **Archivo(s):** `src/Module/PaymentProcedureTax/DTO/ProcedureWithTaxesResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromEntity(ProcedureWithTaxes): array` y `fromCollection(array): array`. Cada tax se mapea a `{ taxId, taxName, defaultValue }` (**sin** `procedureId`, que ya está en el padre). `taxes` siempre es lista JSON (`[]` si vacío). No se modifica `ProcedureTaxResponse`.
- **Dependencias:** 1.1

#### 2.3 Controller
- **Archivo(s):** `src/Module/PaymentProcedureTax/Controller/PaymentProcedureTaxController.php`
- **Tipo:** Modificar
- **Descripción:** `listAll(Request $request): Response` → `Response::json(ProcedureWithTaxesResponse::fromCollection($this->service->getAllWithTaxes($request->getNotaryId())))`. Sin parámetros que validar; query params se ignoran.
- **Dependencias:** 2.1, 2.2

#### 2.4 Ruta
- **Archivo(s):** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** `$router->get('/api/v3/payment-procedures/taxes', [PaymentProcedureTaxController::class, 'listAll'])->withMiddleware('auth');` **inmediatamente antes** de la línea de `GET /api/v3/payment-procedures/{id}` (el router se queda con la primera coincidencia y `{id}` es `[^/]+`).
- **Dependencias:** 2.3

### Fase 3: Tests y documentación
Estimación: 1.5 horas

#### 3.1 Tests unitarios
- **Archivo(s):**
  - `tests/Module/PaymentProcedureTax/DTO/ProcedureWithTaxesResponseTest.php` (Crear)
  - `tests/Module/PaymentProcedureTax/Service/PaymentProcedureTaxServiceTest.php` (Modificar)
- **Descripción:** forma exacta del DTO (con y sin taxes, `defaultValue` 0 presente, `taxes` es lista), y que el Service delega con el `notaryId` correcto.
- **Dependencias:** Fase 2

#### 3.2 OpenAPI
- **Archivo(s):** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Path `/payment-procedures/taxes` (GET, tag `PaymentProcedureTaxes`, `operationId: listPaymentProceduresWithTaxes`, 200 array plano / 401), **declarado antes** de `/payment-procedures/{id}` por legibilidad. Schemas nuevos `ProcedureWithTaxesResponse` y `ProcedureTaxSummary`.
- **Dependencias:** Fase 2

## Orden de Implementación
1. `ProcedureWithTaxes` (1.1)
2. `findActiveProceduresWithTaxes` (1.2) — probar la query a mano en phpMyAdmin primero
3. `getAllWithTaxes` (2.1)
4. `ProcedureWithTaxesResponse` (2.2)
5. `listAll` + ruta (2.3, 2.4)
6. Tests (3.1)
7. OpenAPI (3.2)

## Riesgos y Mitigaciones
| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Ruta registrada después de `/{id}` → `GET /payment-procedures/taxes` responde 400 `Invalid ID format` | Media | Alto | Tarea 2.4 explícita; QA prueba el endpoint y además `GET /payment-procedures/5` sigue funcionando |
| Filtros de status en el `WHERE` en vez del `ON` → desaparecen procedimientos sin impuestos | Media | Alto | Query de referencia en `developer_notes.md`; QA escenarios 2, 3, 4 y caso edge "único impuesto inactivo" |
| Relación activa a impuesto inactivo genera un tax con `taxId` nulo | Media | Medio | Agrupación: solo se agrega tax si `id_tax` del catálogo no es NULL |
| Filas de `payment_procedure_taxes` con `id_status` NULL no se listan | Baja | Bajo | Mismo criterio (`= 1`) que `findActiveByProcedure` de la spec 10, para que ambos endpoints coincidan. Ver pre-check en `developer_notes.md` |
| Notaría con muchos procedimientos | Baja | Bajo | Una sola query; catálogo pequeño por notaría (decenas de filas). Sin paginación por alcance del PO |

## Decisiones de Arquitectura

### ADR-001: Respuesta como array plano
- **Contexto:** La spec del PO pide `{ "data": [...] }` "igual que otros listados", pero todos los GET del módulo Payments devuelven array plano (ADR-005 de la spec 10).
- **Decisión:** Array plano. Notaría sin procedimientos → `200 []`. Confirmado con el equipo.
- **Consecuencias:** Consistencia con los clientes existentes. El escenario 7 y la "respuesta esperada" del PO se leen sin el envoltorio `data`.

### ADR-002: Una sola query con LEFT JOIN y agrupación en PHP
- **Contexto:** Requisito no funcional de no hacer N+1. `GROUP_CONCAT`/`JSON_ARRAYAGG` moverían el armado del JSON a SQL y complicarían tipos y orden.
- **Decisión:** Una query desde `payment_procedures` con dos LEFT JOIN; los filtros de status de relación y catálogo, y la tenencia del catálogo, van en el `ON`. El Repository agrupa por `id_procedure` y devuelve `ProcedureWithTaxes[]`.
- **Consecuencias:** 1 query por request sin importar el tamaño. El Repository sigue devolviendo entities (no arrays crudos). No se usa `view_payment_procedure_taxes`: no filtra status ni `pt.id_notary`.

### ADR-003: Ubicación en el módulo `PaymentProcedureTax`
- **Contexto:** El endpoint cuelga de `/payment-procedures` pero su contenido es la relación procedimiento–impuesto.
- **Decisión:** Método nuevo en `PaymentProcedureTaxController/Service/Repository`, como sugiere el PO. `PaymentProcedure*` no se toca.
- **Consecuencias:** Toda la lectura de `payment_procedure_taxes` vive en un solo repositorio. La dependencia sigue siendo `PaymentProcedureTax → PaymentProcedure/PaymentTax`, nunca al revés.

### ADR-004: Serialización de `defaultValue`
- **Contexto:** El PO pide "float con 2 decimales" (`500.00`, `0.00`). `Response` usa `json_encode` sin `JSON_PRESERVE_ZERO_FRACTION`, así que un float `500.0` sale como `500`. JSON no distingue `500` de `500.00`.
- **Decisión:** `defaultValue` se entrega como `float`, sin redondeo ni formato, idéntico a `GET /payment-procedures/{id}/taxes`. No se cambian los flags globales de `Response` (afectaría todos los endpoints).
- **Consecuencias:** `0` se devuelve (nunca se omite) y es numérico. Los clientes deben formatear a 2 decimales en la UI. Los valores guardados vía API ya son números válidos (`DefaultValueRule`).

### ADR-005: Orden con collation unicode
- **Contexto:** `procedure_name` y `tax_name` son `utf8mb3_bin`; ordenar en binario pone mayúsculas antes que minúsculas y acentos al final.
- **Decisión:** `ORDER BY CONVERT(pp.procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, pp.id_procedure, CONVERT(pt.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, pt.id_tax`. Los ids desempatan para que el orden sea determinista.
- **Consecuencias:** Mismo orden de impuestos que `GET /payment-procedures/{id}/taxes`. Nota: `GET /payment-procedures` hoy ordena en binario; no se cambia (fuera de alcance).
