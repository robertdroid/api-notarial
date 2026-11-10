# Plan de Implementación: Notary Profile Management

## Resumen

Implementar un módulo para consultar y actualizar el perfil de la notaría del usuario autenticado. El módulo incluye manejo de archivos para el logo (subida y descarga). A diferencia de otros módulos CRUD, no requiere ID en las rutas ya que el `notary_id` viene implícito en el token JWT.

## Arquitectura Propuesta

### Diagrama de Componentes

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              HTTP Request                                    │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         AuthMiddleware (JWT)                                 │
│                    Extrae notary_id del token                               │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
           ┌─────────────────────────┼─────────────────────────┐
           ▼                         ▼                         ▼
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   GET /notary    │     │  POST /notary    │     │ GET /notary/logo │
│  (show profile)  │     │ (update + logo)  │     │ (serve image)    │
└──────────────────┘     └──────────────────┘     └──────────────────┘
           │                         │                         │
           └─────────────────────────┼─────────────────────────┘
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         NotaryController                                     │
│    show() │ update() │ logo()                                               │
└─────────────────────────────────────────────────────────────────────────────┘
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          NotaryService                                       │
│    getByIdActive() │ update() │ saveLogo() │ getLogoPath()                  │
└─────────────────────────────────────────────────────────────────────────────┘
           │                                                   │
           ▼                                                   ▼
