# Notas para el Developer

## Contexto Rápido

Crear módulo CRUD para fraccionamientos (subdivisions). Es casi idéntico al módulo Act pero **sin campo de descripción**. La tabla ya existe en producción con datos.

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/Subdivision/Controller/SubdivisionController.php` | Endpoints HTTP REST |
| `src/Module/Subdivision/Service/SubdivisionService.php` | Lógica de negocio y validaciones |
| `src/Module/Subdivision/Repository/SubdivisionRepository.php` | Acceso a BD |
| `src/Module/Subdivision/Entity/Subdivision.php` | Objeto de dominio inmutable |
| `src/Module/Subdivision/DTO/CreateSubdivisionRequest.php` | Validación de datos creación |
| `src/Module/Subdivision/DTO/UpdateSubdivisionRequest.php` | Validación de datos actualización |
| `src/Module/Subdivision/DTO/SubdivisionResponse.php` | Transformación a JSON |
| `src/Config/routes.php` | Registro de rutas |

## Patrones a Seguir

Revisar implementación del módulo Act como referencia principal:
- `src/Module/Act/Controller/ActController.php` - Estructura de controller
- `src/Module/Act/Service/ActService.php` - Patrón de Service
- `src/Module/Act/Repository/ActRepository.php` - Queries con filtros dinámicos
- `src/Module/Act/Entity/Act.php` - Entity con fromRow()
- `src/Module/Act/DTO/` - Patrón de DTOs con validación

## Diferencias Clave con Act

| Aspecto | Act | Subdivision |
|---------|-----|-------------|
| Tabla | `actos_notaria` | `fraccionamientos` |
| PK | `id_acto` | `id_fraccionamiento` |
| Nombre | `nombre_acto` (200 chars) | `nombre_fraccionamiento` (500 chars) |
| Descripción | `descripcion_acto` ✅ | ❌ No tiene |
| Response ID | `id` | `subdivisionId` |
| Fecha formato | `Y-m-d H:i:s` | `Y-m-d\TH:i:s` (ISO 8601) |

## Código de Referencia

### Entity/Subdivision.php

```php
<?php

declare(strict_types=1);

namespace App\Module\Subdivision\Entity;

use DateTime;

final class Subdivision
{
    public function __construct(
        public readonly int $id,
        public readonly int $notaryId,
        public readonly string $name,
        public readonly int $userId,
        public readonly DateTime $modificationDate,
        public readonly int $statusId,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_fraccionamiento'],
            notaryId: (int) $row['id_notaria'],
            name: $row['nombre_fraccionamiento'],
            userId: (int) $row['id_usuario'],
            modificationDate: new DateTime($row['fecha_modificacion']),
            statusId: (int) $row['id_estatus'],
        );
    }
}
```

### DTO/CreateSubdivisionRequest.php

```php
<?php

declare(strict_types=1);

namespace App\Module\Subdivision\DTO;

final class CreateSubdivisionRequest
{
    public readonly string $name;
    public readonly int $status;

    private array $errors = [];

    private function __construct(string $name, int $status)
    {
        $this->name = $name;
        $this->status = $status;
    }

    public static function fromArray(array $data): self
    {
        $instance = new self(
            name: $data['name'] ?? '',
            status: isset($data['status']) ? (int) $data['status'] : 1,
        );

        $instance->validate();

        return $instance;
    }

