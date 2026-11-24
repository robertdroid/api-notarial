# Contexto Técnico

## Entidades Involucradas

| Tabla | Motor actual | Acción |
|-------|--------------|--------|
| `escrituras` | MyISAM | Migrar a InnoDB |
| `participantes_escritura` | MyISAM | Migrar a InnoDB |
| `inscripciones_escritura` | MyISAM | Migrar a InnoDB |
| `actos_notaria` | — | Solo lectura (validación) |
| `tipo_escritura` | — | Solo lectura (validación) |
| `fraccionamientos` | — | Solo lectura (validación) |
| `usuarios` | — | Solo lectura (auditoría) |

### Esquema actual de `escrituras`
```sql
CREATE TABLE escrituras (
  id_escritura int(11) NOT NULL AUTO_INCREMENT,
  id_notaria int(11),
  num_escritura varchar(50) NOT NULL DEFAULT '0',
  libro varchar(100) NOT NULL DEFAULT '',
  fecha_escritura date,
  id_acto int(11),
  id_tipo_escitura int(11),  -- typo legacy, sin "r"
  id_fraccionamiento int(11),
  predio text,
  fecha_entrega date,
  entrego varchar(250),
  observaciones text,
  terminada int(11) DEFAULT 0,
  id_usuario int(11),
  fecha_modificacion datetime,
  PRIMARY KEY (id_escritura)
);
```

### Esquema actual de `participantes_escritura`
```sql
CREATE TABLE participantes_escritura (
  id_participante int(11) NOT NULL AUTO_INCREMENT,
  id_escritura int(11),
  nombre_participante varchar(250),
  id_tipo_participante int(11),  -- 1=Enajenante, 2=Adquiriente
  id_estatus int(11) DEFAULT 1,  -- 1=activo, 2=inactivo (cambiar de 0 a 2)
  PRIMARY KEY (id_participante)
);
```

### Esquema actual de `inscripciones_escritura`
```sql
CREATE TABLE inscripciones_escritura (
  id_inscripcion int(11) NOT NULL AUTO_INCREMENT,
  id_escritura int(11),
  numero_inscripcion varchar(100),
  seccion_inscripcion varchar(100),
  tomo_inscripcion varchar(100),
  fecha_inscripcion date,
  PRIMARY KEY (id_inscripcion)
);
```

## Endpoints

### Escrituras
| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/api/v3/deeds/{id}` | Detalle completo |
| POST | `/api/v3/deeds` | Crear con participantes/inscripciones |
| PUT | `/api/v3/deeds/{id}` | Modificar (sin participantes ni terminada) |
| POST | `/api/v3/deeds/{id}/finish` | Marcar como terminada |

### Participantes
| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/api/v3/deeds/{id}/participants` | Listar activos |
| POST | `/api/v3/deeds/{id}/participants` | Crear |
| PUT | `/api/v3/deeds/{id}/participants/{pid}` | Modificar |
| DELETE | `/api/v3/deeds/{id}/participants/{pid}` | Soft delete (status=2) |

