<?php

/**
 * Client statement template (Dompdf HTML/CSS). Rendered by StatementHtmlBuilder; do not call directly.
 *
 * @var \App\Module\PaymentStatement\DTO\StatementPdfData $data
 * @var string $period
 * @var string $issuedOn
 * @var list<array{opening: bool, date: string, folio: string, concept: string, charge: string, payment: string, balance: string}> $rows
 * @var bool $showOpening
 * @var string $pending
 * @var string $totalCharges
 * @var string $totalPayments
 * @var string $balance
 * @var string $statusLabel
 * @var string|null $legendAmount
 */

use App\Module\Receipt\Renderer\ReceiptHtmlBuilder as H;

$notary = $data->notary;
$client = $data->client;
$addressLine = trim($notary->address . ($notary->city !== '' && $notary->address !== '' ? ', ' : '') . $notary->city);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= H::escape('Estado de cuenta ' . $period) ?></title>
<style>
    @page { margin: 36px 40px 64px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #222; }
    table { width: 100%; border-collapse: collapse; }
    h1 { font-size: 18px; margin: 0 0 4px 0; letter-spacing: 1px; }
    .muted { color: #666; }
    .header { border-bottom: 2px solid #1f3a5f; padding-bottom: 10px; margin-bottom: 14px; }
    .logo { width: 90px; vertical-align: middle; }
    .logo img { max-width: 80px; max-height: 70px; }
    .notary-name { font-size: 15px; font-weight: bold; color: #1f3a5f; }
    .section-title { font-size: 10px; font-weight: bold; color: #1f3a5f; text-transform: uppercase;
        border-bottom: 1px solid #c8d0da; padding-bottom: 3px; margin: 16px 0 6px 0; letter-spacing: 1px; }
    /* Fixed layouts: a long text with no spaces (a URL, a pasted folio) is cut inside its cell instead of
       widening the table past the page and pushing the amount columns out of it */
    .kv { table-layout: fixed; }
    .kv td { padding: 3px 0; vertical-align: top; word-wrap: break-word; }
    .kv td.k { width: 18%; color: #666; }
    .ledger { margin-top: 6px; table-layout: fixed; }
    .ledger thead { display: table-header-group; }
    .ledger tr { page-break-inside: avoid; }
    .ledger th { background: #eef1f5; text-align: left; padding: 5px 6px; font-size: 10px; border-bottom: 1px solid #c8d0da; }
    .ledger td { padding: 5px 6px; border-bottom: 1px solid #e3e7ec; vertical-align: top; word-wrap: break-word; }
    .ledger tr.opening td { background: #f5f7fa; font-style: italic; }
    .ledger tr.empty td { text-align: center; color: #666; padding: 12px 6px; }
    /* Percentages on purpose: Dompdf ignores absolute widths (px) in table-layout:fixed. Only the header row
       counts; Concepto takes the rest. The amount columns fit up to $99,999,999.99 */
    .date { width: 12%; }
    .folio { width: 10%; }
    .num { text-align: right; }
    .amt { width: 15%; }
    .bal { width: 15%; }
    .closing { page-break-inside: avoid; margin-top: 16px; }
    .totals td { padding: 3px 6px; }
    .totals .label { text-align: right; color: #666; }
    .totals .value { text-align: right; width: 110px; }
    .total-row td { font-size: 13px; font-weight: bold; border-top: 2px solid #1f3a5f; padding-top: 6px; }
    .legend { margin-top: 14px; padding: 8px 10px; background: #f5f7fa; border: 1px solid #c8d0da; text-align: center; }
    .legend .status { font-size: 13px; font-weight: bold; letter-spacing: 1px; }
    .legend .words { margin-top: 4px; font-weight: bold; }
</style>
</head>
<body>

<table class="header">
    <tr>
<?php if ($notary->logoDataUri !== null) : ?>
        <td class="logo"><img src="<?= H::escape($notary->logoDataUri) ?>" alt=""></td>
<?php endif; ?>
        <td>
            <div class="notary-name"><?= H::orDash($notary->name) ?></div>
            <div>Notario titular: <?= H::orDash($notary->notaryName) ?></div>
            <div class="muted">RFC: <?= H::orDash($notary->rfc) ?></div>
            <div class="muted"><?= H::orDash($addressLine) ?></div>
            <div class="muted">Tel. <?= H::orDash($notary->phone) ?></div>
        </td>
    </tr>
</table>

<h1>ESTADO DE CUENTA</h1>
<div>Periodo: <strong><?= H::escape($period) ?></strong></div>
<div>Fecha de emisión: <?= H::escape($issuedOn) ?></div>

<div class="section-title">Cliente</div>
<table class="kv">
    <tr><td class="k">Nombre</td><td><?= H::orDash($client->name) ?></td></tr>
    <tr><td class="k">Teléfono</td><td><?= H::orDash($client->phone) ?></td></tr>
    <tr><td class="k">Correo</td><td><?= H::orDash($client->email) ?></td></tr>
</table>

<div class="section-title">Movimientos</div>
<table class="ledger">
    <thead>
        <tr>
            <th class="date">Fecha</th>
            <th class="folio">Folio</th>
            <th>Concepto</th>
            <th class="num amt">Cargo</th>
            <th class="num amt">Abono</th>
            <th class="num bal">Saldo</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $row) : ?>
        <tr<?= $row['opening'] ? ' class="opening"' : '' ?>>
            <td class="date"><?= H::escape($row['date']) ?></td>
            <td class="folio"><?= H::escape($row['folio']) ?></td>
            <td><?= H::escape($row['concept']) ?></td>
            <td class="num amt"><?= H::escape($row['charge']) ?></td>
            <td class="num amt"><?= H::escape($row['payment']) ?></td>
            <td class="num bal"><?= H::escape($row['balance']) ?></td>
        </tr>
<?php endforeach; ?>
<?php if (!$data->hasMovements) : ?>
        <tr class="empty"><td colspan="6">Sin movimientos en el periodo</td></tr>
<?php endif; ?>
    </tbody>
</table>

<div class="closing">
    <div class="section-title">Resumen</div>
    <table class="totals">
<?php if ($showOpening) : ?>
        <tr><td class="label">Saldo anterior</td><td class="value"><?= H::escape($pending) ?></td></tr>
<?php endif; ?>
        <tr><td class="label">Total de cargos</td><td class="value"><?= H::escape($totalCharges) ?></td></tr>
        <tr><td class="label">Total de abonos</td><td class="value"><?= H::escape($totalPayments) ?></td></tr>
        <tr class="total-row"><td class="label">SALDO FINAL</td><td class="value"><?= H::escape($balance) ?></td></tr>
    </table>

    <div class="legend">
        <div class="status"><?= H::escape($statusLabel) ?></div>
<?php if ($legendAmount !== null) : ?>
        <div><?= H::escape($legendAmount) ?></div>
        <div class="words"><?= H::escape($data->amountInWords) ?></div>
<?php endif; ?>
    </div>
</div>
</body>
</html>
