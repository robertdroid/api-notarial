# Criterios de Aceptación

## Funcionales

### GET /deeds/{id}

#### Escenario 1: Obtener escritura existente
**Dado** una escritura con id 123 de mi notaría  
**Cuando** hago GET /deeds/123  
**Entonces** recibo 200 con el detalle completo:
- Datos de la escritura
- Acto como `{id, name}`
- Tipo de escritura como `{id, name}`
- Fraccionamiento como `{id, name}` (o null)
- Lista de participantes activos con `{id, name, type, typeId}`
- Lista de inscripciones con `{id, number, section, volume, date}`
- Usuario que modificó como `{id, name}`

#### Escenario 2: Escritura no existe
**Dado** que no existe escritura con id 999  
**Cuando** hago GET /deeds/999  
**Entonces** recibo 404

#### Escenario 3: Escritura de otra notaría
**Dado** una escritura con id 123 de otra notaría  
**Cuando** hago GET /deeds/123  
**Entonces** recibo 404 (no exponer que existe)

---

### POST /deeds

#### Escenario 4: Crear escritura completa
**Dado** datos válidos con participantes e inscripciones  
**Cuando** hago POST /deeds con:
```json
{
  "deedNumber": "1234",
  "book": "10",
  "deedDate": "2024-01-15",
  "actId": 5,
  "deedTypeId": 3,
  "subdivisionId": 2,
  "property": "Lote 5, Manzana 3",
  "deliveryDate": "2024-02-01",
  "deliveredBy": "Juan Pérez",
  "observations": "Sin observaciones",
  "participants": [
    {"name": "María García", "typeId": 1},
    {"name": "Pedro López", "typeId": 2}
  ],
  "registrations": [
    {"number": "12345", "section": "I", "volume": "100", "date": "2024-01-20"}
  ]
}
```
**Entonces** recibo 201 con la escritura creada (id, campos, relaciones)  
**Y** los participantes e inscripciones se crean asociados

#### Escenario 5: Crear escritura mínima
**Dado** solo campos obligatorios  
**Cuando** hago POST /deeds con:
```json
{
  "deedNumber": "1234",
  "book": "10",
  "deedDate": "2024-01-15",
  "actId": 5,
  "deedTypeId": 3
}
```
**Entonces** recibo 201 con la escritura creada

#### Escenario 6: Número de escritura duplicado
**Dado** que ya existe escritura "1234" en mi notaría  
**Cuando** hago POST /deeds con deedNumber "1234"  
**Entonces** recibo 409 con mensaje "Deed number already exists"

#### Escenario 7: Acto inactivo o de otra notaría
**Dado** un actId que no pertenece a mi notaría o está inactivo  
**Cuando** hago POST /deeds  
**Entonces** recibo 422 con mensaje "Act not found or inactive"

#### Escenario 8: Tipo de escritura inactivo
**Dado** un deedTypeId inactivo  
**Cuando** hago POST /deeds  
**Entonces** recibo 422 con mensaje "Deed type not found or inactive"

#### Escenario 9: Fraccionamiento inactivo
**Dado** un subdivisionId inactivo  
**Cuando** hago POST /deeds  
**Entonces** recibo 422 con mensaje "Subdivision not found or inactive"

#### Escenario 10: Campos obligatorios faltantes
**Dado** que falta deedNumber  
**Cuando** hago POST /deeds  
**Entonces** recibo 400 con validación de campos requeridos

---

### PUT /deeds/{id}

#### Escenario 11: Modificar escritura
**Dado** una escritura existente  
**Cuando** hago PUT /deeds/{id} con campos modificados  
**Entonces** recibo 200 con la escritura actualizada  
**Y** se actualiza `id_usuario` y `fecha_modificacion`

#### Escenario 12: PUT no modifica participantes ni inscripciones
**Dado** una escritura con 3 participantes  
**Cuando** hago PUT /deeds/{id} (sin campo participants)  
**Entonces** los participantes permanecen sin cambios

#### Escenario 13: PUT no modifica bandera terminada
**Dado** una escritura con terminada = 0  
**Cuando** hago PUT /deeds/{id} con `"isFinished": true`  
**Entonces** la bandera `terminada` sigue en 0 (campo ignorado)

#### Escenario 14: PUT en escritura terminada
**Dado** una escritura con terminada = 1  
**Cuando** hago PUT /deeds/{id} modificando datos  
**Entonces** recibo 200 y se actualizan los datos  
**Y** la bandera `terminada` sigue en 1

#### Escenario 15: Cambiar número a uno existente
**Dado** escritura "1234" y otra "5678"  
**Cuando** hago PUT /deeds/{id de 5678} con deedNumber "1234"  
**Entonces** recibo 409 "Deed number already exists"

---

### POST /deeds/{id}/finish

#### Escenario 16: Terminar escritura
**Dado** una escritura no terminada  
**Cuando** hago POST /deeds/{id}/finish  
**Entonces** recibo 200  
**Y** `terminada` cambia a 1  
**Y** se actualiza `id_usuario` y `fecha_modificacion`

