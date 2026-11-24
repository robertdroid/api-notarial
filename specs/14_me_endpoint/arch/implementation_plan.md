# Plan de Implementación: Endpoint Me (Usuario + Notaría)

## Resumen
`GET /api/v3/me` devuelve `{user, notary}` de la sesión. Un módulo nuevo `Me` con un `MeController` delgado y un `MeService` que lee el usuario y la notaría **por id del token**, valida estados y arma la respuesta reutilizando `UserResponse` y `NotaryResponse`. Sin migraciones, sin tocar login/JWT ni `/users` y `/notary`.

Hallazgo clave: los métodos existentes **no alcanzan** para los códigos que pide el PO.
- `UserRepository::findByIdAndNotary()` devuelve `null` si el `notaryId` del token no coincide → daría `404`, y el PO pide `403`.
- `NotaryRepository::findByIdActive()` mezcla "no existe" e "inactiva" → no se puede responder `403` con certeza.

Por eso se agregan dos lecturas sin filtro de estado/tenant (ver ADR-002) y la decisión se toma en el Service.

## Arquitectura Propuesta
```
GET /api/v3/me  ──► AuthMiddleware (JWT → userId, notaryId)
                        │
                  MeController::show(Request)      sin parámetros, solo token
                        │
                  MeService::getProfile(userId, notaryId): MeProfile
                        │   1. UserRepository::findById(userId)        → null → 404
                        │   2. user.notaryId ≠ token.notaryId          → 403
                        │   3. user.status() ≠ 1                       → 403
                        │   4. NotaryRepository::findById(notaryId)    → null o status ≠ 1 → 403
                        ▼
                  MeProfile(User, Notary)   ──► MeResponse::fromProfile() ──► 200 JSON
```
Errores: `MeException(MeError)` → el controller los mapea a `Response` (mismo patrón que `UserController::errorResponse`).

## Módulos Afectados
- [ ] `Module/Me/` - **Nuevo**: Controller, Service, DTO, Exception
- [ ] `Module/User/` - `User::fullName()`, `UserRepository::findById()`
- [ ] `Module/Notary/` - `NotaryRepository::findById()`
- [ ] `Config/routes.php`, `bootstrap.php`, `docs/openapi.yaml`

## Tareas de Implementación

### Fase 1: Capa de datos y dominio (módulos existentes)
Estimación: 1 hora

#### 1.1 `User::fullName()`
- **Archivo(s):** `src/Module/User/Entity/User.php`
- **Tipo:** Modificar
- **Descripción:** `fullName(): string` = `trim` de cada parte, descarta vacías, une con un espacio. No cambia `UserResponse` (PO excluye cambios en `/users`).
- **Dependencias:** ninguna

#### 1.2 `UserRepository::findById()`
- **Archivo(s):** `src/Module/User/Repository/UserRepository.php`
- **Tipo:** Modificar
- **Descripción:** `findById(int $id): ?User`, mismas `COLUMNS` (nunca password). PHPDoc: solo para resolver la identidad del token; el llamador debe comparar `notaryId`.
- **Dependencias:** ninguna

#### 1.3 `NotaryRepository::findById()`
- **Archivo(s):** `src/Module/Notary/Repository/NotaryRepository.php`
- **Tipo:** Modificar
- **Descripción:** `findById(int $id): ?Notary` sin filtro de estatus. `findByIdActive` queda intacto.
- **Dependencias:** ninguna

### Fase 2: Módulo Me
Estimación: 1.5 horas

#### 2.1 `MeError` y `MeException`
- **Archivo(s):** `src/Module/Me/Exception/MeError.php`, `MeException.php`
- **Tipo:** Crear
- **Descripción:** Enum con `message()` y `httpStatus()` (mismo patrón que `UserError`).

| Case | HTTP | Mensaje |
|------|------|---------|
| `UserNotFound` | 404 | `User not found` |
| `TenantMismatch` | 403 | `Forbidden` |
| `UserInactive` | 403 | `User is inactive` |
| `NotaryInactive` | 403 | `Notary is inactive` |

- **Dependencias:** ninguna

#### 2.2 `MeProfile`
- **Archivo(s):** `src/Module/Me/DTO/MeProfile.php`
- **Tipo:** Crear
- **Descripción:** VO `final readonly` con `User $user`, `Notary $notary`. Es el resultado del Service.

#### 2.3 `MeResponse`
- **Archivo(s):** `src/Module/Me/DTO/MeResponse.php`
- **Tipo:** Crear
- **Descripción:** `fromProfile(MeProfile): array` → `['user' => …, 'notary' => NotaryResponse::fromEntity()]`. `user` sale de `UserResponse::fromEntity()` insertando `fullName` justo después de `maternalLastName` (`array_slice`). Sin `notaryId`, sin password, sin nombre del logo.
- **Dependencias:** 1.1, 2.2

#### 2.4 `MeService`
- **Archivo(s):** `src/Module/Me/Service/MeService.php`
- **Tipo:** Crear
- **Descripción:** `getProfile(int $userId, int $notaryId): MeProfile`, con el orden de validaciones del diagrama. Dependencias: `UserRepository`, `NotaryRepository`. Sin transacción (solo lectura, 2 queries por PK).
- **Dependencias:** 1.2, 1.3, 2.1, 2.2

#### 2.5 `MeController`
- **Archivo(s):** `src/Module/Me/Controller/MeController.php`
- **Tipo:** Crear
- **Descripción:** `show(Request)`: toma `getUserId()` y `getNotaryId()`, llama al Service, mapea `MeException` → `Response::notFound|forbidden`. **No lee** route params, query ni body.
- **Dependencias:** 2.3, 2.4

