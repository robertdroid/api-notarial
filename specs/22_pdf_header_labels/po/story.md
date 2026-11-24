# Encabezado de PDFs de Pagos sin Etiquetas (Payments)

## Historia de Usuario

**Como** empleado de una notaría
**Quiero** que el encabezado de los PDFs de pagos muestre el nombre de la notaría, el notario titular y el RFC sin etiquetas de texto
**Para** entregar documentos más limpios y con el aspecto de un membrete formal

## Contexto

Los PDFs de pagos (recibo de la spec 18 y estado de cuenta de la spec 21) comparten el mismo encabezado de notaría. Hoy se ve así:

```
[Nombre notaría]
Notario titular: [Nombre notario]
RFC: [RFC]
[Dirección]
Tel. [teléfono]
```

Las etiquetas `Notario titular:` y `RFC:` restan limpieza al membrete. El cambio es **solo de presentación**: no modifica datos, endpoints ni contratos.

### Decisiones de negocio

| Tema | Decisión |
|------|----------|
| Etiquetas | Se eliminan `Notario titular:` y `RFC:` |
| Formato | Sin cambios: misma fuente, tamaño, negrita y color. La notaría conserva el estilo destacado y el RFC, la dirección y el teléfono conservan el estilo atenuado (`muted`) |
| RFC vacío | Se muestra `---` (tres guiones) |
| Notario titular vacío | Sin cambios: se muestra `—` como hoy |
| Dirección y teléfono | Sin cambios, incluida la etiqueta `Tel.` y el `—` cuando faltan |
| Resto del PDF | Sin cambios. El `—` de cliente, teléfono, correo y demás campos se conserva |
| PDFs afectados | Los 2 que tienen este encabezado: recibo (trámite y cobro) y estado de cuenta |

### Resultado esperado

```
[Nombre notaría]            ← destacado, igual que hoy
[Nombre notario titular]
[RFC]   o   ---             ← atenuado, igual que hoy
[Dirección]
Tel. [teléfono]
```

## Alcance

### Incluido
- Quitar las etiquetas `Notario titular:` y `RFC:` del encabezado del recibo
- Quitar las mismas etiquetas del encabezado del estado de cuenta
- Mostrar `---` cuando la notaría no tenga RFC
- Mantener sin cambios fuente, tamaño, color y posición de cada línea

### Excluido
- Cambios en el resto de los campos del encabezado (notaría, notario, dirección, teléfono)
- Cambiar el marcador `—` del resto del PDF
- Cambios en logo, folio, QR, footer o cuerpo del documento
- Cambios en la API, base de datos, migraciones o `docs/openapi.yaml`
- Plantillas configurables por notaría
- Otros PDFs (no hay más con este encabezado)

## Usuarios Afectados

- **Empleados de notaría**: generan e imprimen recibos y estados de cuenta.
- **Clientes finales**: reciben el documento con el nuevo encabezado.

## Valor de Negocio

- **Membrete más limpio**: el encabezado se ve como el de un documento notarial formal.
- **Consistencia**: ambos PDFs muestran el mismo encabezado.
- **Sin riesgo**: cambio de presentación, sin impacto en datos.

## Preguntas abiertas para Arch

- Cómo mostrar `---` solo en el RFC sin cambiar el `—` de `H::orDash` que usa el resto del PDF (por ejemplo, un parámetro opcional o un helper específico).
- Si conviene extraer el encabezado compartido de `receipt.php` y `statement.php` a un parcial común, o mantener el cambio mínimo en las dos plantillas.
