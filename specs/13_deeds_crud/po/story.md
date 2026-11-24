# CRUD Completo de Escrituras

## Historia de Usuario

**Como** empleado de una notaría  
**Quiero** crear, consultar, modificar y marcar como terminadas las escrituras  
**Para** llevar el control completo del índice notarial sin depender de sistemas legacy

## Contexto

Actualmente solo existe `GET /deeds` (listado). Este feature completa el módulo de escrituras con:

1. **GET /deeds/{id}** — Detalle completo de una escritura con sus relaciones
2. **POST /deeds** — Crear escritura con participantes e inscripciones en una sola llamada
3. **PUT /deeds/{id}** — Modificar escritura (sin tocar participantes ni bandera de terminada)
4. **POST /deeds/{id}/finish** — Marcar escritura como terminada (irreversible)
5. **Endpoints de participantes** — CRUD independiente para agregar/modificar/eliminar participantes
6. **Endpoints de inscripciones** — CRUD independiente para agregar/modificar/eliminar inscripciones

### Problema del legacy

En el sistema legacy, al modificar una escritura terminada se perdía la bandera `terminada` y volvía a quedar como no terminada. Se corrige separando:
- **PUT /deeds/{id}** — Modifica datos, pero **nunca** toca `terminada`
- **POST /deeds/{id}/finish** — Solo cambia `terminada` a 1 (una sola vez, irreversible)

## Alcance

### Incluido
- GET por id con detalle completo (acto, tipo, fraccionamiento, participantes, inscripciones, usuario)
- POST para crear escritura con participantes e inscripciones opcionales
- PUT para modificar escritura (sin participantes ni bandera terminada)
- POST /finish para marcar como terminada
- CRUD de participantes como subrecurso (`/deeds/{id}/participants`)
- CRUD de inscripciones como subrecurso (`/deeds/{id}/registrations`)
- Validación de unicidad de `num_escritura` por notaría
- Validación de que acto, tipo y fraccionamiento pertenezcan a la notaría y estén activos
- Migración de tablas a InnoDB para transacciones
- Campos de auditoría (`id_usuario`, `fecha_modificacion`) actualizados en cada operación

### Excluido
- DELETE de escrituras (no hay columna de status en la tabla)
- Endpoint para reabrir una escritura terminada
- Búsqueda/filtrado (ya existe en GET /deeds)
- Reportes o exports

## Usuarios Afectados

- **Empleados de notaría**: Podrán gestionar escrituras completas desde la nueva API
- **Sistemas frontend**: Consumirán estos endpoints para el nuevo UI