### Fase 3: Integración y documentación
Estimación: 0.5 hora

#### 3.1 Wiring y ruta
- **Archivo(s):** `src/bootstrap.php`, `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Registrar `MeService` en `ServiceLocator`; `$router->get('/api/v3/me', [MeController::class, 'show'])->withMiddleware('auth');`. Verificar que `POST/PUT/DELETE /me` respondan `405`.

#### 3.2 OpenAPI
- **Archivo(s):** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Path `/me` (200/401/403/404/405), schemas `MeResponse` y `MeUser` (reusar `$ref` a `NotaryResponse`). Agregar tag `Me`.

### Fase 4: Tests
Estimación: 1 hora

#### 4.1 Unit tests
- **Archivo(s):** `tests/Module/Me/Service/MeServiceTest.php`, `tests/Module/Me/DTO/MeResponseTest.php`, `tests/Module/User/Entity/UserTest.php` (ampliar)
- **Tipo:** Crear / Modificar
- **Descripción:** Mocks de repositorios (ver `UserServiceTest`). Casos en `technical_acceptance_criteria.md`.

## Orden de Implementación
1. 1.1, 1.2, 1.3 (independientes)
2. 2.1, 2.2 → 2.3 → 2.4 → 2.5
3. 3.1 y verificación manual con `curl`
4. 4.1 (los tests de Service/Response se pueden escribir junto con 2.x)
5. 3.2 OpenAPI

**Total estimado: ~4 horas.**

## Riesgos y Mitigaciones
| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| `findById` sin filtro de tenant se reutiliza mal en otro lado | Media | Alto | PHPDoc explícito + única llamada en `MeService`, que compara `notaryId` de inmediato (ADR-002) |
| `ultimo_acceso` legacy `0000-00-00 00:00:00` produce `-0001-11-30` en vez de `null` | Media | Bajo | Ya ocurre en `/users`. QA lo verifica con datos reales; si aparece, corregir en `User::fromRow` (afecta ambos endpoints, es mejora) |
| `Notary::fromRow` falla con columnas `NULL` legacy (props `string` tipadas) | Baja | Medio | Mismo comportamiento que `GET /notary`; QA prueba con la notaría real |
| JWT de 15 min vigente tras desactivar usuario | Alta | Bajo | Se revalida estado en BD en cada llamada (Escenarios 3 y 4) |
| El frontend interpreta `403` como "sin permiso" y no como "cuenta inactiva" | Baja | Bajo | Mensajes distintos por causa; fuera de alcance agregar `code` |

## Decisiones de Arquitectura
### ADR-001: Módulo propio `Me` (no dentro de `Auth`)
- **Contexto:** El PO dejó pendiente `Me`/`Profile` o `Auth`. `Auth` hoy solo emite/valida tokens y tiene su propio `UserAuthRepository`.
- **Decisión:** Módulo `Me`. Es un read model compuesto de dos agregados (User + Notary) que no pertenece a ninguno. `Auth` no debería depender de `User`/`Notary`.
- **Consecuencias:** Dependencia de `Me` hacia `User` y `Notary` (unidireccional). Nombre `Me` y no `Profile` porque la ruta es `/me` y `notary_profile` (05) ya existe como feature de `Notary`.

### ADR-002: Lectura por id del token sin filtro de tenant, verificación explícita en Service
- **Contexto:** Regla del proyecto: repositorios siempre filtran por `notary_id`. Pero `findByIdAndNotary` convierte un token inconsistente en `404`, y el PO exige `403` (usuario cuya notaría cambió). `findByIdActive` de notaría oculta la causa del rechazo.
- **Decisión:** `UserRepository::findById` y `NotaryRepository::findById` sin filtro; `MeService` compara `user->notaryId === $notaryId` inmediatamente después de leer el usuario y antes de leer la notaría. Ambos ids provienen solo del token, así que no hay vector de manipulación por el cliente.
- **Consecuencias:** Excepción documentada a la regla multi-tenant, acotada a este flujo. Alternativa descartada: `findByIdAndNotary` + segunda consulta para distinguir 404/403 (más queries, más código, mismo resultado).

### ADR-003: Service con repositorios, no con `UserService`/`NotaryService`
- **Contexto:** Los services existentes devuelven `null`/errores que ya colapsan los casos que necesitamos distinguir (`getByIdActive`, `getById` filtrado).
- **Decisión:** `MeService` depende de los dos repositorios directamente.
- **Consecuencias:** Acoplamiento a repos de otro módulo, aceptado porque Clean Architecture permite que un caso de uso componga repositorios; evita crear métodos "por si acaso" en services ajenos.

### ADR-004: `fullName` en la entidad `User`, no en `UserResponse`
- **Contexto:** El PO excluye cambios en `/users`; el campo es solo de `/me`.
- **Decisión:** `User::fullName()` (regla de dominio, testeable sin DTO). `UserResponse` no cambia; `MeResponse` lo agrega.
- **Consecuencias:** `/users` no expone `fullName`. Si luego se quiere, es un cambio de una línea.

### ADR-005: Estado inactivo = `status() !== 1` para usuario y `status !== 1` para notaría
- **Contexto:** Datos legacy con `0`/`NULL` conviven con la v1 (ver ADR-004 de 13_deeds_crud).
- **Decisión:** Usuario usa `User::status()` (ya normaliza). Notaría compara el estatus crudo con `1`.
- **Consecuencias:** `notary.status` se expone tal cual viene de BD, igual que `GET /notary`.
