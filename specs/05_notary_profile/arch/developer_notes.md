# Notas para el Developer

## Contexto Rápido

Construir el módulo de perfil de notaría con 3 endpoints. A diferencia de otros módulos CRUD, este NO recibe ID en la ruta - el `notary_id` siempre viene del token JWT. Incluye manejo de archivos para el logo.

---

## Archivos Clave

| Archivo | Propósito |
|---------|-----------|
| `src/Module/Notary/Controller/NotaryController.php` | 3 endpoints: show, update, logo |
| `src/Module/Notary/Service/NotaryService.php` | Lógica de negocio + manejo de archivos |
| `src/Module/Notary/Repository/NotaryRepository.php` | Queries a `notarias` |
| `src/Module/Notary/Entity/Notary.php` | Entidad de dominio |
| `src/Module/Notary/DTO/UpdateNotaryRequest.php` | Validación POST |
| `src/Module/Notary/DTO/NotaryResponse.php` | Formato de respuesta |
| `src/Core/Http/Response.php` | Agregar método `file()` |
| `src/Config/routes.php` | Registrar rutas con middleware |
| `src/bootstrap.php` | Registrar servicios en ServiceLocator |

---

## Patrones a Seguir

Revisar estas implementaciones como referencia:

| Referencia | Qué copiar |
|------------|-----------|
| `src/Module/Subdivision/Controller/SubdivisionController.php` | Estructura de controller, uso de ServiceLocator |
| `src/Module/Subdivision/Service/SubdivisionService.php` | Patrón de service con repository |
| `src/Module/Subdivision/DTO/UpdateSubdivisionRequest.php` | Validación de DTOs |
| `src/Module/Subdivision/Entity/Subdivision.php` | Estructura de entity con `fromRow()` |

---

## Código de Referencia

### 1. Entity Notary

```php
<?php

declare(strict_types=1);

namespace App\Module\Notary\Entity;

final class Notary
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $notaryName,
        public readonly string $city,
        public readonly string $address,
        public readonly string $phone,
        public readonly string $rfc,
        public readonly string $logo,
        public readonly int $status,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id_notaria'],
            name: $row['nombre_notaria'],
            notaryName: $row['notario_titular'],
            city: $row['ciudad'],
            address: $row['direccion'],
            phone: $row['telefono'],
            rfc: $row['rfc'],
            logo: $row['logo'],
            status: (int) $row['id_estatus'],
        );
    }

    public function hasLogo(): bool
    {
        return $this->logo !== '';
    }
}
```

### 2. NotaryRepository

```php
<?php

declare(strict_types=1);

namespace App\Module\Notary\Repository;

use App\Module\Notary\Entity\Notary;
use PDO;

class NotaryRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findByIdActive(int $id): ?Notary
    {
        $sql = "SELECT * FROM notarias WHERE id_notaria = :id AND id_estatus = 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? Notary::fromRow($row) : null;
    }

    public function update(int $id, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $setParts = [];
        $params = ['id' => $id];

        // Mapeo de campos API a columnas BD
        $columnMap = [
            'name' => 'nombre_notaria',
            'notaryName' => 'notario_titular',
            'city' => 'ciudad',
            'address' => 'direccion',
            'phone' => 'telefono',
            'rfc' => 'rfc',
            'logo' => 'logo',
        ];

        foreach ($fields as $field => $value) {
            if (isset($columnMap[$field])) {
                $column = $columnMap[$field];
                $setParts[] = "{$column} = :{$field}";
                $params[$field] = $value;
            }
        }

        if (empty($setParts)) {
            return;
        }

        $sql = "UPDATE notarias SET " . implode(', ', $setParts) . " WHERE id_notaria = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
```

### 3. UpdateNotaryRequest DTO