    private function validate(): void
    {
        if (empty(trim($this->name))) {
            $this->errors['name'] = 'Subdivision name is required';
        } elseif (mb_strlen($this->name) > 500) {
            $this->errors['name'] = 'Subdivision name must not exceed 500 characters';
        }

        if (!in_array($this->status, [1, 2], true)) {
            $this->errors['status'] = 'Status must be 1 or 2';
        }
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

### DTO/SubdivisionResponse.php

```php
<?php

declare(strict_types=1);

namespace App\Module\Subdivision\DTO;

use App\Module\Subdivision\Entity\Subdivision;

final class SubdivisionResponse
{
    public static function fromEntity(Subdivision $subdivision): array
    {
        return [
            'subdivisionId' => $subdivision->id,
            'notaryId' => $subdivision->notaryId,
            'name' => $subdivision->name,
            'userId' => $subdivision->userId,
            'modificationDate' => $subdivision->modificationDate->format('Y-m-d\TH:i:s'),
            'status' => $subdivision->statusId,
        ];
    }

    public static function fromCollection(array $subdivisions): array
    {
        return array_map(
            fn(Subdivision $s) => self::fromEntity($s),
            $subdivisions
        );
    }
}
```

### Repository (query ejemplo)

```php
public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
{
    $sql = "SELECT * FROM fraccionamientos WHERE id_notaria = :notaryId";
    $params = ['notaryId' => $notaryId];

    if ($status !== null) {
        $sql .= " AND id_estatus = :status";
        $params['status'] = $status;
    }

    if ($name !== null && $name !== '') {
        $sql .= " AND LOWER(nombre_fraccionamiento) LIKE LOWER(:name)";
        $params['name'] = '%' . $name . '%';
    }

    $sql .= " ORDER BY nombre_fraccionamiento ASC";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $results[] = Subdivision::fromRow($row);
    }

    return $results;
}
```

## Base de Datos

### Tabla: fraccionamientos

```sql
CREATE TABLE fraccionamientos (
    id_fraccionamiento INT AUTO_INCREMENT PRIMARY KEY,
    id_notaria INT NOT NULL,
    nombre_fraccionamiento VARCHAR(500) NOT NULL,
    id_usuario INT NOT NULL,
    fecha_modificacion DATETIME NOT NULL,
    id_estatus INT NOT NULL DEFAULT 1,
    FOREIGN KEY (id_notaria) REFERENCES notaria(id_notaria),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
);
```

### Índices relevantes

La tabla ya existe. Verificar que existan índices en:
- `id_notaria` (filtro de multi-tenancy)
- `nombre_fraccionamiento` (búsqueda)

### Queries clave

```sql
-- Listar por notaría con filtros
SELECT * FROM fraccionamientos 
WHERE id_notaria = ? 
AND id_estatus = ?  -- opcional
AND LOWER(nombre_fraccionamiento) LIKE LOWER(?)  -- opcional
ORDER BY nombre_fraccionamiento ASC;

-- Verificar unicidad (excluyendo ID actual para updates)
SELECT COUNT(*) FROM fraccionamientos 
WHERE LOWER(nombre_fraccionamiento) = LOWER(?) 
AND id_notaria = ? 
AND id_fraccionamiento != ?;

-- Insertar
INSERT INTO fraccionamientos 
(id_notaria, nombre_fraccionamiento, id_usuario, fecha_modificacion, id_estatus)
VALUES (?, ?, ?, ?, ?);

-- Actualizar
UPDATE fraccionamientos
SET nombre_fraccionamiento = ?,
    id_usuario = ?,
    fecha_modificacion = ?,
    id_estatus = ?
WHERE id_fraccionamiento = ? AND id_notaria = ?;
```

## Rutas a agregar en routes.php

```php
use App\Module\Subdivision\Controller\SubdivisionController;

// Subdivisions routes (protected)
$router->get('/api/v3/subdivisions', [SubdivisionController::class, 'index'])->withMiddleware('auth');
$router->get('/api/v3/subdivisions/{id}', [SubdivisionController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/subdivisions', [SubdivisionController::class, 'store'])->withMiddleware('auth');
$router->put('/api/v3/subdivisions/{id}', [SubdivisionController::class, 'update'])->withMiddleware('auth');
```

## Checklist Pre-PR

- [ ] Todos los archivos en `src/Module/Subdivision/` creados
- [ ] Namespace correcto en cada archivo
- [ ] `declare(strict_types=1)` en cada archivo
- [ ] Rutas agregadas a `routes.php`
- [ ] Probado con Postman/cURL:
  - [ ] GET /subdivisions devuelve lista
  - [ ] GET /subdivisions?status=1 filtra
  - [ ] GET /subdivisions?name=test filtra
  - [ ] GET /subdivisions/1 devuelve detalle
  - [ ] POST /subdivisions crea (201)
  - [ ] POST /subdivisions duplicado (400)
  - [ ] PUT /subdivisions/1 actualiza (204)
  - [ ] Sin token (401)
- [ ] No hay warnings de PHP
- [ ] Código sigue PSR-12

## Preguntas Frecuentes

**P: ¿Por qué `subdivisionId` en lugar de `id` en la respuesta?**
R: El PO lo especificó así en el contexto técnico. Seguimos la documentación.

**P: ¿Por qué el formato de fecha tiene `T` literal?**
R: ISO 8601 usa `T` como separador entre fecha y hora. El PO lo especificó así.

**P: ¿Qué pasa si intento ver un fraccionamiento de otra notaría?**
R: Retorna 404, no 403. Esto es intencional para no revelar que existe.

**P: ¿El nombre debe ser único globalmente?**
R: No, solo único por notaría. Dos notarías pueden tener "Residencial Centro".

**P: ¿Debo hacer trim al nombre antes de comparar?**
R: Sí, en el Service antes de validar unicidad y antes de guardar.

## Contacto

- Para dudas de negocio: Ver `specs/03_subdivisions_crud/po/`
- Para dudas de arquitectura: Ver `specs/03_subdivisions_crud/arch/`
