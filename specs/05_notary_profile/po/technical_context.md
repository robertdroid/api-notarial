# Contexto Técnico

## Entidades Involucradas

### Tabla: `notarias`
| Columna | Tipo | Campo API | Notas |
|---------|------|-----------|-------|
| `id_notaria` | int PK | `id` | Solo lectura |
| `nombre_notaria` | varchar(500) | `name` | Obligatorio |
| `notario_titular` | varchar(500) | `notaryName` | Obligatorio |
| `ciudad` | varchar(150) | `city` | Opcional |
| `direccion` | varchar(500) | `address` | Opcional |
| `telefono` | varchar(20) | `phone` | Opcional, solo dígitos/espacios/guiones |
| `rfc` | varchar(20) | `rfc` | Opcional |
| `logo` | varchar(100) | - | Ruta relativa, no expuesta directamente |
| `id_estatus` | int | `status` | Solo lectura, 1 = activo |

### Relaciones
- `notarias.id_notaria` es referenciada por `usuarios_notaria.notaria_id`
- El `notary_id` viene del token JWT del usuario autenticado

## Endpoints

### GET /api/v3/notary
- **Auth:** JWT requerido
- **Response 200:**
```json
{
  "id": 3,
  "name": "Notaría 45 de Guadalajara",
  "notaryName": "Lic. Juan Pérez López",
  "city": "Guadalajara",
  "address": "Av. Vallarta 1234, Col. Centro",
  "phone": "33 1234-5678",
  "rfc": "NPL850101ABC",
  "hasLogo": true,
  "status": 1
}
```
- **Nota:** `hasLogo` indica si la notaría tiene logo, la imagen se obtiene con el endpoint dedicado

### POST /api/v3/notary
- **Auth:** JWT requerido
- **Content-Type:** `multipart/form-data`
- **Body:**

| Campo | Tipo | Requerido |
|-------|------|-----------|
| `name` | string | Sí |
| `notaryName` | string | Sí |
| `city` | string | No |
| `address` | string | No |
| `phone` | string | No |
| `rfc` | string | No |
| `logo` | file | No |

- **Response 204:** Sin contenido
- **Response 400:** Errores de validación

### GET /api/v3/notary/logo
- **Auth:** JWT requerido
- **Response 200:** Imagen binaria con `Content-Type: image/jpeg` o `image/png`
- **Response 404:** Logo no encontrado
- **Headers sugeridos:** `Content-Disposition: inline`, `Cache-Control: max-age=3600`

## Dependencias

- **Módulo Auth:** Token JWT con `notary_id`
- **Middleware AuthMiddleware:** Validación de token
- **Filesystem:** Permisos de escritura en `public/assets/`

## Almacenamiento de Logo

### Estructura de carpetas
```
public/
└── assets/
    └── {notary_id}/
        └── logo/
            └── logo_{notary_id}.{jpg|png}
```

### Ejemplo
- Notaría ID 3 con logo JPG: `public/assets/3/logo/logo_3.jpg`
- Valor en BD: `assets/3/logo/logo_3.jpg`

### Proceso de subida
1. Validar extensión (jpg, jpeg, png)
2. Validar tamaño (≤ 512 KB = 524288 bytes)
3. Validar MIME real con `finfo_file()` o `getimagesize()`
4. Crear directorio si no existe: `public/assets/{notary_id}/logo/`
5. Si existe logo anterior con diferente extensión, eliminarlo
6. Guardar archivo como `logo_{notary_id}.{ext}`
7. Actualizar campo `logo` en BD con ruta relativa

## Impacto en Sistema Existente

### Nuevos archivos
```
src/Module/Notary/
├── Controller/NotaryController.php
├── Service/NotaryService.php
├── Repository/NotaryRepository.php
├── Entity/Notary.php
├── DTO/
│   ├── UpdateNotaryRequest.php
│   └── NotaryResponse.php
```

### Modificaciones
- `src/Config/routes.php` - Agregar rutas del módulo
- `public/index.php` - Registrar servicios en ServiceLocator

### Carpetas a crear
- `public/assets/` (si no existe)
- Permisos 755 para directorios, 644 para archivos

## Consideraciones de Datos

- No se requieren migraciones de BD (tabla `notarias` ya existe)
- El campo `logo` puede estar vacío en notarías existentes
- Las notarías inactivas (`id_estatus != 1`) retornan 404 en todos los endpoints

## Notas para Implementación

### Validación de imagen
```php
// Validar MIME real
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($tmpPath);
$allowedMimes = ['image/jpeg', 'image/png'];

// Validar que sea imagen real
$imageInfo = getimagesize($tmpPath);
if ($imageInfo === false) {
    // No es una imagen válida
}
```

### Servir imagen (GET /notary/logo)
```php
// Headers para imagen
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline');
header('Cache-Control: public, max-age=3600');

// Enviar contenido
readfile($filePath);
exit;
```

### Manejo de multipart en POST
PHP maneja automáticamente `multipart/form-data` en POST:
- Campos de texto en `$_POST`
- Archivos en `$_FILES['logo']`

El Request actual del framework debe ser compatible con esto.
