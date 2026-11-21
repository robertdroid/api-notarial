# BUG_002: Con volumen alto el agotamiento de memoria responde 200 con el error de PHP en HTML

## Severidad
**Media** (riesgo conocido, requiere decisión del PO/arquitecto: ADR-005 sigue abierta). Hoy no afecta a clientes reales (el máximo en la BD de desarrollo es 6 trámites por cliente), pero el endpoint no tiene límite de filas y el fallo es silencioso para el cliente HTTP.

## Estado
Closed como problema de ambiente (decisión del usuario, 2026-11-21): se vuelve a probar más adelante en otras condiciones

## Criterios incumplidos
- PO, Escenario 23: "Falla al generar el PDF ... responde **500** JSON `{ "error": "Could not generate statement" }`".
- Arch, Controller: "Cualquier `Throwable` ... 500 JSON". Un agotamiento de memoria es un `Fatal error`, no un `Throwable`, y no lo cubre el `try/catch`.
- Seguridad: el cuerpo de la respuesta expone la ruta interna del servidor (`/var/www/html/vendor/dompdf/...`).

Lo que **sí** se cumple: nunca sale un PDF parcial ni encabezados de PDF (el binario se arma completo en memoria antes de enviar).

## Pasos para reproducir
1. Cliente con 700 trámites activos en una notaría (con `memory_limit` 128M, el valor por defecto del contenedor).
2. `GET /api/v3/payment-clients/{id}/statement/pdf` con un token de esa notaría.

Medición de QA en el contenedor `api` (Docker en Windows, `memory_limit=128M`):

| Filas del libro | Resultado | Tiempo |
|-----------------|-----------|--------|
| 150 | 200, PDF de 6 páginas | 6.3 s |
| 450 | 200, PDF (con aviso `[statement] ... 450 ledger rows (> 400)` en el log) | 7.5 s |
| 700 | **200** con cuerpo HTML de error de PHP | 4.0 s |

## Resultado esperado
Un 500 JSON `{ "error": "Could not generate statement" }` (escenario 23), o un rechazo claro y controlado (por ejemplo 422) si el PO decide poner un límite.

## Resultado actual
```
HTTP/1.1 200 OK
Content-Type: application/json; charset=utf-8
Content-Length: 200

<br />
<b>Fatal error</b>:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 2097152 bytes) in <b>/var/www/html/vendor/dompdf/dompdf/src/Css/Style.php</b> on line <b>2286</b><br />
```
El código de estado es 200 porque el contenedor no tiene `php.ini` (`display_errors=1`, `log_errors=0`): PHP imprime el fatal en el cuerpo y no cambia el código. Un cliente que solo mira el status lo tomaría por éxito, y el `Content-Type` dice JSON pero el cuerpo no lo es. Con `display_errors=0` (típico de producción) sería un 500 vacío; no se pudo comprobar el entorno real.

Nota de documentación: `developer_notes.md` describe el caso como "un 500 vacío de Apache/PHP". En este contenedor no es un 500 vacío, es lo anterior. README y OpenAPI dicen "a PHP fatal error, not a JSON 500", lo cual es correcto, pero no mencionan el 200 ni la fuga de la ruta.

## Opciones (decisión del PO/arquitecto, ya planteadas en ADR-005)
1. Subir `memory_limit` para esta ruta (p. ej. `ini_set('memory_limit', '512M')` antes de renderizar), asumiendo tiempos de decenas de segundos a partir de ~1,000 filas.
2. Límite de filas con un 422 claro, comprobado **antes** de renderizar (el recuento ya se conoce en `StatementPdfRenderer`).
3. Paginar por periodo.

Cualquiera de las tres cierra el bug; con la 2 el escenario 23 también queda cubierto para este caso. Independientemente de la opción: asegurar `display_errors=0` y `log_errors=1` en la imagen para que ningún fatal exponga rutas ni responda 200.

## Evidencia
- Cliente 9214 (700 trámites) y 9213 (450) de la notaría de prueba 2, fixtures de QA.
- Log del contenedor: `[statement] client 9214: 700 ledger rows (> 400), the render may exceed memory_limit (128M)`.

