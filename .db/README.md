# Database Migrations

Sistema de migraciones manual para el proyecto Notarial API.

## Estructura

```
.db/
├── pending/           # Migraciones nuevas (pendientes de aplicar)
├── applied/           # Migraciones aplicadas en local
├── executed/          # Migraciones ejecutadas en producción
│   ├── v1.0.0/
│   ├── v1.1.0/
│   └── ...
└── README.md
```

## Ciclo de Vida

1. **Crear migración** → `.db/pending/`
2. **Aplicar en local** → mover a `.db/applied/`
3. **Deploy a producción** → mover a `.db/executed/vX.X.X/`

## Formato de Nombre

```
YYYYMMDD_HHMMSS_descripcion.sql
```

Ejemplo: `20261106_143022_add_column_status_to_escrituras.sql`

El timestamp garantiza el orden de ejecución.

## Estructura de Migración

```sql
-- Migration: nombre_descriptivo
-- Description: Descripción detallada del cambio
-- Author: nombre del developer
-- Date: YYYY-MM-DD

-- UP
[Comandos SQL para aplicar el cambio]

-- DOWN (rollback)
-- [Comandos SQL para revertir el cambio - comentados]
```

## Proceso

### Para Developers

1. Crear archivo en `.db/pending/` con el formato correcto
2. Aplicar en tu entorno local
3. Mover a `.db/applied/`
4. Commit y push con el resto de los cambios

### Para Deploy

1. Antes de deploy, ejecutar migraciones de `.db/applied/` en producción
2. Mover a `.db/executed/vX.X.X/` donde X.X.X es la versión del release

## Notas

- Las migraciones deben ser idempotentes cuando sea posible
- Incluir siempre el DOWN (rollback) aunque esté comentado
- Probar la migración en local antes de hacer commit
- No modificar migraciones que ya estén en `applied/` o `executed/`
