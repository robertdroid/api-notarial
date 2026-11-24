<?php

declare(strict_types=1);

namespace App\Module\Deed\Repository;

use App\Module\Deed\DTO\ParticipantInput;
use App\Module\Deed\Entity\Participant;
use PDO;

/**
 * Data access for participantes_escritura.
 *
 * It does not filter by notary: callers always resolve the parent deed first
 * (DeedRepository::lockForUpdate filters by notary) and then scope by deed id.
 */
class ParticipantRepository
{
    private const COLUMNS = 'id_participante, id_escritura, nombre_participante, id_tipo_participante, id_estatus';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /** @return Participant[] */
    public function findActiveByDeed(int $deedId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM participantes_escritura
             WHERE id_escritura = :deedId AND id_estatus = :status
             ORDER BY id_participante'
        );
        $stmt->execute(['deedId' => $deedId, 'status' => Participant::STATUS_ACTIVE]);

        $participants = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $participants[] = Participant::fromRow($row);
        }

        return $participants;
    }

    /** Returns the participant whatever its status: the Service decides what an inactive one means. */
    public function findByIdAndDeed(int $id, int $deedId): ?Participant
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM participantes_escritura
             WHERE id_participante = :id AND id_escritura = :deedId'
        );
        $stmt->execute(['id' => $id, 'deedId' => $deedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Participant::fromRow($row);
    }

    /** @param ParticipantInput[] $items */
    public function insertMany(int $deedId, array $items): void
    {
        if ($items === []) {
            return;
        }

        // Native prepares do not allow repeating a named placeholder, hence the indexed names.
        $params = [];
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $rows[] = "(:deedId$i, :name$i, :type$i, :status$i)";
            $params["deedId$i"] = $deedId;
            $params["name$i"] = $item->name;
            $params["type$i"] = $item->type->value;
            $params["status$i"] = Participant::STATUS_ACTIVE;
        }

        $this->db->prepare(
            'INSERT INTO participantes_escritura (id_escritura, nombre_participante, id_tipo_participante, id_estatus) VALUES '
            . implode(', ', $rows)
        )->execute($params);
    }

    public function insert(int $deedId, ParticipantInput $item): int
    {
        $this->db->prepare(
            'INSERT INTO participantes_escritura (id_escritura, nombre_participante, id_tipo_participante, id_estatus)
             VALUES (:deedId, :name, :type, :status)'
        )->execute([
            'deedId' => $deedId,
            'name' => $item->name,
            'type' => $item->type->value,
            'status' => Participant::STATUS_ACTIVE,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $deedId, ParticipantInput $item): void
    {
        $this->db->prepare(
            'UPDATE participantes_escritura
             SET nombre_participante = :name, id_tipo_participante = :type
             WHERE id_participante = :id AND id_escritura = :deedId'
        )->execute([
            'id' => $id,
            'deedId' => $deedId,
            'name' => $item->name,
            'type' => $item->type->value,
        ]);
    }

    public function deactivate(int $id, int $deedId): void
    {
        $this->db->prepare(
            'UPDATE participantes_escritura
             SET id_estatus = :status
             WHERE id_participante = :id AND id_escritura = :deedId'
        )->execute([
            'id' => $id,
            'deedId' => $deedId,
            'status' => Participant::STATUS_INACTIVE,
        ]);
    }
}