## Revisión de desarrollo (2026-11-20): en gran parte es del ambiente
Sin cambios de código. Se separó qué parte del reporte es del contenedor y cuál es del producto.

**Causa del 200 y de la fuga de la ruta: el ambiente.** `docker/Dockerfile` parte de `php:8.3-apache` y no copia ningún `php.ini` (dentro del contenedor: `Loaded Configuration File: (none)`). Rigen los valores por defecto de PHP: `memory_limit=128M`, `display_errors=1`, `log_errors=0`. Con `display_errors=1` PHP imprime el fatal en el cuerpo y deja el código en 200; además, como `log_errors=0`, el fatal no queda en ningún log. `APP_DEBUG` no interviene (el manejador de `index.php` solo ve excepciones, no fatales).

**Prueba.** Mismo código, cliente de 700 trámites (notaría de prueba), servidor PHP aparte dentro del contenedor, cuatro configuraciones:

| Configuración | `memory_limit` | Resultado |
|---------------|----------------|-----------|
| Contenedor actual (sin `php.ini`) | 128M | **200** + HTML "Fatal error" con la ruta `/var/www/html/vendor/...` |
| `display_errors=0`, `log_errors=1` | 128M | **500**, cuerpo vacío, sin ruta; el fatal queda en el log |
| `php.ini-production` (el que trae la imagen) | 128M | **500**, cuerpo vacío, sin ruta; el fatal queda en el log |
| `display_errors=0`, `log_errors=1` | **256M** | **200**, PDF válido de 94 KB |

**Qué queda del reporte original**
- El 200 con HTML y la fuga de la ruta son un problema de configuración del ambiente, no de la feature: le pasaría a cualquier fatal de cualquier endpoint. Se corrige en la imagen (copiar `php.ini-production` o fijar `display_errors=0` y `log_errors=1`), y conviene confirmar qué usa producción. Esto afecta a todo el API, no solo a esta feature, y está fuera del alcance de la spec 21.
- Lo que sí es de la feature: con ~600 filas o más y 128M el resultado sigue siendo un **500 de cuerpo vacío**, no el JSON `Could not generate statement` del escenario 23, porque un agotamiento de memoria no es un `Throwable`. El conteo y el aviso en log (> 400 filas) ya existen. Cerrarlo requiere la decisión de producto de siempre (ADR-005): subir `memory_limit` (con 256M ya alcanzan 700 filas), un límite con 422 antes de renderizar, o paginar por periodo.
- La documentación (`developer_notes.md`, README, OpenAPI) dice "fatal error, no JSON 500" y `developer_notes.md` habla de un "500 vacío": eso es correcto **en una configuración de producción**, no en el contenedor de desarrollo actual. Cuando se decida el límite, ajustar esas notas.

**Recomendación:** bajar la severidad a **Baja** para esta spec (riesgo conocido y documentado, sin clientes reales cerca del umbral: máximo 6 trámites), mantener el bug abierto solo por la decisión del PO sobre el límite, y abrir aparte el ajuste del `php.ini` de la imagen. No bloquea el cierre de la spec 21 si el PO acepta el riesgo.

## Cierre de QA (2026-11-21): tratado como problema de ambiente
Por decisión del usuario, este bug se toma como un problema de ambiente (contenedor sin `php.ini`, ver la revisión de desarrollo de arriba) y **no bloquea** la spec 21. Queda **sin re-probar**: se volverá a probar más adelante en otras condiciones (imagen con `php.ini` de producción, `memory_limit` real del servidor).

Pendientes que quedan fuera de esta spec:
- Ticket aparte para fijar `display_errors=0` y `log_errors=1` en la imagen (afecta a todo el API).
- Confirmar el `memory_limit` y el `php.ini` de producción.
- Decisión del PO sobre ADR-005 (límite de filas, `memory_limit` o paginación) para el escenario 23 con ≥ ~600 filas.

Riesgo aceptado: con ~600 filas o más y 128M el endpoint no responde el JSON `Could not generate statement`. Hoy el máximo en datos reales es 6 trámites por cliente.
