# Criterios de Aceptación

## Funcionales

### Escenario 1: Recibo sin etiquetas en el encabezado
**Dado** una notaría con nombre, notario titular y RFC capturados
**Cuando** el empleado genera el PDF de un recibo (trámite o cobro)
**Entonces** el encabezado muestra, en este orden y sin etiquetas: nombre de la notaría, nombre del notario titular, RFC, dirección y `Tel. [teléfono]`
**Y** el PDF no contiene el texto `Notario titular:` ni `RFC:`

### Escenario 2: Estado de cuenta sin etiquetas en el encabezado
**Dado** una notaría con nombre, notario titular y RFC capturados
**Cuando** el empleado genera el PDF del estado de cuenta de un cliente
**Entonces** el encabezado muestra el mismo contenido y orden que el del recibo, sin las etiquetas `Notario titular:` ni `RFC:`

### Escenario 3: Notaría sin RFC
**Dado** una notaría cuyo RFC es nulo o vacío
**Cuando** se genera cualquiera de los 2 PDFs
**Entonces** la línea del RFC muestra `---` (tres guiones)

### Escenario 4: Notaría con RFC
**Dado** una notaría con RFC `ABC123456XYZ`
**Cuando** se genera cualquiera de los 2 PDFs
**Entonces** la línea del RFC muestra `ABC123456XYZ` sin prefijo y sin guiones

### Escenario 5: Notario titular sin nombre
**Dado** una notaría sin nombre de notario titular
**Cuando** se genera cualquiera de los 2 PDFs
**Entonces** la línea del notario titular muestra `—` (como hoy)

### Escenario 6: Formato conservado
**Dado** cualquiera de los 2 PDFs generados
**Cuando** se compara el encabezado con el de la versión anterior
**Entonces** el nombre de la notaría conserva fuente, tamaño, negrita y color
**Y** el RFC, la dirección y el teléfono conservan el estilo atenuado
**Y** el notario titular conserva el estilo normal
**Y** el logo, si existe, sigue en su posición

## No Funcionales
- Sin cambios en endpoints, contratos JSON, headers HTTP ni nombres de archivo
- Sin cambios de esquema ni migraciones
- Sin impacto perceptible en el tiempo de generación
- Codificación: acentos y caracteres especiales del nombre se ven bien (sin `?` ni cuadros), como hoy

## Validaciones
- El RFC se escapa para HTML igual que hoy
- RFC con solo espacios se trata como vacío y muestra `---`

## Casos Edge
- RFC nulo, cadena vacía o solo espacios: muestra `---`
- Notaría sin logo: el encabezado se ve igual, sin columna de logo
- Nombre de notaría o notario muy largo: se ajusta como hoy, sin cambios de maquetación
- Recibo cancelado (marca de agua `CANCELADO`): el encabezado cambia igual que en uno vigente
- Estado de cuenta sin movimientos: el encabezado cambia igual

## Criterios de Rechazo
- El PDF aún contiene `Notario titular:` o `RFC:` en alguno de los 2 documentos
- El RFC vacío se muestra como `—` o en blanco en lugar de `---`
- Cambia el `—` en campos fuera de la línea del RFC (cliente, teléfono, correo, dirección, notario titular, etc.)
- Cambia la fuente, tamaño, color u orden de las líneas del encabezado
- Los 2 PDFs quedan con encabezados distintos entre sí
