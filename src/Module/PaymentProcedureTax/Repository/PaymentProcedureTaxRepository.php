<?php

declare(strict_types=1);

namespace App\Module\PaymentProcedureTax\Repository;

use App\Module\PaymentProcedureTax\DTO\ProcedureTaxItem;
use App\Module\PaymentProcedureTax\Entity\PaymentProcedureTax;
use App\Module\PaymentProcedureTax\Entity\ProcedureWithTaxes;
use PDO;

class PaymentProcedureTaxRepository
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Active assignments of active taxes. Tenancy is enforced on both parents.
     *
     * @return PaymentProcedureTax[]
     */
    public function findActiveByProcedure(int $procedureId, int $notaryId): array
    {
        // Native prepares do not allow repeating a named placeholder.
        $sql = 'SELECT ppt.id_procedure, ppt.id_tax, pt.tax_name, ppt.default_value, ppt.id_status
                FROM payment_procedure_taxes ppt
                JOIN payment_procedures pp ON pp.id_procedure = ppt.id_procedure AND pp.id_notary = :notaryId
                JOIN payment_taxes pt ON pt.id_tax = ppt.id_tax AND pt.id_notary = :notaryId2
                WHERE ppt.id_procedure = :procedureId
                  AND ppt.id_status = ' . PaymentProcedureTax::STATUS_ACTIVE . '
                  AND pt.id_status = 1
                ORDER BY CONVERT(pt.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC, ppt.id_tax ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'notaryId' => $notaryId,
            'notaryId2' => $notaryId,
            'procedureId' => $procedureId,
        ]);

        $taxes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $taxes[] = PaymentProcedureTax::fromRow($row);
        }

        return $taxes;
    }

    /**
     * Active procedures of the notary, each with its active assignments of active taxes.
     * Procedures without taxes are included with an empty list. Single query (no N+1).
     *
     * @return ProcedureWithTaxes[]
     */
    public function findActiveProceduresWithTaxes(int $notaryId): array
    {
        // Status/tenancy filters of the joined tables live in ON so procedures without taxes are kept.
        // Native prepares do not allow repeating a named placeholder.
        $sql = 'SELECT pp.id_procedure, pp.procedure_name,
                       pt.id_tax, pt.tax_name, ppt.default_value, ppt.id_status
                FROM payment_procedures pp
                LEFT JOIN payment_procedure_taxes ppt
                       ON ppt.id_procedure = pp.id_procedure
                      AND ppt.id_status = ' . PaymentProcedureTax::STATUS_ACTIVE . '
                LEFT JOIN payment_taxes pt
                       ON pt.id_tax = ppt.id_tax
                      AND pt.id_notary = :notaryId2
                      AND pt.id_status = 1
                WHERE pp.id_notary = :notaryId
                  AND pp.id_status = 1
                ORDER BY CONVERT(pp.procedure_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC,
                         pp.id_procedure ASC,
                         CONVERT(pt.tax_name USING utf8mb4) COLLATE utf8mb4_unicode_ci ASC,
                         pt.id_tax ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['notaryId' => $notaryId, 'notaryId2' => $notaryId]);

        /** @var array<int, array{name: string, taxes: PaymentProcedureTax[]}> $groups */
        $groups = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $procedureId = (int) $row['id_procedure'];
            $groups[$procedureId] ??= ['name' => (string) $row['procedure_name'], 'taxes' => []];

            // NULL when the assignment is active but the catalog tax is inactive or foreign.
            if ($row['id_tax'] !== null) {
                $groups[$procedureId]['taxes'][] = PaymentProcedureTax::fromRow($row);
            }
        }

        $result = [];
        foreach ($groups as $procedureId => $group) {
            $result[] = new ProcedureWithTaxes($procedureId, $group['name'], $group['taxes']);
        }

        return $result;
    }

    /**
     * Status of the existing assignments (any status) for the given taxes.
     *
     * @param int[] $taxIds
     * @return array<int, int> taxId => id_status
     */
    public function findStatuses(int $procedureId, array $taxIds): array
    {
        if ($taxIds === []) {
            return [];
        }

        $params = ['procedureId' => $procedureId];
        $placeholders = [];
        foreach (array_values($taxIds) as $i => $taxId) {
            $placeholders[] = ":t$i";
            $params["t$i"] = $taxId;
        }

        $sql = 'SELECT id_tax, id_status FROM payment_procedure_taxes
                WHERE id_procedure = :procedureId AND id_tax IN (' . implode(', ', $placeholders) . ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $statuses = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statuses[(int) $row['id_tax']] = (int) ($row['id_status'] ?? PaymentProcedureTax::STATUS_ACTIVE);
        }

        return $statuses;
    }

    /**
     * Inserts new assignments and reactivates inactive ones in a single statement
     * (MyISAM has no transactions; requires the UNIQUE (id_procedure, id_tax) index).
     *
     * @param ProcedureTaxItem[] $items
     */
    public function upsertMany(int $procedureId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $params = [];
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $rows[] = "(:p$i, :t$i, :v$i, " . PaymentProcedureTax::STATUS_ACTIVE . ')';
            $params["p$i"] = $procedureId;
            $params["t$i"] = $item->taxId;
            $params["v$i"] = $item->defaultValue;
        }

        $sql = 'INSERT INTO payment_procedure_taxes (id_procedure, id_tax, default_value, id_status) VALUES '
            . implode(', ', $rows)
            . ' ON DUPLICATE KEY UPDATE default_value = VALUES(default_value), id_status = '
            . PaymentProcedureTax::STATUS_ACTIVE;

        $this->db->prepare($sql)->execute($params);
    }

    public function updateDefaultValue(int $procedureId, int $taxId, float $value, int $notaryId): void
    {
        $sql = 'UPDATE payment_procedure_taxes ppt
                JOIN payment_procedures pp ON pp.id_procedure = ppt.id_procedure
                SET ppt.default_value = :value
                WHERE ppt.id_procedure = :procedureId AND ppt.id_tax = :taxId
                  AND ppt.id_status = ' . PaymentProcedureTax::STATUS_ACTIVE . '
                  AND pp.id_notary = :notaryId';

        $this->db->prepare($sql)->execute([
            'value' => $value,
            'procedureId' => $procedureId,
            'taxId' => $taxId,
            'notaryId' => $notaryId,
        ]);
    }

    public function deactivate(int $procedureId, int $taxId, int $notaryId): void
    {
        $sql = 'UPDATE payment_procedure_taxes ppt
                JOIN payment_procedures pp ON pp.id_procedure = ppt.id_procedure
                SET ppt.id_status = ' . PaymentProcedureTax::STATUS_INACTIVE . '
                WHERE ppt.id_procedure = :procedureId AND ppt.id_tax = :taxId
                  AND ppt.id_status = ' . PaymentProcedureTax::STATUS_ACTIVE . '
                  AND pp.id_notary = :notaryId';

        $this->db->prepare($sql)->execute([
            'procedureId' => $procedureId,
            'taxId' => $taxId,
            'notaryId' => $notaryId,
        ]);
    }
}
