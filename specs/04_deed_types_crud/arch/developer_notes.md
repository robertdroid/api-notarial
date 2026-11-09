# Notas para el Developer

## Contexto Rápido

Crear módulo CRUD para tipos de escritura (`tipo_escritura`). Seguir **exactamente** el patrón de `Module/Subdivision`. La diferencia principal: este catálogo tiene más campos (description, maxInscriptions, enableSubdivisions).

**IMPORTANTE:** El PK de la tabla tiene un typo legacy: `id_tipo_escitura` (falta la 'r'). No lo corrijas en BD, solo mapéalo correctamente en Entity.

## Archivos a Crear

| Archivo | Copiar de | Cambios principales |
|---------|-----------|---------------------|
| `Entity/DeedType.php` | `Subdivision/Entity/Subdivision.php` | Agregar campos: description, maxInscriptions, enableSubdivisions |
| `DTO/CreateDeedTypeRequest.php` | `Subdivision/DTO/CreateSubdivisionRequest.php` | Agregar validaciones de campos extras |
| `DTO/UpdateDeedTypeRequest.php` | `Subdivision/DTO/UpdateSubdivisionRequest.php` | Agregar validaciones de campos extras |
| `DTO/DeedTypeResponse.php` | `Subdivision/DTO/SubdivisionResponse.php` | Agregar campos, convertir enableSubdivisions a bool |
| `Repository/DeedTypeRepository.php` | `Subdivision/Repository/SubdivisionRepository.php` | Cambiar tabla y campos |
| `Service/DeedTypeService.php` | `Subdivision/Service/SubdivisionService.php` | Adaptar a nuevos campos |
| `Controller/DeedTypeController.php` | `Subdivision/Controller/SubdivisionController.php` | Cambiar nombres de clases |

## Estructura del Entity

```php
<?php

declare(strict_types=1);

namespace App\Module\DeedType\Entity;

use DateTime;

final class DeedType
{
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly string $description,
        public readonly int $maxInscriptions,
        public readonly int $enableSubdivisions, // 0 o 1 en BD
        public readonly int $userId,
        public readonly DateTime $modificationDate,
        public readonly int $statusId,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_tipo_escitura'], // ← TYPO LEGACY
            notaryId: (int) $row['id_notaria'],
            name: $row['nombre_tipo'],
            description: $row['descripcion_tipo'],
            maxInscriptions: (int) $row['max_inscripciones'],
            enableSubdivisions: (int) $row['activar_fraccionamientos'],
            userId: (int) $row['id_usuario'],
            modificationDate: new DateTime($row['fecha_modificacion']),
            statusId: (int) $row['id_estatus'],
        );
    }
}
```

## Estructura del Response DTO

```php
public static function fromEntity(DeedType $deedType): array
{
    return [
        'deedTypeId' => $deedType->id,
        'notaryId' => $deedType->notaryId,
        'name' => $deedType->name,
        'description' => $deedType->description,
        'maxInscriptions' => $deedType->maxInscriptions,
        'enableSubdivisions' => (bool) $deedType->enableSubdivisions, // ← INT a BOOL
        'userId' => $deedType->userId,
        'modificationDate' => $deedType->modificationDate->format('Y-m-d\TH:i:s'),
        'status' => $deedType->statusId,
    ];
}
```

## Base de Datos

### Tabla: `tipo_escritura`

```sql
-- Estructura actual
CREATE TABLE tipo_escritura (
    id_tipo_escitura INT AUTO_INCREMENT PRIMARY KEY,  -- NOTA: typo 'escitura'
    id_notaria INT,
    nombre_tipo VARCHAR(200) NOT NULL,
    descripcion_tipo VARCHAR(500) NOT NULL,
    max_inscripciones INT DEFAULT 1,
    activar_fraccionamientos INT DEFAULT 0,
    id_usuario INT,
    fecha_modificacion DATETIME,
    id_estatus INT NOT NULL DEFAULT 1
);
```

### Queries Sugeridas

