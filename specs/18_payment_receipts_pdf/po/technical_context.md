# Contexto Técnico

## Entidades Involucradas

Solo lectura. Este feature no requiere migraciones.

### Trámites: `payments_transactions` (spec 11)
Campos usados: `id_transaction`, `uuid`, `id_notary`, `id_client`, `id_procedure`, `reference`, `date`, `responsible`, `fee`, `taxes`, `total`, `comments`, `id_status`.

### Detalle de trámite: `payments_transaction_details` (spec 11)
Campos usados: `id_transaction`, `id_tax`, `quantity`, `amount`, `date`, `comments`.

### Cobros: `payments_payments` (spec 17)
Campos usados: `id_payment`, `uuid`, `id_notary`, `id_client`, `amount`, `payment_date`, `comments`. Sin columna de estatus.

### Relacionadas (solo lectura)
- `payments_clients`: nombre, teléfono y correo del cliente
- `payment_procedures`: nombre del procedimiento
- `payment_taxes`: nombre del impuesto
- `notaria`: datos de encabezado (`nombre_notaria`, `notario_titular`, `ciudad`, `direccion`, `telefono`, `rfc`, `logo`, `id_estatus`). Se lee con `NotaryRepository` / `Notary` entity, que ya existen.

### Logo
- Ya existe el manejo de logo en el módulo `Notary` (`NotaryService::getLogoInfo`, `NotaryService::saveLogo`).
- Arch debe confirmar la ruta física del archivo y si el logo se puede leer desde disco durante la generación del PDF.

## Endpoints Propuestos

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/v3/payment-transactions/{id}/receipt` | Recibo de trámite por id |
| GET | `/api/v3/payment-transactions/uuid/{uuid}/receipt` | Recibo de trámite por uuid |
| GET | `/api/v3/payments/{id}/receipt` | Recibo de cobro por id |
| GET | `/api/v3/payments/uuid/{uuid}/receipt` | Recibo de cobro por uuid |

### Cambio respecto al resumen previo

En el resumen anterior propuse `GET /api/v3/payment-transactions/receipt?uuid=…`. **Lo cambio** por este motivo: el router resuelve `GET /api/v3/payment-transactions/{id}` (un segmento), así que `/payment-transactions/receipt?uuid=` sería capturado como `{id}="receipt"`. Depender del orden de registro de rutas es frágil. Con `/uuid/{uuid}/receipt` no hay ambigüedad: tiene tres segmentos después del recurso y no compite con ninguna ruta existente.

Arch debe confirmar el comportamiento del router (`src/Core/Http/Router.php`) y que las cuatro rutas no colisionen.

### Respuesta exitosa

```
200 OK
Content-Type: application/pdf
Content-Disposition: inline; filename="recibo-tramite-T-000025.pdf"
Cache-Control: no-store

<binario PDF>
```

Nombres de archivo:
- Trámite: `recibo-tramite-T-{id:06}.pdf`
- Cobro: `recibo-cobro-C-{id:06}.pdf`

### Respuestas de error (JSON)

```json
// 400 Bad Request
{ "error": "Invalid id" }
{ "error": "Invalid uuid" }

// 401 Unauthorized (middleware auth)

// 404 Not Found
{ "error": "Payment transaction not found" }
{ "error": "Payment not found" }

