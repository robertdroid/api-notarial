# Notas para el Developer

## Contexto Rápido

Construir el módulo CRUD de Actos Jurídicos siguiendo exactamente los patrones del módulo Auth. Los actos son un catálogo por notaría que clasifica las escrituras. Multi-tenant obligatorio: todo filtrado por `notary_id` del token JWT.

---

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/Act/Controller/ActController.php` | 4 endpoints: index, show, store, update |
| `src/Module/Act/Service/ActService.php` | Lógica de negocio, validación de unicidad |
| `src/Module/Act/Repository/ActRepository.php` | Queries a `actos_notaria` |
| `src/Module/Act/Entity/Act.php` | Entidad de dominio |
| `src/Module/Act/DTO/CreateActRequest.php` | Validación POST |
| `src/Module/Act/DTO/UpdateActRequest.php` | Validación PUT |
| `src/Module/Act/DTO/ActResponse.php` | Formato de respuesta |
| `src/Config/routes.php` | Registrar rutas con middleware |
| `public/index.php` | Registrar servicios en ServiceLocator |

---

## Patrones a Seguir

Revisar estas implementaciones como referencia:

| Referencia | Qué copiar |
|------------|-----------|
| `AuthController.php` | Estructura del controller, uso de ServiceLocator |
| `AuthService.php` | Inyección de dependencias en constructor |
| `UserAuthRepository.php` | Queries con PDO y prepared statements |
| `AuthUser.php` (DTO) | Estructura de DTO con propiedades públicas |
| `TokenResponse.php` | Método `toArray()` para serialización |

---

## Código de Referencia

### Estructura del Controller

```php
<?php

declare(strict_types=1);

namespace App\Module\Act\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Act\Service\ActService;
use App\Module\Act\DTO\CreateActRequest;
use App\Module\Act\DTO\UpdateActRequest;
use App\Module\Act\DTO\ActResponse;

class ActController
{
    private ActService $actService;

    public function __construct()
    {
        $this->actService = ServiceLocator::get(ActService::class);
    }

    public function index(Request $request): Response
    {
        $notaryId = $request->getNotaryId();
        $status = $request->getQueryParam('status');
        $name = $request->getQueryParam('name');

        // Validar status si viene
        if ($status !== null && !in_array($status, ['1', '2'], true)) {
            return Response::badRequest('Invalid status value');
        }

        $acts = $this->actService->getAll(
            $notaryId,
            $status !== null ? (int) $status : null,
            $name
        );

        return Response::json(ActResponse::fromCollection($acts));
    }

    public function show(Request $request): Response
    {
        $id = $request->getRouteParam('id');
        
        if (!is_numeric($id)) {
            return Response::badRequest('Invalid ID format');
        }

        $act = $this->actService->getById((int) $id, $request->getNotaryId());

        if ($act === null) {
            return Response::notFound('Act not found');
        }

        return Response::json(ActResponse::fromEntity($act));
    }

    public function store(Request $request): Response
    {
        $dto = CreateActRequest::fromArray($request->getBody());
        
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->actService->create(
            $dto,
            $request->getNotaryId(),
            $request->getUserId()
        );

        if ($result !== true) {
            return Response::badRequest($result); // Error message
        }

        return Response::created();
    }

    public function update(Request $request): Response
    {
        $id = $request->getRouteParam('id');
        
        if (!is_numeric($id)) {
            return Response::badRequest('Invalid ID format');
        }

        $dto = UpdateActRequest::fromArray($request->getBody());
        
        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        $result = $this->actService->update(
            (int) $id,
            $dto,
            $request->getNotaryId(),
            $request->getUserId()
        );

        if ($result === null) {
            return Response::notFound('Act not found');
        }

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::noContent();
    }
}
```

### Estructura del Service

```php
<?php

declare(strict_types=1);

namespace App\Module\Act\Service;

