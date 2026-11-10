# Criterios de Aceptación

## Funcionales

### GET /api/v3/notary - Consultar Notaría

#### Escenario 1: Consulta exitosa
**Dado** un usuario autenticado con token válido  
**Cuando** realiza GET a `/api/v3/notary`  
**Entonces** recibe 200 con la información completa de la notaría

#### Escenario 2: Notaría inactiva
**Dado** un usuario cuya notaría tiene `id_estatus != 1`  
**Cuando** realiza GET a `/api/v3/notary`  
**Entonces** recibe 404 "Notary not found"

#### Escenario 3: Token inválido o expirado
**Dado** una petición sin token o con token inválido  
**Cuando** realiza GET a `/api/v3/notary`  
**Entonces** recibe 401 "Unauthorized"

---

### POST /api/v3/notary - Actualizar Notaría

#### Escenario 4: Actualización completa sin logo
**Dado** un usuario autenticado  
**Cuando** envía POST multipart con `name`, `notaryName`, `city`, `address`, `phone`, `rfc`  
**Entonces** actualiza todos los campos y recibe 204

#### Escenario 5: Actualización parcial
**Dado** un usuario autenticado  
**Cuando** envía POST multipart solo con `name` y `notaryName`  
**Entonces** actualiza solo esos campos, los demás permanecen sin cambio, y recibe 204

#### Escenario 6: Actualización con logo válido
**Dado** un usuario autenticado  
**Cuando** envía POST multipart con campo `logo` conteniendo imagen JPG de 400 KB  
**Entonces** guarda la imagen en `public/assets/{notary_id}/logo/logo_{notary_id}.jpg`, actualiza la BD con la ruta, y recibe 204

#### Escenario 7: Reemplazo de logo con diferente formato
**Dado** una notaría que ya tiene `logo_3.png`  
**Cuando** el usuario sube un nuevo logo en formato JPG  
**Entonces** elimina `logo_3.png`, guarda `logo_3.jpg`, actualiza la BD

#### Escenario 8: Logo excede tamaño máximo
**Dado** un usuario autenticado  
**Cuando** envía POST con imagen de 600 KB  
**Entonces** recibe 400 con mensaje "Logo file exceeds maximum size of 512 KB"

#### Escenario 9: Logo con formato no soportado
**Dado** un usuario autenticado  
**Cuando** envía POST con archivo GIF o BMP  
**Entonces** recibe 400 con mensaje "Logo must be JPG or PNG format"

#### Escenario 10: Archivo disfrazado (extensión válida, contenido inválido)
**Dado** un usuario autenticado  
**Cuando** envía POST con archivo `.jpg` que en realidad es un PDF  
**Entonces** recibe 400 con mensaje "Invalid image file"

#### Escenario 11: Campos obligatorios faltantes
**Dado** un usuario autenticado  
**Cuando** envía POST sin `name` o sin `notaryName`  
**Entonces** recibe 400 con mensaje indicando el campo faltante

#### Escenario 12: Notaría inactiva
**Dado** un usuario cuya notaría tiene `id_estatus != 1`  
**Cuando** realiza POST a `/api/v3/notary`  
**Entonces** recibe 404 "Notary not found"

#### Escenario 13: Teléfono con caracteres inválidos
**Dado** un usuario autenticado  
**Cuando** envía POST con `phone` conteniendo letras o símbolos especiales  
**Entonces** recibe 400 con mensaje "Phone must contain only digits, spaces, and hyphens"

---

### GET /api/v3/notary/logo - Obtener Logo

#### Escenario 14: Logo existe
**Dado** una notaría con logo guardado  
**Cuando** realiza GET a `/api/v3/notary/logo`  
**Entonces** recibe 200 con la imagen binaria y Content-Type apropiado (image/jpeg o image/png)

#### Escenario 15: Logo no existe
**Dado** una notaría sin logo configurado  
**Cuando** realiza GET a `/api/v3/notary/logo`  
**Entonces** recibe 404 "Logo not found"

#### Escenario 16: Notaría inactiva
**Dado** un usuario cuya notaría tiene `id_estatus != 1`  
**Cuando** realiza GET a `/api/v3/notary/logo`  
**Entonces** recibe 404 "Notary not found"

---

## No Funcionales

- **Rendimiento:** La subida del logo no debe exceder 5 segundos en condiciones normales
- **Seguridad:** Validar MIME type real del archivo, no solo la extensión
- **Almacenamiento:** El logo se guarda en filesystem, no en base de datos

## Validaciones

| Campo | Regla |
|-------|-------|
| `name` | Obligatorio, máximo 500 caracteres |
| `notaryName` | Obligatorio, máximo 500 caracteres |
| `city` | Opcional, máximo 150 caracteres |
| `address` | Opcional, máximo 500 caracteres |
| `phone` | Opcional, máximo 20 caracteres, solo dígitos, espacios y guiones |
| `rfc` | Opcional, máximo 20 caracteres |
| `logo` | Opcional, JPG/PNG, máximo 512 KB, MIME validado |

## Casos Edge

- Subir logo cuando no existe la carpeta `public/assets/{notary_id}/logo/` → crearla automáticamente
- Logo actual con extensión diferente al nuevo → eliminar el anterior antes de guardar
- Enviar campo `logo` vacío en el form → ignorar, no modificar logo existente
- Campos de texto con espacios en blanco → trimear antes de guardar

## Criterios de Rechazo

- Permitir subir archivos que no sean imágenes reales (validación solo por extensión)
- Exponer rutas del filesystem en la respuesta del GET
- Permitir actualizar `id` o `status` de la notaría
- No eliminar el logo anterior al reemplazarlo (acumular archivos)