┌──────────────────────────┐               ┌──────────────────────────────────┐
│    NotaryRepository      │               │        FileSystem                │
│    (tabla notarias)      │               │   public/assets/{id}/logo/       │
└──────────────────────────┘               └──────────────────────────────────┘
```

### Módulos Afectados

- [x] `Module/Notary/` - **CREAR** - Nuevo módulo completo
- [x] `Core/Http/Response.php` - **MODIFICAR** - Agregar método para servir archivos binarios
- [x] `Config/routes.php` - **MODIFICAR** - Registrar nuevas rutas
- [x] `bootstrap.php` - **MODIFICAR** - Registrar servicios

## Tareas de Implementación

### Fase 1: Infraestructura Base
Estimación: 2 horas

#### 1.1 Crear Entity Notary
- **Archivo:** `src/Module/Notary/Entity/Notary.php`
- **Tipo:** Crear
- **Descripción:** Entidad de dominio que mapea la tabla `notarias`. Incluye `fromRow()` para hidratación desde BD.
- **Dependencias:** Ninguna

#### 1.2 Crear NotaryRepository
- **Archivo:** `src/Module/Notary/Repository/NotaryRepository.php`
- **Tipo:** Crear
- **Descripción:** Repositorio con métodos `findByIdActive()` y `update()`. El método find valida que `id_estatus = 1`.
- **Dependencias:** Entity Notary

#### 1.3 Agregar método Response::file()
- **Archivo:** `src/Core/Http/Response.php`
- **Tipo:** Modificar
- **Descripción:** Agregar método estático `file()` para servir archivos binarios con Content-Type apropiado.
- **Dependencias:** Ninguna

### Fase 2: DTOs y Validación
Estimación: 1.5 horas

#### 2.1 Crear UpdateNotaryRequest DTO
- **Archivo:** `src/Module/Notary/DTO/UpdateNotaryRequest.php`
- **Tipo:** Crear
- **Descripción:** DTO para validar la petición POST. Valida campos obligatorios (`name`, `notaryName`), longitudes y formato de teléfono.
- **Dependencias:** Ninguna

#### 2.2 Crear NotaryResponse DTO
- **Archivo:** `src/Module/Notary/DTO/NotaryResponse.php`
- **Tipo:** Crear
- **Descripción:** DTO para formatear la respuesta del GET. Incluye campo calculado `hasLogo`.
- **Dependencias:** Entity Notary

### Fase 3: Servicio de Negocio
Estimación: 2 horas

#### 3.1 Crear NotaryService
- **Archivo:** `src/Module/Notary/Service/NotaryService.php`
- **Tipo:** Crear
- **Descripción:** Lógica de negocio. Incluye:
  - `getByIdActive()`: Obtiene notaría activa
  - `update()`: Actualiza campos (solo los proporcionados)
  - `saveLogo()`: Valida y guarda imagen
  - `deleteOldLogo()`: Elimina logo anterior si existe
  - `getLogoPath()`: Obtiene ruta completa del logo
- **Dependencias:** NotaryRepository

### Fase 4: Controller y Rutas
Estimación: 1.5 horas

#### 4.1 Crear NotaryController
- **Archivo:** `src/Module/Notary/Controller/NotaryController.php`
- **Tipo:** Crear
- **Descripción:** Controller con 3 métodos:
  - `show()`: GET /notary - Retorna info de la notaría
  - `update()`: POST /notary - Actualiza info + logo
  - `logo()`: GET /notary/logo - Sirve la imagen del logo
- **Dependencias:** NotaryService, DTOs

#### 4.2 Registrar rutas
- **Archivo:** `src/Config/routes.php`
- **Tipo:** Modificar
- **Descripción:** Agregar las 3 rutas del módulo con middleware auth
- **Dependencias:** NotaryController

#### 4.3 Registrar servicios
- **Archivo:** `src/bootstrap.php`
- **Tipo:** Modificar
- **Descripción:** Registrar NotaryRepository y NotaryService en ServiceLocator
- **Dependencias:** NotaryRepository, NotaryService

### Fase 5: Documentación
Estimación: 0.5 horas

#### 5.1 Actualizar OpenAPI
- **Archivo:** `docs/openapi.yaml`
- **Tipo:** Modificar
- **Descripción:** Documentar los 3 endpoints del módulo
- **Dependencias:** Ninguna

## Orden de Implementación

1. **Entity Notary** - Base para todo el módulo
2. **NotaryRepository** - Acceso a datos
3. **Response::file()** - Necesario para servir el logo
4. **UpdateNotaryRequest DTO** - Validación de entrada
5. **NotaryResponse DTO** - Formato de salida
6. **NotaryService** - Lógica de negocio completa
7. **NotaryController** - Expone endpoints
8. **routes.php** - Registra rutas
9. **bootstrap.php** - Registra servicios
10. **OpenAPI** - Documentación

## Riesgos y Mitigaciones

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Permisos de escritura en `public/assets/` | Media | Alto | Documentar configuración en Docker y producción. Verificar permisos en bootstrap o primer uso. |
| Archivos disfrazados (no son imágenes reales) | Media | Alto | Validar MIME con `finfo` Y verificar con `getimagesize()`. Doble validación. |
| Logo anterior no se elimina (acumula archivos) | Baja | Medio | Implementar `deleteOldLogo()` que busca cualquier extensión antes de guardar. |
| Token JWT con notary_id inválido | Baja | Alto | El middleware ya valida el token. El repository retorna null si no existe. |

## Decisiones de Arquitectura

### ADR-001: POST en lugar de PUT para actualización

- **Contexto:** El endpoint de actualización maneja archivos (logo). PHP no parsea automáticamente `multipart/form-data` en requests PUT.
- **Decisión:** Usar POST para `/api/v3/notary` aunque semánticamente sea una actualización.
- **Consecuencias:** Desviación del estándar REST puro. Alternativa sería implementar parser manual de multipart para PUT, lo cual agrega complejidad innecesaria.

### ADR-002: Logo en filesystem, no en BD

- **Contexto:** El logo podría almacenarse como BLOB en la BD o en el filesystem.
- **Decisión:** Guardar en `public/assets/{notary_id}/logo/` y almacenar solo la ruta en BD.
- **Consecuencias:** 
  - Mejor rendimiento (Apache sirve archivos directamente en endpoint dedicado)
  - Requiere permisos de escritura en filesystem
  - Backup de BD no incluye logos (considerar en estrategia de backup)

### ADR-003: Endpoint dedicado para logo

- **Contexto:** El logo podría exponerse como URL en el GET o tener endpoint dedicado.
- **Decisión:** Crear `GET /notary/logo` que sirve la imagen binaria.
- **Consecuencias:**
  - No exponemos rutas del filesystem al cliente
  - Control de acceso mediante JWT
  - Headers de cache controlables
  - El GET principal incluye `hasLogo: boolean` para saber si existe

### ADR-004: Actualización parcial de campos

- **Contexto:** El POST podría requerir todos los campos o permitir actualización parcial.
- **Decisión:** Solo `name` y `notaryName` son obligatorios. Los demás campos, si no vienen, no se modifican.
- **Consecuencias:**
  - Mayor flexibilidad para el cliente
  - El service debe merge-ar campos existentes con nuevos
  - El DTO marca claramente qué es opcional
