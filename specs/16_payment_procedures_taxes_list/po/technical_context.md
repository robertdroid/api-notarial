# Contexto Técnico

## Entidades Involucradas
- `payment_procedures` (`id_procedure`, `procedure_name`, `id_notary`, `id_status`)
- `payment_procedure_taxes` (`id_procedure`, `id_tax`, `default_value`, `id_status`)
- `payment_taxes` (`id_tax`, `tax_name`, `id_notary`, `id_status`)
- Vista existente `view_payment_procedure_taxes` (LEFT JOIN de las tres); puede reutilizarse, pero no filtra por status de la relación ni del catálogo, así que esos filtros deben aplicarse en el JOIN

## Endpoints Sugeridos
- GET /api/v3/payment-procedures/taxes (auth)

## Dependencias
- Spec 07 (payment_taxes), spec 08 (payment_procedures), spec 10 (payment_procedure_taxes)
- Spec 01: JWT con `notaryId`

## Impacto en Sistema Existente
- Nueva ruta en `src/Config/routes.php`; debe registrarse **antes** de `GET /api/v3/payment-procedures/{id}`, o `taxes` se interpretaría como `{id}`
- Método nuevo en `PaymentProcedureTaxController` / `PaymentProcedureTaxService` / `PaymentProcedureTaxRepository`, con DTO de respuesta nuevo (procedimiento con arreglo de impuestos)
- No se modifican endpoints ni DTOs existentes

## Consideraciones de Datos
- Sin migraciones ni cambios de esquema
- Tablas MyISAM sin FKs: la consulta debe filtrar por `payment_procedures.id_notary`
- Para conservar procedimientos sin impuestos, los filtros de status de relación y de catálogo van en la cláusula `ON` del LEFT JOIN, no en el `WHERE`
- Un procedimiento activo cuyos impuestos están todos inactivos debe salir con `taxes: []`

## Notas para Implementación
1. Una sola query con LEFT JOIN y agrupación en PHP por `procedureId`
2. Orden: `procedure_name ASC`, luego `tax_name ASC`
3. `defaultValue` como float con 2 decimales
4. Envolver en `{ "data": [...] }`, igual que otros listados
5. Agregar el endpoint a la documentación OpenAPI