#### Escenario 17: Terminar escritura ya terminada
**Dado** una escritura con terminada = 1  
**Cuando** hago POST /deeds/{id}/finish  
**Entonces** recibo 409 "Deed is already finished"

---

### GET /deeds/{id}/participants

#### Escenario 18: Listar participantes
**Dado** una escritura con participantes activos e inactivos  
**Cuando** hago GET /deeds/{id}/participants  
**Entonces** recibo 200 con solo los participantes activos (status = 1)

---

### POST /deeds/{id}/participants

#### Escenario 19: Agregar participante
**Dado** una escritura existente  
**Cuando** hago POST /deeds/{id}/participants con:
```json
{"name": "Carlos Ruiz", "typeId": 1}
```
**Entonces** recibo 201 con el participante creado

#### Escenario 20: Tipo de participante inválido
**Dado** typeId = 99 (no existe)  
**Cuando** hago POST /deeds/{id}/participants  
**Entonces** recibo 400 "Invalid participant type"

---

### PUT /deeds/{id}/participants/{participantId}

#### Escenario 21: Modificar participante
**Dado** un participante existente  
**Cuando** hago PUT con nuevo nombre o tipo  
**Entonces** recibo 200 con el participante actualizado

#### Escenario 22: Participante de otra escritura
**Dado** un participante que no pertenece a esta escritura  
**Cuando** hago PUT  
**Entonces** recibo 404

---

### DELETE /deeds/{id}/participants/{participantId}

#### Escenario 23: Eliminar participante (soft delete)
**Dado** un participante activo  
**Cuando** hago DELETE  
**Entonces** recibo 204  
**Y** el participante queda con `id_estatus = 2`

#### Escenario 24: Eliminar participante ya eliminado
**Dado** un participante con id_estatus = 2  
**Cuando** hago DELETE  
**Entonces** recibo 409 "Participant is already inactive"

---

### GET /deeds/{id}/registrations

#### Escenario 25: Listar inscripciones
**Dado** una escritura con inscripciones  
**Cuando** hago GET /deeds/{id}/registrations  
**Entonces** recibo 200 con todas las inscripciones

---

### POST /deeds/{id}/registrations

#### Escenario 26: Agregar inscripción
**Dado** una escritura existente  
**Cuando** hago POST /deeds/{id}/registrations con:
```json
{"number": "54321", "section": "II", "volume": "200", "date": "2024-03-15"}
```
**Entonces** recibo 201 con la inscripción creada

---

### PUT /deeds/{id}/registrations/{registrationId}

#### Escenario 27: Modificar inscripción
**Dado** una inscripción existente  
**Cuando** hago PUT con datos modificados  
**Entonces** recibo 200 con la inscripción actualizada

---

### DELETE /deeds/{id}/registrations/{registrationId}

#### Escenario 28: Eliminar inscripción
**Dado** una inscripción existente  
**Cuando** hago DELETE  
**Entonces** recibo 204  
**Y** la inscripción se elimina físicamente (no hay campo status)

---

## No Funcionales

- Todas las operaciones de escritura (POST, PUT, finish) deben ser atómicas usando transacciones
- Tiempo de respuesta < 500ms para operaciones individuales
- GET por id debe hacer JOINs eficientes, no N+1 queries

## Validaciones

| Campo | Regla |
|-------|-------|
| deedNumber | Requerido, max 50 chars, único por notaría |
| book | Requerido, max 100 chars |
| deedDate | Requerido, formato YYYY-MM-DD |
| actId | Requerido, debe existir y estar activo en la notaría |
| deedTypeId | Requerido, debe existir y estar activo en la notaría |
| subdivisionId | Opcional, si se envía debe existir y estar activo |
| property | Opcional, text |
| deliveryDate | Opcional, formato YYYY-MM-DD |
| deliveredBy | Opcional, max 250 chars |
| observations | Opcional, text |
| participants[].name | Requerido, max 250 chars |
| participants[].typeId | Requerido, debe ser 1 o 2 |
| registrations[].number | Opcional, max 100 chars |
| registrations[].section | Opcional, max 100 chars |
| registrations[].volume | Opcional, max 100 chars |
| registrations[].date | Opcional, formato YYYY-MM-DD |

## Casos Edge

- Crear escritura sin participantes ni inscripciones (válido)
- PUT que envía mismo deedNumber (no debe dar 409, es el mismo registro)
- Participante con nombre muy largo (truncar a 250)
- Fecha de inscripción futura (permitir, no validar lógica de negocio)
- GET de escritura con acto/tipo eliminado (mostrar con name null o "Eliminado")

## Criterios de Rechazo

- PUT que modifique la bandera `terminada`
- POST /finish que permita reabrir una escritura
- Operaciones que no actualicen campos de auditoría
- Queries N+1 en el GET por id
- Operaciones no atómicas (participantes creados sin escritura padre)
