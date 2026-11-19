<?php

/**
 * Receipt template (Dompdf HTML/CSS). Rendered by ReceiptHtmlBuilder; do not call directly.
 *
 * @var string $title
 * @var string $folio
 * @var string $date
 * @var bool $isTransaction
 * @var bool $cancelled
 * @var string $qrDataUri
 * @var \App\Module\Receipt\DTO\ReceiptData $data
 */

use App\Module\Receipt\Renderer\ReceiptHtmlBuilder as H;
use App\Module\Receipt\Service\MoneyFormatter as M;

$notary = $data->notary;
$client = $data->client;
$addressLine = trim($notary->address . ($notary->city !== '' && $notary->address !== '' ? ', ' : '') . $notary->city);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= H::escape($title . ' ' . $folio) ?></title>
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
    .qr { width: 90px; text-align: right; vertical-align: top; }
    .qr img { width: 80px; height: 80px; }
    .section-title { font-size: 10px; font-weight: bold; color: #1f3a5f; text-transform: uppercase;
        border-bottom: 1px solid #c8d0da; padding-bottom: 3px; margin: 16px 0 6px 0; letter-spacing: 1px; }
    .kv td { padding: 3px 0; vertical-align: top; }
    .kv td.k { width: 130px; color: #666; }
    .items { margin-top: 6px; }
    .items thead { display: table-header-group; }
    .items tr { page-break-inside: avoid; }
    .items th { background: #eef1f5; text-align: left; padding: 5px 6px; font-size: 10px; border-bottom: 1px solid #c8d0da; }
    .items td { padding: 5px 6px; border-bottom: 1px solid #e3e7ec; vertical-align: top; }
    .num { text-align: right; }
    .qty { width: 60px; }
    .amt { width: 110px; }
    .totals { margin-top: 8px; }
    .totals td { padding: 3px 6px; }
    .totals .label { text-align: right; color: #666; }
    .totals .value { text-align: right; width: 110px; }
    .total-row td { font-size: 13px; font-weight: bold; border-top: 2px solid #1f3a5f; padding-top: 6px; }
    .words { margin-top: 18px; padding: 8px 10px; background: #f5f7fa; border: 1px solid #c8d0da; font-weight: bold; text-align: center; }
    .comments { white-space: pre-wrap; }
<?php if ($cancelled) : ?>
    .watermark { position: fixed; top: 38%; left: 5%; width: 90%; text-align: center; font-size: 96px;
        font-weight: bold; color: #c00; opacity: 0.15; transform: rotate(-30deg); }
<?php endif; ?>
</style>
</head>
<body>
<?php if ($cancelled) : ?>
<div class="watermark">CANCELADO</div>
<?php endif; ?>

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

<table>
    <tr>
        <td>
            <h1><?= H::escape($title) ?></h1>
            <div>Folio: <strong><?= H::escape($folio) ?></strong></div>
            <div>Fecha: <?= H::escape($date) ?></div>
        </td>
        <td class="qr"><img src="<?= H::escape($qrDataUri) ?>" alt=""></td>
    </tr>
</table>

<div class="section-title">Cliente</div>
<table class="kv">
    <tr><td class="k">Nombre</td><td><?= H::orDash($client->name) ?></td></tr>
    <tr><td class="k">Teléfono</td><td><?= H::orDash($client->phone) ?></td></tr>
    <tr><td class="k">Correo</td><td><?= H::orDash($client->email) ?></td></tr>
</table>

<div class="section-title">Detalle</div>
<?php if ($isTransaction) : ?>
<table class="kv">
    <tr><td class="k">Procedimiento</td><td><?= H::orDash($data->procedureName) ?></td></tr>
    <tr><td class="k">Referencia</td><td><?= H::orDash($data->reference) ?></td></tr>
    <tr><td class="k">Responsable</td><td><?= H::orDash($data->responsible) ?></td></tr>
    <tr><td class="k">Cuota de honorarios</td><td><?= H::escape(M::money($data->fee)) ?></td></tr>
</table>

    <?php if ($data->taxLines !== []) : ?>
<table class="items">
    <thead>
        <tr>
            <th class="qty">Cantidad</th>
            <th>Concepto</th>
            <th class="num amt">Importe</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data->taxLines as $line) : ?>
        <tr>
            <td class="qty"><?= H::escape((string) $line->quantity) ?></td>
            <td><?= H::orDash($line->concept) ?></td>
            <td class="num amt"><?= H::escape(M::money($line->amount)) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
    <?php endif; ?>

<table class="totals">
    <tr>
        <td class="label">Impuestos totales</td>
        <td class="value"><?= H::escape(M::money($data->taxes)) ?></td>
    </tr>
    <tr class="total-row">
        <td class="label">TOTAL</td>
        <td class="value"><?= H::escape(M::money($data->total)) ?></td>
    </tr>
</table>
<?php else : ?>
<table class="kv">
    <tr><td class="k">Monto</td><td><strong><?= H::escape(M::money($data->total)) ?></strong></td></tr>
    <tr><td class="k">Fecha de pago</td><td><?= H::escape($date) ?></td></tr>
    <tr><td class="k">Comentarios</td><td class="comments"><?= H::escape($data->comments) ?></td></tr>
</table>
<?php endif; ?>

<div class="words"><?= H::escape($data->amountInWords) ?></div>
</body>
</html>
