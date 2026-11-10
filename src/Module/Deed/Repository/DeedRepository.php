<?php

declare(strict_types=1);

namespace App\Module\Deed\Repository;

use App\Module\Deed\Entity\DeedListItem;
use PDO;
use PDOStatement;

class DeedRepository
{
    private const CASE_ACCENT_INSENSITIVE = 'CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    private const PARTICIPANT_ACTIVE = 1;
    private const PARTICIPANT_SELLER = 1;
    private const PARTICIPANT_BUYER = 2;

    private const FROM = 'FROM escrituras e
        LEFT JOIN actos_notaria a ON a.id_acto = e.id_acto
        LEFT JOIN tipo_escritura t ON t.id_tipo_escitura = e.id_tipo_escitura';

    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * @param string[] $words
     */
    public function countByNotary(int $notaryId, array $words): int
    {
        [$where, $params] = $this->buildFilter($notaryId, $words);

        $stmt = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . ' WHERE ' . $where);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param string[] $words
     * @return DeedListItem[]
     */
    public function findPageByNotary(int $notaryId, array $words, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildFilter($notaryId, $words);

        $sql = 'SELECT e.id_escritura, e.num_escritura, e.libro, e.fecha_escritura,
                       a.nombre_acto, t.nombre_tipo, e.terminada '
            . self::FROM
            . ' WHERE ' . $where
            . ' ORDER BY CAST(e.num_escritura AS UNSIGNED) DESC, e.num_escritura DESC, e.id_escritura DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $deeds = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $deeds[] = DeedListItem::fromRow($row);
        }

        return $deeds;
    }

    /**
     * Builds the WHERE clause shared by the count and page queries.
     *
     * A deed matches when every word is found in "number + act", or when a single
     * active participant (seller/buyer) contains every word in "number + act + name".
     *
     * @param string[] $words
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function buildFilter(int $notaryId, array $words): array
    {
        $where = 'e.id_notaria = :notaryId';
        $params = ['notaryId' => $notaryId];

        if ($words === []) {
            return [$where, $params];
        }

        $deedHaystack = sprintf(self::CASE_ACCENT_INSENSITIVE, "CONCAT_WS(' ', e.num_escritura, a.nombre_acto)");
        $participantHaystack = sprintf(
            self::CASE_ACCENT_INSENSITIVE,
            "CONCAT_WS(' ', e.num_escritura, a.nombre_acto, p.nombre_participante)"
        );

        $deedConditions = [];
        $participantConditions = [];
        foreach ($words as $i => $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            // Unique names: native prepares (EMULATE_PREPARES=false) cannot reuse a placeholder
            $params["d{$i}"] = $like;
            $params["p{$i}"] = $like;
            $deedConditions[] = "{$deedHaystack} LIKE :d{$i}";
            $participantConditions[] = "{$participantHaystack} LIKE :p{$i}";
        }

        $where .= ' AND ((' . implode(' AND ', $deedConditions) . ')'
            . ' OR EXISTS (SELECT 1 FROM participantes_escritura p'
            . ' WHERE p.id_escritura = e.id_escritura'
            . ' AND p.id_estatus = :activeStatus'
            . ' AND p.id_tipo_participante IN (:typeSeller, :typeBuyer)'
            . ' AND ' . implode(' AND ', $participantConditions) . '))';

        $params['activeStatus'] = self::PARTICIPANT_ACTIVE;
        $params['typeSeller'] = self::PARTICIPANT_SELLER;
        $params['typeBuyer'] = self::PARTICIPANT_BUYER;

        return [$where, $params];
    }

    /**
     * @param array<string, int|string> $params
     */
    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