```php
<?php

declare(strict_types=1);

namespace App\Module\Notary\DTO;

final class UpdateNotaryRequest
{
    public readonly string $name;
    public readonly string $notaryName;
    public readonly ?string $city;
    public readonly ?string $address;
    public readonly ?string $phone;
    public readonly ?string $rfc;

    private array $errors = [];
    private array $providedFields = [];

    private function __construct(
        string $name,
        string $notaryName,
        ?string $city,
        ?string $address,
        ?string $phone,
        ?string $rfc,
        array $providedFields,
    ) {
        $this->name = trim($name);
        $this->notaryName = trim($notaryName);
        $this->city = $city !== null ? trim($city) : null;
        $this->address = $address !== null ? trim($address) : null;
        $this->phone = $phone !== null ? trim($phone) : null;
        $this->rfc = $rfc !== null ? trim($rfc) : null;
        $this->providedFields = $providedFields;
    }

    public static function fromArray(array $data): self
    {
        // Tracking de qué campos vinieron en el request
        $providedFields = array_keys($data);

        $instance = new self(
            name: $data['name'] ?? '',
            notaryName: $data['notaryName'] ?? '',
            city: $data['city'] ?? null,
            address: $data['address'] ?? null,
            phone: $data['phone'] ?? null,
            rfc: $data['rfc'] ?? null,
            providedFields: $providedFields,
        );

        $instance->validate();

        return $instance;
    }

    private function validate(): void
    {
        // Obligatorios
        if (empty($this->name)) {
            $this->errors['name'] = 'Notary name is required';
        } elseif (mb_strlen($this->name) > 500) {
            $this->errors['name'] = 'Notary name must not exceed 500 characters';
        }

        if (empty($this->notaryName)) {
            $this->errors['notaryName'] = 'Notary titular name is required';
        } elseif (mb_strlen($this->notaryName) > 500) {
            $this->errors['notaryName'] = 'Notary titular name must not exceed 500 characters';
        }

        // Opcionales - validar solo si vienen
        if ($this->city !== null && mb_strlen($this->city) > 150) {
            $this->errors['city'] = 'City must not exceed 150 characters';
        }

        if ($this->address !== null && mb_strlen($this->address) > 500) {
            $this->errors['address'] = 'Address must not exceed 500 characters';
        }

        if ($this->phone !== null) {
            if (mb_strlen($this->phone) > 20) {
                $this->errors['phone'] = 'Phone must not exceed 20 characters';
            } elseif ($this->phone !== '' && !preg_match('/^[\d\s\-]+$/', $this->phone)) {
                $this->errors['phone'] = 'Phone must contain only digits, spaces, and hyphens';
            }
        }

        if ($this->rfc !== null && mb_strlen($this->rfc) > 20) {
            $this->errors['rfc'] = 'RFC must not exceed 20 characters';
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

    /**
     * Retorna true si el campo fue enviado en el request (incluso si está vacío)
     */
    public function hasField(string $field): bool
    {
        return in_array($field, $this->providedFields, true);
    }

    /**
     * Retorna solo los campos que fueron enviados y tienen valor
     */
    public function getFieldsToUpdate(): array
    {
        $fields = [];

        // Obligatorios siempre se actualizan
        $fields['name'] = $this->name;
        $fields['notaryName'] = $this->notaryName;

        // Opcionales solo si vinieron en el request
        if ($this->hasField('city') && $this->city !== null) {
            $fields['city'] = $this->city;
        }
        if ($this->hasField('address') && $this->address !== null) {
            $fields['address'] = $this->address;
        }
        if ($this->hasField('phone') && $this->phone !== null) {
            $fields['phone'] = $this->phone;
        }
        if ($this->hasField('rfc') && $this->rfc !== null) {
            $fields['rfc'] = $this->rfc;
        }

        return $fields;
    }
}
```

### 4. NotaryResponse DTO

```php
<?php

declare(strict_types=1);

namespace App\Module\Notary\DTO;

use App\Module\Notary\Entity\Notary;

final class NotaryResponse
{
    public static function fromEntity(Notary $notary): array
    {
        return [
            'id' => $notary->id,
            'name' => $notary->name,
            'notaryName' => $notary->notaryName,
            'city' => $notary->city,
            'address' => $notary->address,
            'phone' => $notary->phone,
            'rfc' => $notary->rfc,
            'hasLogo' => $notary->hasLogo(),
            'status' => $notary->status,
        ];
    }
}
```

### 5. NotaryService

