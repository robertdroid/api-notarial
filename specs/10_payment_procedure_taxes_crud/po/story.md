# Configuración de Impuestos por Procedimiento (Payment Procedure Taxes)

## Historia de Usuario

**Como** usuario de la notaría  
**Quiero** configurar qué impuestos aplican a cada procedimiento de pago  
**Para** poder asignar automáticamente los impuestos correctos con sus valores predeterminados al crear transacciones

## Contexto

El módulo de pagos permite gestionar procedimientos (trámites) e impuestos de forma independiente. Sin embargo, cada procedimiento puede requerir diferentes impuestos con valores predeterminados específicos.

Por ejemplo:
- El procedimiento "Escritura de Compraventa" podría tener configurados los impuestos: ISR (valor default $500), ISAI (valor default $1,200), Derechos de Registro ($800).
- El procedimiento "Cancelación de Hipoteca" podría tener solo: Derechos de Registro ($400).

Esta configuración sirve como plantilla al crear transacciones, donde los valores default pueden ser ajustados según el caso específico.

## Alcance

### Incluido
- Listar los impuestos configurados para un procedimiento específico
- Agregar uno o varios impuestos a un procedimiento (bulk en creación inicial)
- Modificar el valor default de un impuesto en un procedimiento
- Deshabilitar (soft delete) un impuesto de un procedimiento
- Rehabilitar un impuesto previamente deshabilitado (re-enviando con POST)
- Validación de multi-tenencia (procedimiento e impuesto deben ser de la misma notaría)

### Excluido
- Modificación de los impuestos base (eso es del CRUD de Payment Taxes - spec 07)
- Modificación de los procedimientos (eso es del CRUD de Payment Procedures - spec 08)
- Consulta inversa ("¿en qué procedimientos está este impuesto?")
- Eliminación física de registros

## Usuarios Afectados
- Todos los usuarios autenticados de la notaría (sin restricción de rol)

## Valor de Negocio
- Permite pre-configurar las plantillas de impuestos por tipo de trámite
- Reduce errores al crear transacciones (los impuestos correctos ya están asignados)
- Flexibilidad para ajustar valores default según el procedimiento específico
