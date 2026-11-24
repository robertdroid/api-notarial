# Notas para el Desarrollador

## Contexto rápido

Los PDFs de recibo (spec 18) y estado de cuenta (spec 21) comparten el mismo bloque de encabezado de notaría, copiado en dos plantillas. Hay que quitar las etiquetas `Notario titular:` y `RFC:` y mostrar `---` cuando el RFC esté vacío. Es solo presentación: sin cambios de API, datos, CSS ni `openapi.yaml`.

Son 3 archivos de producción: el helper y las dos plantillas.

## Archivos clave

| Archivo | Qué hacer |
|---------|-----------|
| `src/Module/Receipt/Renderer/ReceiptHtmlBuilder.php` (línea 54) | Parámetro opcional `$placeholder` en `orDash` |
| `src/Module/Receipt/Renderer/templates/receipt.php` (líneas 76-77) | Quitar etiquetas, RFC con `'---'` |
| `src/Module/PaymentStatement/Renderer/templates/statement.php` (líneas 80-81) | Mismo cambio |
| `tests/Module/Receipt/Renderer/ReceiptHtmlBuilderTest.php` | Casos nuevos, helper `notary()` con parámetros |
| `tests/Module/PaymentStatement/Renderer/StatementHtmlBuilderTest.php` | Casos nuevos, helper `data()` con parámetros |
| `tests/Module/Receipt/Renderer/NotaryHeaderParityTest.php` | Nuevo: paridad de encabezados |

## Código de referencia

### Helper (ADR-001)

```php
/** Escaped value, or the placeholder (an em dash by default) when it is empty. */
public static function orDash(?string $value, string $placeholder = '—'): string
{
    return $value === null || trim($value) === '' ? $placeholder : self::escape($value);
}
```

### Encabezado, igual en las dos plantillas

Antes:
```php
<div class="notary-name"><?= H::orDash($notary->name) ?></div>
<div>Notario titular: <?= H::orDash($notary->notaryName) ?></div>
<div class="muted">RFC: <?= H::orDash($notary->rfc) ?></div>
<div class="muted"><?= H::orDash($addressLine) ?></div>
<div class="muted">Tel. <?= H::orDash($notary->phone) ?></div>
```

Después:
```php
<div class="notary-name"><?= H::orDash($notary->name) ?></div>
<div><?= H::orDash($notary->notaryName) ?></div>
<div class="muted"><?= H::orDash($notary->rfc, '---') ?></div>
<div class="muted"><?= H::orDash($addressLine) ?></div>
<div class="muted">Tel. <?= H::orDash($notary->phone) ?></div>
```

### Ajuste de helpers de test

`ReceiptHtmlBuilderTest::notary()` hoy fija el RFC y el notario. Agregar parámetros con los valores actuales como default, para no tocar los tests existentes:

```php
private function notary(
    ?string $logo = null,
    string $name = 'Notaría 42',
    string $notaryName = 'Lic. Juan Pérez',
    string $rfc = 'XAXX010101000',
): ReceiptNotary {
    return new ReceiptNotary($name, $notaryName, $rfc, 'Av. Central 100', 'Querétaro', '4421234567', $logo);
}
```

`StatementHtmlBuilderTest::data()` ya recibe varios parámetros; agregar `string $titularName = 'Lic. Juan Pérez'` y `string $rfc = 'XAXX010101000'` al final (usar argumentos con nombre en los tests nuevos).

Ejemplos de aserciones:

```php
$html = $this->builder->build($this->transaction(notary: $this->notary(rfc: '   ')), self::QR);

$this->assertStringContainsString('<div class="muted">---</div>', $html);
$this->assertStringNotContainsString('RFC:', $html);
$this->assertStringNotContainsString('Notario titular:', $html);
```

Para el orden de líneas, comparar `strpos` de nombre, notario, RFC, dirección y `Tel.`; el RFC debe quedar entre el notario y la dirección.

### Test de paridad

Renderizar con los dos builders y extraer el bloque entre `<div class="notary-name">` y la línea `Tel.` con `preg_match` (modificador `s`). Afirmar que ambos resultados son iguales, con RFC `XAXX010101000` y con RFC vacío. Los builders son `new ReceiptHtmlBuilder()` (`build($data, $qr)`) y `new StatementHtmlBuilder()` (`build($data)`). Los helpers de datos de cada test existente sirven de modelo para armar `ReceiptData` y `StatementPdfData`.

## Puntos de atención

- **No cambiar el default de `orDash`.** Se usa en 14 sitios (cliente, procedimiento, referencia, responsable, concepto, etc.) y esos deben seguir mostrando `—`. El criterio de rechazo de PO es explícito.
- **`—` (raya) y `---` (tres guiones ASCII) son caracteres distintos.** Copiar `'---'` tal cual, sin autocorrección del editor.
- **El notario vacío sigue mostrando `—`** (sin segundo argumento). Solo el RFC lleva `---`.
- **Mantener `Tel.`** y su `—` cuando falta el teléfono.
- **Los dos bloques deben quedar idénticos.** Si cambia uno, cambia el otro.
- **No tocar CSS.** `notary-name` y `muted` se conservan; la línea del notario no lleva clase, como hoy.
- **`rfc` es `string` no nulo en toda la cadena.** `Notary::rfc` (`Entity/Notary.php:16`, asignado desde `$row['rfc']`) pasa tal cual a `ReceiptNotary::rfc` (`ReceiptService.php:149`). En la práctica al helper llegan `''` o espacios, no `null`. `orDash` acepta `?string`, así que el caso "nulo" de PO queda cubierto sin código extra. No agregar coalescencia en el mapeo: si la columna permitiera `NULL`, el fallo ocurriría antes, al hidratar la entidad, y estaría fuera de esta spec.
- **Test existente `testNeverPrintsTheWordNullOrPhpNotices`** busca `null` en el HTML sin distinguir mayúsculas; `---` no lo afecta.

## Comandos

```bash
docker-compose exec api composer install        # primera vez
docker-compose exec api composer test           # suite completa (verificar el nombre del script en composer.json)
docker-compose exec api vendor/bin/phpunit tests/Module/Receipt/Renderer
docker-compose exec api vendor/bin/phpunit tests/Module/PaymentStatement/Renderer
```

## Verificación manual

Generar con un token válido y revisar en un visor de PDF, con y sin RFC en la notaría:

- `GET /api/v3/payment-transactions/{id}/receipt`: recibo de trámite
- `GET /api/v3/payments/{id}/receipt`: recibo de cobro, incluido uno cancelado (marca de agua)
- `GET /api/v3/payment-clients/{id}/statement/pdf`: estado de cuenta, con y sin movimientos

Revisar: sin etiquetas, orden de líneas, RFC atenuado, `---` cuando falta, logo en su lugar, acentos correctos y `—` conservado en cliente y teléfono.

## Definición de terminado

- [ ] Criterios de `technical_acceptance_criteria.md` cumplidos
- [ ] Suite completa verde
- [ ] Verificación visual hecha, con evidencia para QA
- [ ] `specs/_index.md` actualizado al cerrar la feature
- [ ] PR hacia `develop` creado por dev; QA lo cierra