```php
<?php

declare(strict_types=1);

namespace App\Module\Notary\Service;

use App\Module\Notary\Repository\NotaryRepository;
use App\Module\Notary\Entity\Notary;
use App\Module\Notary\DTO\UpdateNotaryRequest;

class NotaryService
{
    private const LOGO_MAX_SIZE = 524288; // 512 KB
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png'];
    private const ASSETS_PATH = BASE_PATH . '/public/assets';

    public function __construct(
        private readonly NotaryRepository $repository
    ) {}

    public function getByIdActive(int $notaryId): ?Notary
    {
        return $this->repository->findByIdActive($notaryId);
    }

    public function update(int $notaryId, UpdateNotaryRequest $request, ?array $file): bool|string
    {
        $notary = $this->repository->findByIdActive($notaryId);
        
        if ($notary === null) {
            return 'notary_not_found';
        }

        // Actualizar campos de texto
        $fields = $request->getFieldsToUpdate();
        $this->repository->update($notaryId, $fields);

        // Procesar logo si viene
        if ($file !== null && isset($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
            $logoResult = $this->saveLogo($notaryId, $file, $notary->logo);
            if ($logoResult !== true) {
                return $logoResult;
            }
        }

        return true;
    }

    public function saveLogo(int $notaryId, array $file, string $currentLogo): bool|string
    {
        // 1. Validar tamaño
        if ($file['size'] > self::LOGO_MAX_SIZE) {
            return 'Logo file exceeds maximum size of 512 KB';
        }

        // 2. Validar extensión
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return 'Logo must be JPG or PNG format';
        }

        // Normalizar jpeg a jpg
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // 3. Validar MIME real
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            return 'Invalid image file';
        }

        // 4. Validar que sea imagen real
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return 'Invalid image file';
        }

        // 5. Crear directorio si no existe
        $logoDir = self::ASSETS_PATH . "/{$notaryId}/logo";
        if (!is_dir($logoDir)) {
            if (!mkdir($logoDir, 0755, true)) {
                return 'Failed to create logo directory';
            }
        }

        // 6. Eliminar logo anterior si existe y tiene extensión diferente
        $this->deleteOldLogo($notaryId, $extension, $currentLogo);

        // 7. Guardar nuevo logo
        $filename = "logo_{$notaryId}.{$extension}";
        $fullPath = "{$logoDir}/{$filename}";
        
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            return 'Failed to save logo file';
        }

        chmod($fullPath, 0644);

        // 8. Actualizar BD
        $relativePath = "assets/{$notaryId}/logo/{$filename}";
        $this->repository->update($notaryId, ['logo' => $relativePath]);

        return true;
    }

    private function deleteOldLogo(int $notaryId, string $newExtension, string $currentLogo): void
    {
        if ($currentLogo === '') {
            return;
        }

        // Verificar si el logo actual tiene extensión diferente
        $currentExt = strtolower(pathinfo($currentLogo, PATHINFO_EXTENSION));
        if ($currentExt === 'jpeg') {
            $currentExt = 'jpg';
        }

        if ($currentExt !== $newExtension) {
            $oldPath = self::ASSETS_PATH . "/{$notaryId}/logo/logo_{$notaryId}.{$currentExt}";
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }
    }

    public function getLogoInfo(int $notaryId): ?array
    {
        $notary = $this->repository->findByIdActive($notaryId);
        
        if ($notary === null || !$notary->hasLogo()) {
            return null;
        }

        $fullPath = BASE_PATH . '/public/' . $notary->logo;
        
        if (!file_exists($fullPath)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fullPath);

        return [
            'path' => $fullPath,
            'mimeType' => $mimeType,
            'size' => filesize($fullPath),
        ];
    }
}
```

### 6. NotaryController

```php
<?php

declare(strict_types=1);

namespace App\Module\Notary\Controller;

use App\Core\Container\ServiceLocator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Module\Notary\Service\NotaryService;
use App\Module\Notary\DTO\UpdateNotaryRequest;
use App\Module\Notary\DTO\NotaryResponse;

class NotaryController
{
    private NotaryService $notaryService;

    public function __construct()
    {
        $this->notaryService = ServiceLocator::get(NotaryService::class);
    }

    public function show(Request $request): Response
    {
        $notaryId = $request->getNotaryId();
        
        $notary = $this->notaryService->getByIdActive($notaryId);
        
        if ($notary === null) {
            return Response::notFound('Notary not found');
        }

        return Response::json(NotaryResponse::fromEntity($notary));
    }

    public function update(Request $request): Response
    {
        $notaryId = $request->getNotaryId();

        // Para multipart, los datos vienen en $_POST
        $dto = UpdateNotaryRequest::fromArray($_POST);

        if (!$dto->isValid()) {
            return Response::badRequest('Validation failed', $dto->getErrors());
        }

        // Archivo de logo (opcional)
        $file = isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE 
            ? $_FILES['logo'] 
            : null;

        $result = $this->notaryService->update($notaryId, $dto, $file);

        if ($result === 'notary_not_found') {
            return Response::notFound('Notary not found');
        }

        if ($result !== true) {
            return Response::badRequest($result);
        }

        return Response::noContent();
    }

    public function logo(Request $request): Response
    {
        $notaryId = $request->getNotaryId();
        
        $logoInfo = $this->notaryService->getLogoInfo($notaryId);
        
        if ($logoInfo === null) {
            return Response::notFound('Logo not found');
        }

        return Response::file(
            $logoInfo['path'],
            $logoInfo['mimeType'],
            $logoInfo['size']
        );
    }
}
```

### 7. Agregar a Response.php

