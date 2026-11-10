# Criterios de Aceptación Técnicos

## Basado en: PO Acceptance Criteria
Referencia: `specs/05_notary_profile/po/acceptance_criteria.md`

---

## Criterios por Capa

### Controller Layer

#### GET /api/v3/notary
- [ ] Endpoint responde a `GET /api/v3/notary`
- [ ] Requiere middleware `auth`
- [ ] Obtiene `notary_id` de `$request->getNotaryId()`
- [ ] Retorna 200 con JSON de NotaryResponse
- [ ] Retorna 404 si notaría no existe o está inactiva

#### POST /api/v3/notary
- [ ] Endpoint responde a `POST /api/v3/notary`
- [ ] Requiere middleware `auth`
- [ ] Acepta `Content-Type: multipart/form-data`
- [ ] Lee campos de texto desde `$_POST`
- [ ] Lee archivo logo desde `$_FILES['logo']`
- [ ] Valida request con `UpdateNotaryRequest::fromArray()`
- [ ] Retorna 204 en éxito
- [ ] Retorna 400 con errores de validación
- [ ] Retorna 404 si notaría no existe o está inactiva

#### GET /api/v3/notary/logo
- [ ] Endpoint responde a `GET /api/v3/notary/logo`
- [ ] Requiere middleware `auth`
- [ ] Retorna 200 con imagen binaria
- [ ] Header `Content-Type: image/jpeg` o `image/png`
- [ ] Header `Content-Length` con tamaño del archivo
- [ ] Header `Content-Disposition: inline`
- [ ] Header `Cache-Control: public, max-age=3600`
- [ ] Retorna 404 si no existe logo
- [ ] Retorna 404 si notaría no existe o está inactiva

---

### Service Layer

#### NotaryService
- [ ] Constructor recibe `NotaryRepository`
- [ ] Método `getByIdActive(int $notaryId): ?Notary`
  - [ ] Retorna null si no existe o está inactiva
- [ ] Método `update(int $notaryId, UpdateNotaryRequest $request, ?array $file): bool|string`
  - [ ] Verifica que notaría existe y está activa
  - [ ] Actualiza solo campos proporcionados (no vacíos)
  - [ ] Llama a `saveLogo()` si viene archivo
  - [ ] Retorna `true` en éxito, mensaje de error en fallo
- [ ] Método `saveLogo(int $notaryId, array $file): bool|string`
  - [ ] Valida extensión (jpg, jpeg, png)
  - [ ] Valida tamaño (≤ 524288 bytes)
  - [ ] Valida MIME real con `finfo`
  - [ ] Valida que sea imagen real con `getimagesize()`
  - [ ] Crea directorio si no existe
  - [ ] Elimina logo anterior si tiene extensión diferente
  - [ ] Guarda archivo con nombre `logo_{notaryId}.{ext}`
  - [ ] Actualiza campo `logo` en BD
- [ ] Método `getLogoInfo(int $notaryId): ?array`
  - [ ] Retorna `['path' => string, 'mimeType' => string]` o null

---

### Repository Layer

#### NotaryRepository
- [ ] Constructor recibe `PDO`
- [ ] Método `findByIdActive(int $id): ?Notary`
  - [ ] Query filtra por `id_estatus = 1`
  - [ ] Retorna Entity hidratada o null
- [ ] Método `update(int $id, array $fields): void`
  - [ ] Construye UPDATE dinámico solo con campos proporcionados
  - [ ] Usa prepared statements
- [ ] Método `updateLogo(int $id, string $logoPath): void`
  - [ ] Actualiza solo el campo `logo`

---

### Entity

#### Notary
- [ ] Propiedades readonly tipadas:
  - `int $id`
  - `string $name`
  - `string $notaryName`
  - `string $city`
  - `string $address`
  - `string $phone`
  - `string $rfc`
  - `string $logo`
  - `int $status`
- [ ] Método estático `fromRow(array $row): self`
- [ ] Mapeo correcto de columnas españolas a inglesas

---

### DTOs

