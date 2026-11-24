# Plan de Pruebas: Cambio de Contraseña (spec 15)

## Alcance
- `PUT /api/v3/me/password` (usuario del token)
- `PUT /api/v3/users/{id}/password` (usuario de la ruta, misma notaría)
- Regresión: `PUT/PATCH /api/v3/users/{id}` con `password`, login v3, `GET /me`, `UserService::create()`

Fuera de alcance (según spec): contraseña actual, invalidación de JWT, bitácora, rate limiting, limpieza de `contrasenia`, login v1.

## Tipos de Pruebas
| Tipo | Detalle |
|------|---------|
| Unitarias | Suite completa `phpunit`; foco en `ChangePasswordRequestTest` y `UserServiceTest::changePassword` |
| API funcional | Escenarios 1-10 del PO, curl contra `localhost:8080` |
| Validaciones | Tipos, longitud (8 chars / 72 bytes), multibyte, NUL, espacios, body vacío / inválido / escalar |
| Ids de ruta | `abc`, `0`, `-1`, `1.5`, `1e2`, desbordado, `014`, `+14`, espacio |
| Multi-tenancy | Usuario de otra notaría (404, fila intacta en BD) |
| Efectos en BD | Antes/después de cada cambio: `contrasenia`, `ultimo_acceso`, `id_estatus`, `user_access`, `id_notaria` |
| Seguridad | Sin token / token inválido, contraseña ausente de respuestas, errores y logs |
| Revisión de código | Diff del commit `9a66561` contra los criterios técnicos |

## Datos de prueba
Usuarios temporales creados solo para la prueba (con `contrasenia` MD5 legacy y sin `password_hash`) y eliminados al terminar:
- `qa_a` (notaría 1, activo, actor), `qa_b` (notaría 1, activo, destino), `qa_inact` (notaría 1, `id_estatus = 2`), `qa_other` (notaría 2).
- No se modificaron usuarios reales; `AUTO_INCREMENT` restaurado a 13.

## Criterios de salida
- Suite unitaria en verde
- 10/10 escenarios del PO y todos los criterios técnicos en PASS
- Sin bugs abiertos atribuibles al feature