```php
// Agregar este método a src/Core/Http/Response.php

public static function file(string $path, string $mimeType, int $size): self
{
    // Enviamos headers especiales y el contenido del archivo
    return new self(
        body: null,  // El body se maneja especial en send()
        statusCode: 200,
        headers: [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=3600',
            'X-File-Path' => $path,  // Header interno para send()
        ]
    );
}

// Modificar el método send() para manejar archivos:
public function send(): void
{
    http_response_code($this->statusCode);

    foreach ($this->headers as $name => $value) {
        if ($name === 'X-File-Path') {
            continue; // No enviar este header interno
        }
        header("$name: $value");
    }

    // Si es un archivo, enviarlo
    if (isset($this->headers['X-File-Path'])) {
        readfile($this->headers['X-File-Path']);
        return;
    }

    if ($this->body !== null) {
        if (is_array($this->body) || is_object($this->body)) {
            echo json_encode($this->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo $this->body;
        }
    }
}
```

### 8. Rutas (routes.php)

```php
// Agregar después de las rutas de Deed Types

use App\Module\Notary\Controller\NotaryController;

// Notary profile routes (protected)
$router->get('/api/v3/notary', [NotaryController::class, 'show'])->withMiddleware('auth');
$router->post('/api/v3/notary', [NotaryController::class, 'update'])->withMiddleware('auth');
$router->get('/api/v3/notary/logo', [NotaryController::class, 'logo'])->withMiddleware('auth');
```

### 9. Bootstrap (bootstrap.php)

```php
// Agregar al final del archivo

use App\Module\Notary\Repository\NotaryRepository;
use App\Module\Notary\Service\NotaryService;

ServiceLocator::register(NotaryRepository::class, function () {
    return new NotaryRepository(Connection::getInstance());
});

ServiceLocator::register(NotaryService::class, function () {
    return new NotaryService(
        ServiceLocator::get(NotaryRepository::class)
    );
});
```

---

## Base de Datos

### Tabla: `notarias`

```sql
CREATE TABLE `notarias` (
  `id_notaria` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_notaria` varchar(500) NOT NULL DEFAULT '',
  `notario_titular` varchar(500) NOT NULL DEFAULT '',
  `ciudad` varchar(150) NOT NULL DEFAULT '',
  `direccion` varchar(500) NOT NULL DEFAULT '',
  `telefono` varchar(20) NOT NULL DEFAULT '',
  `rfc` varchar(20) NOT NULL DEFAULT '',
  `logo` varchar(100) NOT NULL DEFAULT '',
  `id_estatus` int(11) DEFAULT 1,
  PRIMARY KEY (`id_notaria`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3;
```

### Mapeo de columnas

| Columna BD | Campo API |
|------------|-----------|
| `id_notaria` | `id` |
| `nombre_notaria` | `name` |
| `notario_titular` | `notaryName` |
| `ciudad` | `city` |
| `direccion` | `address` |
| `telefono` | `phone` |
| `rfc` | `rfc` |
| `logo` | (interno, no expuesto) |
| `id_estatus` | `status` |

---

## Checklist Pre-PR

- [ ] Código sigue la arquitectura definida
- [ ] Todas las validaciones de archivo implementadas (ext, size, MIME, getimagesize)
- [ ] Carpeta `public/assets/` creada si no existe
- [ ] Response::file() implementado correctamente
- [ ] Tests manuales de los 3 endpoints pasando
- [ ] Sin warnings de PHP
- [ ] `docs/openapi.yaml` actualizado
- [ ] Revisé los criterios de aceptación técnicos

---

## Preguntas Frecuentes

**P: ¿Por qué POST en lugar de PUT para actualizar?**
R: PHP no parsea automáticamente `multipart/form-data` en PUT. Usar POST evita implementar un parser manual.

**P: ¿Cómo pruebo la subida de logo con curl?**
```bash
curl -X POST http://localhost:8080/api/v3/notary \
  -H "Authorization: Bearer <token>" \
  -F "name=Mi Notaría" \
  -F "notaryName=Lic. Juan Pérez" \
  -F "logo=@/ruta/al/logo.jpg"
```

**P: ¿Qué pasa si el directorio assets no existe?**
R: El service lo crea automáticamente con `mkdir($dir, 0755, true)`.

**P: ¿Cómo verifico que una imagen es real y no un archivo disfrazado?**
R: Triple validación:
1. Extensión del nombre de archivo
2. MIME type real con `finfo`
3. Contenido de imagen con `getimagesize()`

---

## Contacto

Para dudas de negocio: Revisar `specs/05_notary_profile/po/`
Para dudas de arquitectura: Revisar este documento o consultar con el arquitecto
