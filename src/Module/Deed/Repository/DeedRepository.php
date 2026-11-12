<?php

declare(strict_types=1);

namespace App\Module\Deed\Repository;

use App\Module\Deed\DTO\DeedFields;
use App\Module\Deed\Entity\DeedListItem;
use App\Module\Deed\Entity\DeedLock;
use DateTimeImmutable;
use PDO;
use PDOStatement;

class DeedRepository
{
    private const CASE_ACCENT_INSENSITIVE = 'CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci';
    private const PARTICIPANT_ACTIVE = 1;
    private const PARTICIPANT_SELLER = 1;
    private const PARTICIPANT_BUYER = 2;
    /** Legacy marker for "no subdivision" (ADR-004). */
    private const NO_SUBDIVISION = -1;

    private const HEADER_SQL = "SELECT e.id_escritura, e.id_notaria, e.num_escritura, e.libro, e.fecha_escritura,
               e.id_acto, a.nombre_acto,
               e.id_tipo_escitura, t.nombre_tipo,
               e.id_fraccionamiento, f.nombre_fraccionamiento,
               e.predio, e.fecha_entrega, e.entrego, e.observaciones, e.terminada,
               e.id_usuario, CONCAT_WS(' ', u.nombre, u.apellido_paterno, u.apellido_materno) AS nombre_usuario,
               e.fecha_modificacion
        FROM escrituras e
        LEFT JOIN actos_notaria a ON a.id_acto = e.id_acto AND a.id_notaria = e.id_notaria
        LEFT JOIN tipo_escritura t ON t.id_tipo_escitura = e.id_tipo_escitura AND t.id_notaria = e.id_notaria
        LEFT JOIN fraccionamientos f ON f.id_fraccionamiento = e.id_fraccionamiento AND f.id_notaria = e.id_notaria
        LEFT JOIN usuarios_notaria u ON u.id_usuario = e.id_usuario AND u.id_notaria = e.id_notaria
        WHERE e.id_escritura = :id AND e.id_notaria = :notaryId";

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
     * Deed header with its catalog and user names in a single query.
     *
     * @return array<string, mixed>|null
     */
    public function findHeaderByIdAndNotary(int $id, int $notaryId): ?array
    {
        $stmt = $this->db->prepare(self::HEADER_SQL);
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Locks the deed row (must run inside a transaction). Doubles as the multi-tenant existence check.
     */
    public function lockForUpdate(int $id, int $notaryId): ?DeedLock
    {
        $stmt = $this->db->prepare(
            'SELECT id_escritura, id_acto, id_tipo_escitura, id_fraccionamiento, terminada
             FROM escrituras
             WHERE id_escritura = :id AND id_notaria = :notaryId
             FOR UPDATE'
        );
        $stmt->execute(['id' => $id, 'notaryId' => $notaryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : DeedLock::fromRow($row);
    }

    public function insert(int $notaryId, DeedFields $fields, int $userId, DateTimeImmutable $now): int
    {
        $sql = 'INSERT INTO escrituras (
                    id_notaria, num_escritura, libro, fecha_escritura, id_acto, id_tipo_escitura,
                    id_fraccionamiento, predio, fecha_entrega, entrego, observaciones,
                    terminada, id_usuario, fecha_modificacion
                ) VALUES (
                    :notaryId, :deedNumber, :book, :deedDate, :actId, :deedTypeId,
                    :subdivisionId, :property, :deliveryDate, :deliveredBy, :observations,
                    0, :userId, :modifiedAt
                )';

        $this->db->prepare($sql)->execute([
            'notaryId' => $notaryId,
            'deedNumber' => $fields->deedNumber,
            'book' => $fields->book,
            'deedDate' => $fields->deedDate,
            'actId' => $fields->actId,
            'deedTypeId' => $fields->deedTypeId,
            'subdivisionId' => $fields->subdivisionId ?? self::NO_SUBDIVISION,
            'property' => $fields->property,
            'deliveryDate' => $fields->deliveryDate,
            'deliveredBy' => $fields->deliveredBy,
            'observations' => $fields->observations,
            'userId' => $userId,
            'modifiedAt' => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Never touches the finished flag: only POST /finish may change it.
     */
    public function update(int $id, int $notaryId, DeedFields $fields, int $userId, DateTimeImmutable $now): void
    {
        $sql = 'UPDATE escrituras
                SET num_escritura = :deedNumber, libro = :book, fecha_escritura = :deedDate,
                    id_acto = :actId, id_tipo_escitura = :deedTypeId, id_fraccionamiento = :subdivisionId,
                    predio = :property, fecha_entrega = :deliveryDate, entrego = :deliveredBy,
                    observaciones = :observations,
                    id_usuario = :userId, fecha_modificacion = :modifiedAt
                WHERE id_escritura = :id AND id_notaria = :notaryId';

        $this->db->prepare($sql)->execute([
            'id' => $id,
            'notaryId' => $notaryId,
            'deedNumber' => $fields->deedNumber,
            'book' => $fields->book,
            'deedDate' => $fields->deedDate,
            'actId' => $fields->actId,
            'deedTypeId' => $fields->deedTypeId,
            'subdivisionId' => $fields->subdivisionId ?? self::NO_SUBDIVISION,
            'property' => $fields->property,
            'deliveryDate' => $fields->deliveryDate,
            'deliveredBy' => $fields->deliveredBy,
            'observations' => $fields->observations,
            'userId' => $userId,
            'modifiedAt' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function markFinished(int $id, int $notaryId, int $userId, DateTimeImmutable $now): void
    {
        $this->db->prepare(
            'UPDATE escrituras
             SET terminada = 1, id_usuario = :userId, fecha_modificacion = :modifiedAt
             WHERE id_escritura = :id AND id_notaria = :notaryId'
        )->execute([
            'id' => $id,
            'notaryId' => $notaryId,
            'userId' => $userId,
            'modifiedAt' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Audit only: records who last touched the deed (used by participant and registration writes).
     */
    public function touch(int $id, int $notaryId, int $userId, DateTimeImmutable $now): void
    {
        $this->db->prepare(
            'UPDATE escrituras
             SET id_usuario = :userId, fecha_modificacion = :modifiedAt
             WHERE id_escritura = :id AND id_notaria = :notaryId'
        )->execute([
            'id' => $id,
            'notaryId' => $notaryId,
            'userId' => $userId,
            'modifiedAt' => $now->format('Y-m-d H:i:s'),
        ]);
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