use App\Module\Act\Repository\ActRepository;
use App\Module\Act\Entity\Act;
use App\Module\Act\DTO\CreateActRequest;
use App\Module\Act\DTO\UpdateActRequest;

class ActService
{
    public function __construct(
        private readonly ActRepository $repository
    ) {}

    public function getAll(int $notaryId, ?int $status, ?string $name): array
    {
        return $this->repository->findAllByNotary($notaryId, $status, $name);
    }

    public function getById(int $id, int $notaryId): ?Act
    {
        return $this->repository->findByIdAndNotary($id, $notaryId);
    }

    public function create(CreateActRequest $request, int $notaryId, int $userId): bool|string
    {
        $name = trim($request->actName);

        if ($this->repository->existsByNameAndNotary($name, $notaryId)) {
            return 'Act name already exists';
        }

        $act = new Act(
            id: 0,
            notaryId: $notaryId,
            name: $name,
            description: trim($request->actDescription),
            userId: $userId,
            modificationDate: new \DateTime(),
            statusId: $request->status ?? 1
        );

        $this->repository->create($act);
        return true;
    }

    public function update(int $id, UpdateActRequest $request, int $notaryId, int $userId): bool|string|null
    {
        $existing = $this->repository->findByIdAndNotary($id, $notaryId);
        
        if ($existing === null) {
            return null; // Not found
        }

        $name = trim($request->actName);

        if ($this->repository->existsByNameAndNotary($name, $notaryId, $id)) {
            return 'Act name already exists';
        }

        $act = new Act(
            id: $id,
            notaryId: $notaryId,
            name: $name,
            description: trim($request->actDescription),
            userId: $userId,
            modificationDate: new \DateTime(),
            statusId: $request->status
        );

        $this->repository->update($act);
        return true;
    }
}
```

### Estructura del Repository

```php
<?php

declare(strict_types=1);

namespace App\Module\Act\Repository;

use App\Module\Act\Entity\Act;
use PDO;

class ActRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findAllByNotary(int $notaryId, ?int $status, ?string $name): array
    {
        $sql = "SELECT * FROM actos_notaria WHERE id_notaria = :notaryId";
        $params = ['notaryId' => $notaryId];

        if ($status !== null) {
            $sql .= " AND id_status = :status";
            $params['status'] = $status;
        }

        if ($name !== null && $name !== '') {
            $sql .= " AND nombre_acto LIKE :name";
            $params['name'] = '%' . $name . '%';
        }

        $sql .= " ORDER BY nombre_acto ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $acts = [];
        while ($row = $stmt->fetch()) {
            $acts[] = Act::fromRow($row);
        }

        return $acts;
    }

    public function findByIdAndNotary(int $id, int $notaryId): ?Act
    {
        $sql = "SELECT * FROM actos_notaria WHERE id_acto = :id AND id_notaria = :notaryId";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);

        $row = $stmt->fetch();

        return $row ? Act::fromRow($row) : null;
    }

    public function existsByNameAndNotary(string $name, int $notaryId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM actos_notaria WHERE LOWER(nombre_acto) = LOWER(:name) AND id_notaria = :notaryId";
        $params = ['name' => $name, 'notaryId' => $notaryId];

        if ($excludeId !== null) {
            $sql .= " AND id_acto != :excludeId";
            $params['excludeId'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(Act $act): int
    {
        $sql = "INSERT INTO actos_notaria (id_notaria, nombre_acto, descripcion_acto, id_usuario, fecha_modificacion, id_status)
                VALUES (:notaryId, :name, :description, :userId, :modDate, :status)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $act->notaryId,
            'name' => $act->name,
            'description' => $act->description,
            'userId' => $act->userId,
            'modDate' => $act->modificationDate->format('Y-m-d H:i:s'),
            'status' => $act->statusId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(Act $act): void
    {
        $sql = "UPDATE actos_notaria 
                SET nombre_acto = :name, 
                    descripcion_acto = :description, 
                    id_usuario = :userId, 
                    fecha_modificacion = :modDate, 
                    id_status = :status
                WHERE id_acto = :id AND id_notaria = :notaryId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $act->id,
            'notaryId' => $act->notaryId,
            'name' => $act->name,
            'description' => $act->description,
            'userId' => $act->userId,
            'modDate' => $act->modificationDate->format('Y-m-d H:i:s'),
            'status' => $act->statusId,
        ]);
    }
}
```

---

## Base de Datos

### Tabla: `actos_notaria`

```sql
-- Estructura esperada (ya existe)
CREATE TABLE actos_notaria (
    id_acto INT AUTO_INCREMENT PRIMARY KEY,
    id_notaria INT NOT NULL,
    nombre_acto VARCHAR(200) NOT NULL,
    descripcion_acto VARCHAR(500),
    id_usuario INT,
    fecha_modificacion DATETIME,
    id_status INT DEFAULT 1,
    FOREIGN KEY (id_notaria) REFERENCES notarias(id_notaria),
    FOREIGN KEY (id_usuario) REFERENCES usuarios_notaria(id_usuario)
);
```

### Índice recomendado (verificar si existe)

```sql
-- Para búsquedas y validación de unicidad
CREATE UNIQUE INDEX idx_actos_nombre_notaria 
ON actos_notaria (id_notaria, nombre_acto);
```

---

## Registro de Rutas

Agregar en `src/Config/routes.php`:

```php
use App\Module\Act\Controller\ActController;

// Acts (protected)
$router->get('/api/v3/acts', [ActController::class, 'index'])
    ->withMiddleware('auth');
$router->get('/api/v3/acts/{id}', [ActController::class, 'show'])
    ->withMiddleware('auth');
$router->post('/api/v3/acts', [ActController::class, 'store'])
    ->withMiddleware('auth');
$router->put('/api/v3/acts/{id}', [ActController::class, 'update'])
    ->withMiddleware('auth');
```

---

## Registro de Servicios

Agregar en `public/index.php` (donde se registra ServiceLocator):

```php
use App\Module\Act\Repository\ActRepository;
use App\Module\Act\Service\ActService;

ServiceLocator::register(ActRepository::class, fn() => new ActRepository($pdo));
ServiceLocator::register(ActService::class, fn() => new ActService(
    ServiceLocator::get(ActRepository::class)
));
```

---

## Checklist Pre-PR

- [ ] Todos los archivos tienen `declare(strict_types=1)`
- [ ] Namespaces correctos (`App\Module\Act\...`)
- [ ] Rutas registradas con middleware `auth`
- [ ] Servicios registrados en ServiceLocator
- [ ] Queries filtran por `notary_id`
- [ ] Prepared statements en todos los queries
- [ ] Sin código de debug (var_dump, echo, etc.)
- [ ] Probé manualmente los 4 endpoints
- [ ] Validé escenarios de otra notaría (debe dar 404)
- [ ] OpenAPI actualizado en `docs/openapi.yaml`

---

## Preguntas Frecuentes

**P: ¿Dónde obtengo el `notaryId` y `userId`?**
R: Del Request. `$request->getNotaryId()` y `$request->getUserId()`. El AuthMiddleware los extrae del JWT.

**P: ¿Cómo valido que el acto pertenece a la notaría?**
R: En el Repository, SIEMPRE incluye `AND id_notaria = :notaryId` en las queries. Si no existe con ese filtro, retorna null.

**P: ¿Por qué 404 y no 403 para actos de otra notaría?**
R: Seguridad por oscuridad. No revelamos que el recurso existe pero no tienes permiso. Simplemente "no existe".

**P: ¿Debo crear tests?**
R: Idealmente sí. Ver criterios técnicos. Mínimo probar manualmente todos los escenarios del PO.

---

## Contacto

- **Dudas de negocio:** Revisar `specs/02_acts_crud/po/`
- **Dudas de arquitectura:** Revisar `specs/02_acts_crud/arch/`