### Inscripciones
| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/api/v3/deeds/{id}/registrations` | Listar |
| POST | `/api/v3/deeds/{id}/registrations` | Crear |
| PUT | `/api/v3/deeds/{id}/registrations/{rid}` | Modificar |
| DELETE | `/api/v3/deeds/{id}/registrations/{rid}` | Eliminar físico |

## Mapeo de Campos (BD → API)

### Escritura
| BD | API | Tipo |
|----|-----|------|
| `id_escritura` | `id` | int |
| `num_escritura` | `deedNumber` | string |
| `libro` | `book` | string |
| `fecha_escritura` | `deedDate` | string (YYYY-MM-DD) |
| `id_acto` | `actId` | int |
| `id_tipo_escitura` | `deedTypeId` | int |
| `id_fraccionamiento` | `subdivisionId` | int? |
| `predio` | `property` | string? |
| `fecha_entrega` | `deliveryDate` | string? |
| `entrego` | `deliveredBy` | string? |
| `observaciones` | `observations` | string? |
| `terminada` | `isFinished` | boolean |
| `id_usuario` | `modifiedBy` | int |
| `fecha_modificacion` | `modifiedAt` | string (ISO 8601) |

### Participante
| BD | API | Tipo |
|----|-----|------|
| `id_participante` | `id` | int |
| `nombre_participante` | `name` | string |
| `id_tipo_participante` | `typeId` | int |
| — | `type` | string ("Enajenante"/"Adquiriente") |

### Inscripción
| BD | API | Tipo |
|----|-----|------|
| `id_inscripcion` | `id` | int |
| `numero_inscripcion` | `number` | string? |
| `seccion_inscripcion` | `section` | string? |
| `tomo_inscripcion` | `volume` | string? |
| `fecha_inscripcion` | `date` | string? |

## Dependencias

- **AuthMiddleware**: JWT con `notary_id` y `user_id`
- **ActRepository**: Validar que acto existe y está activo
- **DeedTypeRepository**: Validar que tipo existe y está activo
- **SubdivisionRepository**: Validar que fraccionamiento existe y está activo
- **UserRepository**: Obtener nombre del usuario para respuesta

## Migraciones Requeridas

### 1. Migrar tablas a InnoDB
```sql
-- Permite transacciones atómicas
ALTER TABLE escrituras ENGINE = InnoDB;
ALTER TABLE participantes_escritura ENGINE = InnoDB;
ALTER TABLE inscripciones_escritura ENGINE = InnoDB;
```

### 2. Índice único para num_escritura
```sql
ALTER TABLE escrituras 
  ADD UNIQUE INDEX idx_escrituras_notaria_num (id_notaria, num_escritura);
```

### 3. Índices para inscripciones
```sql
ALTER TABLE inscripciones_escritura 
  ADD INDEX idx_inscripciones_escritura (id_escritura);
```

### 4. Normalizar id_estatus de participantes
```sql
-- Cambiar 0 (eliminado legacy) a 2 (inactivo)
UPDATE participantes_escritura SET id_estatus = 2 WHERE id_estatus = 0;
```

## Estructura de Respuesta GET /deeds/{id}

```json
{
  "id": 123,
  "deedNumber": "1234",
  "book": "10",
  "deedDate": "2024-01-15",
  "act": {"id": 5, "name": "Compraventa"},
  "deedType": {"id": 3, "name": "Definitiva"},
  "subdivision": {"id": 2, "name": "Los Pinos"},
  "property": "Lote 5, Manzana 3",
  "deliveryDate": "2024-02-01",
  "deliveredBy": "Juan Pérez",
  "observations": "Sin observaciones",
  "isFinished": false,
  "participants": [
    {"id": 1, "name": "María García", "typeId": 1, "type": "Enajenante"},
    {"id": 2, "name": "Pedro López", "typeId": 2, "type": "Adquiriente"}
  ],
  "registrations": [
    {"id": 1, "number": "12345", "section": "I", "volume": "100", "date": "2024-01-20"}
  ],
  "modifiedBy": {"id": 7, "name": "Ana Torres"},
  "modifiedAt": "2024-01-15T10:30:00-06:00"
}
```

## Consideraciones de Implementación

### Transacciones
```php
try {
    $this->db->beginTransaction();
    
    // 1. Insertar escritura
    // 2. Insertar participantes
    // 3. Insertar inscripciones
    
    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}
```

### Validaciones de FK
Antes de insertar, verificar:
1. `actId` existe en `actos_notaria` con `id_notaria = token.notaryId` y `estatus = 1`
2. `deedTypeId` existe en `tipo_escritura` con `id_notaria = token.notaryId` y `estatus = 1`
3. `subdivisionId` (si se envía) existe en `fraccionamientos` con `id_notaria = token.notaryId` y `estatus = 1`

### Multi-tenancy
Todas las queries deben filtrar por `id_notaria` del token JWT. Escrituras de otras notarías devuelven 404.

### Auditoría
En cada INSERT/UPDATE de escritura:
```sql
id_usuario = :userId,  -- del token JWT
fecha_modificacion = NOW()
```

## Notas para Arquitecto

1. **Ya existe** `DeedRepository` con el listado; extender con los nuevos métodos
2. **Ya existe** `DeedService` y `DeedController`; agregar acciones
3. **Crear** `ParticipantRepository`, `ParticipantService`, `ParticipantController`
4. **Crear** `RegistrationRepository`, `RegistrationService`, `RegistrationController`
5. **Considerar** DTOs separados para request y response de cada entidad
6. El typo `id_tipo_escitura` (sin "r") es legacy y debe mantenerse en queries
