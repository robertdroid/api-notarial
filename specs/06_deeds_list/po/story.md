# Listado de Escrituras con Búsqueda

## Historia de Usuario

**Como** usuario de una notaría  
**Quiero** ver un listado paginado de todas las escrituras de mi notaría y poder buscar por participantes, actos o número de escritura  
**Para** localizar rápidamente la información de cualquier escritura y dar seguimiento a su estado

## Contexto

El sistema Notarial maneja potencialmente miles de escrituras por notaría. Los usuarios necesitan:
1. Ver un listado general de escrituras ordenado por número (las más recientes primero)
2. Buscar escrituras por diferentes criterios: nombre de participantes (adquirientes o enajenantes), tipo de acto, o número de escritura
3. Identificar rápidamente el estado de cada escritura (terminada o en proceso)

La búsqueda debe ser flexible: si el usuario escribe "perez juan", debe encontrar "Juan Pérez" (palabras en cualquier orden).

## Alcance

### Incluido
- Endpoint GET para listar escrituras paginadas
- Búsqueda por texto en: número de escritura, nombres de participantes (enajenantes y adquirientes), nombre del acto
- Paginación con 100 registros por página (configurable vía constante)
- Ordenamiento descendente por número de escritura
- Exclusión automática de escrituras con soft delete
- Respuesta con datos básicos: id, número, libro, fecha, acto, tipo de escritura, bandera de terminada

### Excluido
- Filtros avanzados (por fecha, por tipo, por fraccionamiento, etc.)
- Exportación a Excel/PDF
- Ordenamiento configurable por el usuario
- Detalle completo de la escritura (será otro endpoint)
- CRUD de escrituras (crear, editar, eliminar)

## Usuarios Afectados
- Todos los usuarios autenticados de la notaría (sin restricción de rol)