// 500 Internal Server Error
{ "error": "Could not generate receipt" }
```

Si la generación falla a mitad del proceso, **no** se devuelve un PDF parcial: se responde 500 con JSON.

## Dependencias

### Módulos existentes
- **Auth** (spec 01): JWT con `notaryId`
- **Notary** (spec 05): datos de encabezado y logo
- **PaymentTransaction** (spec 11): datos del trámite y detalle
- **PaymentClient** (spec 09): datos del cliente
- **Payment** (spec 17): datos del cobro

### Librerías nuevas (requieren `composer require`)
- **PDF**: propuesta **Dompdf**. Permite diseñar con HTML/CSS, soporta paginación con script en el footer y tiene buen soporte de UTF-8.
- **QR**: propuesta **endroid/qr-code** o **chillerlan/php-qrcode**. Arch elige una.

Arch debe verificar:
- Compatibilidad con `php >= 8.2` (`composer.json`) y con `php:8.3-apache` (`docker/Dockerfile`)
- Extensiones PHP necesarias: `gd` y `mbstring` ya están en el Dockerfile. Confirmar si Dompdf requiere `dom` (viene incluido en la imagen oficial).
- Fuentes: incluir una fuente TTF para caracteres del español (acentos, ñ). Las fuentes base de Dompdf no siempre cubren todo.

## Estructura Sugerida del Módulo

```
src/Module/Receipt/
├── Controller/ReceiptController.php      (4 endpoints)
├── Service/ReceiptService.php            (arma el modelo del recibo)
├── Service/AmountInWordsConverter.php    (importe con letra, es-MX)
├── Renderer/ReceiptPdfRenderer.php       (HTML/CSS + Dompdf)
├── Renderer/templates/                   (plantillas de trámite y cobro)
└── DTO/ReceiptData.php
```

Arch decide el nombre final del módulo y si conviene separar el conversor de importes.

## Impacto en Sistema Existente

### Archivos nuevos
- Módulo `Receipt` completo
- Plantillas HTML/CSS del recibo
- Fuente TTF del diseño

### Archivos a modificar
- `src/Config/routes.php`: cuatro rutas nuevas
- `composer.json`: dependencias de PDF y QR
- `docs/openapi.yaml`: documentación de las cuatro rutas y de la respuesta `application/pdf`

### Sin cambios en
- Migraciones de base de datos
- Módulos `PaymentTransaction`, `Payment`, `Notary` (solo lectura)

## Consideraciones de Datos

- **Montos**: `double` en BD. Redondear a 2 decimales antes de formatear. Verificar que `total = fee + taxes` y que `taxes` = suma de detalles; si no coincide, el recibo muestra el valor guardado y se reporta como hallazgo (no se recalcula).
- **Fechas**: `payments_transactions.date` y `payments_payments.payment_date` son `datetime`. Se muestran como `dd/mm/yyyy`. El footer usa la fecha actual en `America/Mexico_City`.
- **Cliente o procedimiento borrados**: usar `LEFT JOIN`, como ya hace `PaymentTransactionRepository`. Mostrar "—" si el nombre no existe.
- **Collation `utf8mb3_bin`**: las búsquedas por uuid son sensibles a mayúsculas; mantener el valor tal cual.
- **Multi-tenant**: todas las consultas filtran por `id_notary`.

## Notas para Implementación

1. **Orden de operaciones**: validar id/uuid (400) → cargar registro filtrado por notaría (404) → cargar encabezado de notaría y detalle → armar modelo → renderizar PDF → responder.
2. **Importe con letra**: conversor propio para es-MX, con pruebas unitarias para 0, 1, 16, 100, 1000, 1 000 000 y centavos. Formato: `MIL DOSCIENTOS PESOS 00/100 M.N.`
3. **Footer**: usar el número de página que provee el renderizador en cada página (no un número fijo).
4. **QR**: contenido = uuid tal cual, sin URL. No hay endpoint de verificación pública.
5. **Marca de agua**: solo para trámites con `id_status` distinto de 1. Usar `CANCELADO` centrado y con opacidad baja.
6. **Logo**: si no existe el archivo, omitir sin lanzar excepción. Registrar el aviso en log.
7. **Sin escritura en disco**: generar en memoria y enviar en la respuesta.
8. **Pruebas**: unitarias para el conversor de importes y el armado del modelo; integración para las cuatro rutas (200, 404, 400, 401, multi-tenant).

## Estimación

- **Complejidad**: Media (dependencias nuevas y diseño visual; la lógica de negocio es simple)
- **Riesgo**: Medio. Riesgos principales: fuentes y caracteres especiales en Dompdf, y el orden de rutas.

| Tarea | Estimación |
|-------|------------|
| Dependencias y verificación de extensiones | 1 hora |
| Conversor de importes + pruebas | 1.5 horas |
| Servicio y modelo de datos | 2 horas |
| Plantillas de diseño (trámite y cobro) | 4 horas |
| Renderer PDF, footer, marca de agua, QR | 3 horas |
| Controller + rutas | 1 hora |
| Pruebas de integración | 2 horas |
| OpenAPI | 45 min |
| **Total** | **~15 horas** |