#### UpdateNotaryRequest
- [ ] Propiedades:
  - `string $name` (obligatorio)
  - `string $notaryName` (obligatorio)
  - `?string $city`
  - `?string $address`
  - `?string $phone`
  - `?string $rfc`
- [ ] Método `fromArray(array $data): self`
- [ ] Validaciones:
  - [ ] `name` requerido, máx 500 chars
  - [ ] `notaryName` requerido, máx 500 chars
  - [ ] `city` máx 150 chars si presente
  - [ ] `address` máx 500 chars si presente
  - [ ] `phone` máx 20 chars, solo dígitos/espacios/guiones si presente
  - [ ] `rfc` máx 20 chars si presente
- [ ] Método `isValid(): bool`
- [ ] Método `getErrors(): array`
- [ ] Método `hasField(string $field): bool` (para saber si vino en request)

#### NotaryResponse
- [ ] Método `fromEntity(Notary $notary): array`
- [ ] Campos en respuesta:
  - `id: int`
  - `name: string`
  - `notaryName: string`
  - `city: string`
  - `address: string`
  - `phone: string`
  - `rfc: string`
  - `hasLogo: bool` (calculado desde `$notary->logo !== ''`)
  - `status: int`

---

## Validación de Archivos

### Checklist de seguridad para logo
- [ ] Validar extensión contra whitelist: `['jpg', 'jpeg', 'png']`
- [ ] Validar tamaño con `$_FILES['logo']['size'] <= 524288`
- [ ] Validar MIME real:
  ```php
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($_FILES['logo']['tmp_name']);
  in_array($mime, ['image/jpeg', 'image/png'])
  ```
- [ ] Validar contenido de imagen:
  ```php
  $info = getimagesize($_FILES['logo']['tmp_name']);
  $info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG])
  ```
- [ ] Verificar que `$_FILES['logo']['error'] === UPLOAD_ERR_OK`

---

## Tests Requeridos

### Unit Tests (cuando se implementen)
- [ ] `NotaryServiceTest`
  - Test validación de MIME
  - Test validación de tamaño
  - Test actualización parcial de campos

### Integration Tests (manual por ahora)
- [ ] GET /notary con token válido → 200
- [ ] GET /notary con notaría inactiva → 404
- [ ] POST /notary con campos válidos → 204
- [ ] POST /notary sin name → 400
- [ ] POST /notary con logo válido → 204 + archivo creado
- [ ] POST /notary con logo >512KB → 400
- [ ] POST /notary con archivo no-imagen → 400
- [ ] GET /notary/logo con logo existente → 200 + imagen
- [ ] GET /notary/logo sin logo → 404

---

## Criterios de Código

- [ ] PSR-12 coding style
- [ ] `declare(strict_types=1)` en todos los archivos
- [ ] Typed properties y return types
- [ ] Sin dependencias circulares
- [ ] Namespace correcto: `App\Module\Notary\*`
- [ ] Principios SOLID aplicados

---

## Filesystem

### Estructura esperada
```
public/
└── assets/
    └── {notary_id}/
        └── logo/
            └── logo_{notary_id}.{jpg|png}
```

### Permisos
- [ ] Directorio `public/assets/` existe o se puede crear
- [ ] Permisos 755 para directorios
- [ ] Permisos 644 para archivos de logo
- [ ] Usuario de Apache/PHP tiene permisos de escritura

---

## Seguridad

- [ ] Autenticación JWT requerida en todos los endpoints
- [ ] `notary_id` siempre viene del token, nunca del request
- [ ] No se exponen rutas del filesystem en respuestas JSON
- [ ] Input sanitizado (trim de strings)
- [ ] Prepared statements en todas las queries
- [ ] Validación doble de archivos (extensión + MIME + contenido)
- [ ] No se permite Path Traversal en nombres de archivo

---

## Performance

- [ ] Query por `id_notaria` usa índice primario
- [ ] Archivo de logo se lee una sola vez para servir
- [ ] Headers de cache permiten cacheo por 1 hora
