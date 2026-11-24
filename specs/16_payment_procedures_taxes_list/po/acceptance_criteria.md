# Criterios de Aceptación

## Funcionales

### Escenario 1: Procedimientos con impuestos
**Dado** que la notaría tiene el procedimiento 5 "Compraventa" activo con los impuestos ISR (default 500) e ISAI (default 1200) activos
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** recibe 200 y el procedimiento 5 incluye `taxes` con ambos impuestos, cada uno con `taxId`, `taxName` y `defaultValue`

### Escenario 2: Procedimiento sin impuestos
**Dado** que existe un procedimiento activo sin registros en `payment_procedure_taxes`
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** el procedimiento aparece con `taxes: []`

### Escenario 3: Impuesto con relación inactiva
**Dado** un procedimiento con un impuesto cuya relación tiene `id_status = 2`
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** ese impuesto no aparece en `taxes` del procedimiento

### Escenario 4: Impuesto inactivo en catálogo
**Dado** un procedimiento con relación activa a un impuesto cuyo `payment_taxes.id_status = 2`
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** ese impuesto no aparece en `taxes`

### Escenario 5: Procedimiento inactivo
**Dado** un procedimiento con `id_status = 2`
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** el procedimiento no aparece en la respuesta

### Escenario 6: Aislamiento multi-tenant
**Dado** que existen procedimientos de otra notaría
**Cuando** un usuario de la notaría A hace `GET /api/v3/payment-procedures/taxes`
**Entonces** solo recibe procedimientos e impuestos de la notaría A

### Escenario 7: Notaría sin procedimientos
**Dado** una notaría sin procedimientos activos
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** recibe 200 con `{ "data": [] }`

### Escenario 8: Sin autenticación
**Dado** una petición sin token o con token inválido
**Cuando** hace `GET /api/v3/payment-procedures/taxes`
**Entonces** recibe 401

## Respuesta esperada (200)
```json
{
  "data": [
    {
      "procedureId": 5,
      "procedureName": "Compraventa",
      "taxes": [
        { "taxId": 12, "taxName": "ISR", "defaultValue": 500.00 },
        { "taxId": 15, "taxName": "ISAI", "defaultValue": 1200.00 }
      ]
    },
    { "procedureId": 6, "procedureName": "Donación", "taxes": [] }
  ]
}
```

## No Funcionales
- Una sola consulta agregada (sin N+1 por procedimiento)
- Campos en `camelCase`, salida en inglés
- Respuesta mediante DTOs, sin exponer Entities
- Orden determinista: procedimientos por nombre ascendente, impuestos por nombre ascendente

## Validaciones
- Token JWT válido con `notaryId`
- Filtro por `notary_id` obligatorio

## Casos Edge
- Procedimiento activo cuyo único impuesto está inactivo: aparece con `taxes: []`
- `defaultValue` en 0: se devuelve `0.00`, no se omite
- La ruta `/payment-procedures/taxes` no debe interpretarse como `/{id}` con id `taxes`

## Criterios de Rechazo
- Filtra datos de otra notaría
- Incluye procedimientos o impuestos inactivos
- Omite procedimientos activos sin impuestos
- Rompe el comportamiento de `GET /payment-procedures/{id}`