```sql
-- findAllByNotary con filtros
SELECT * FROM tipo_escritura 
WHERE id_notaria = :notaryId
  AND (:status IS NULL OR id_estatus = :status)
  AND (:name IS NULL OR LOWER(nombre_tipo) LIKE LOWER(:name))
ORDER BY nombre_tipo ASC;

-- findByIdAndNotary
SELECT * FROM tipo_escritura 
WHERE id_tipo_escitura = :id 
  AND id_notaria = :notaryId;

-- existsByNameAndNotary (para unicidad)
SELECT COUNT(*) FROM tipo_escritura 
WHERE LOWER(nombre_tipo) = LOWER(:name) 
  AND id_notaria = :notaryId
  AND (:excludeId IS NULL OR id_tipo_escitura != :excludeId);

-- create
INSERT INTO tipo_escritura (
    id_notaria, nombre_tipo, descripcion_tipo, 
    max_inscripciones, activar_fraccionamientos,
    id_usuario, fecha_modificacion, id_estatus
) VALUES (
    :notaryId, :name, :description,
    :maxInscriptions, :enableSubdivisions,
    :userId, :modDate, :status
);

-- update
UPDATE tipo_escritura SET
    nombre_tipo = :name,
    descripcion_tipo = :description,
    max_inscripciones = :maxInscriptions,
    activar_fraccionamientos = :enableSubdivisions,
    id_usuario = :userId,
    fecha_modificacion = :modDate,
    id_estatus = :status
WHERE id_tipo_escitura = :id 
  AND id_notaria = :notaryId;
```

### Migraciones Necesarias

Ninguna - la tabla ya existe con la estructura correcta.

## Rutas a Agregar

En `src/Config/routes.php`:

```php
use App\Module\DeedType\Controller\DeedTypeController;

// Deed Types routes (protected)
$router->get('/api/v3/deed-types', [DeedTypeController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/deed-types/{id}', [DeedTypeController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/deed-types', [DeedTypeController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/deed-types/{id}', [DeedTypeController::class, 'update'])->withMiddleware('auth');
```

## Validaciones Específicas

### CreateDeedTypeRequest

```php
private function validate(): void
{
    // name: requerido, max 200
    if (empty(trim($this->name))) {
        $this->errors['name'] = 'Deed type name is required';
    } elseif (mb_strlen($this->name) > 200) {
        $this->errors['name'] = 'Deed type name must not exceed 200 characters';
    }

    // description: requerido, max 500
    if (empty(trim($this->description))) {
        $this->errors['description'] = 'Description is required';
    } elseif (mb_strlen($this->description) > 500) {
        $this->errors['description'] = 'Description must not exceed 500 characters';
    }

    // maxInscriptions: si viene, debe ser > 0
    if ($this->maxInscriptions <= 0) {
        $this->errors['maxInscriptions'] = 'Max inscriptions must be greater than 0';
    }

    // status: 1 o 2
    if (!in_array($this->status, [1, 2], true)) {
        $this->errors['status'] = 'Status must be 1 or 2';
    }
}
```

## Checklist Pre-PR

- [ ] Todos los archivos en `src/Module/DeedType/`
- [ ] Entity maneja typo `id_tipo_escitura`
- [ ] Response convierte `enableSubdivisions` a boolean
- [ ] Rutas agregadas en `routes.php`
- [ ] ServiceLocator actualizado con Repository y Service
- [ ] Código sigue PSR-12
- [ ] Sin warnings de PHP
- [ ] Tests manuales con Postman/curl pasando

## Preguntas Frecuentes

**P: ¿Debo corregir el typo `id_tipo_escitura` en la BD?**
R: No. Es legacy y cambiaría otras partes del sistema. Solo mapéalo correctamente en el Entity.

**P: ¿`enableSubdivisions` debe ser boolean en Entity?**
R: No. Mantén int en Entity (fiel a BD). Convierte a boolean solo en DeedTypeResponse.

**P: ¿Qué pasa si `maxInscriptions` viene como 0?**
R: El DTO debe validar y rechazar. Debe ser > 0. El default es 1.

**P: ¿La búsqueda por nombre es case-sensitive?**
R: No. Usar `LOWER()` en ambos lados del LIKE.

## Contacto

- Dudas de negocio: Revisar `specs/04_deed_types_crud/po/`
- Dudas de arquitectura: Revisar `specs/04_deed_types_crud/arch/`
- Patrón de referencia: `src/Module/Subdivision/`
