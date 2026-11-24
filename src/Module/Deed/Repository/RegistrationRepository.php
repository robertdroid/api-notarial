<?php

declare(strict_types=1);

namespace App\Module\Deed\Repository;

use App\Module\Deed\DTO\RegistrationInput;
use App\Module\Deed\Entity\Registration;
use PDO;

/**
 * Data access for inscripciones_escritura.
 *
 * It does not filter by notary: callers always resolve the parent deed first
 * (DeedRepository::lockForUpdate filters by notary) and then scope by deed id.
 */
class RegistrationRepository
{
    private const COLUMNS = 'id_inscripcion, id_escritura, numero_inscripcion, seccion_inscripcion, tomo_inscripcion, fecha_inscripcion';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /** @return Registration[] */
    public function findByDeed(int $deedId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM inscripciones_escritura
             WHERE id_escritura = :deedId
             ORDER BY id_inscripcion'
        );
        $stmt->execute(['deedId' => $deedId]);

        $registrations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $registrations[] = Registration::fromRow($row);
        }

        return $registrations;
    }

    public function findByIdAndDeed(int $id, int $deedId): ?Registration
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM inscripciones_escritura
             WHERE id_inscripcion = :id AND id_escritura = :deedId'
        );
        $stmt->execute(['id' => $id, 'deedId' => $deedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Registration::fromRow($row);
    }

    public function countByDeed(int $deedId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM inscripciones_escritura WHERE id_escritura = :deedId');
        $stmt->execute(['deedId' => $deedId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param RegistrationInput[] $items */
    public function insertMany(int $deedId, array $items): void
    {
        if ($items === []) {
            return;
        }

        // Native prepares do not allow repeating a named placeholder, hence the indexed names.
        $params = [];
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $rows[] = "(:deedId$i, :number$i, :section$i, :volume$i, :date$i)";
            $params["deedId$i"] = $deedId;
            $params["number$i"] = $item->number;
            $params["section$i"] = $item->section;
            $params["volume$i"] = $item->volume;
            $params["date$i"] = $item->date;
        }

        $this->db->prepare(
            'INSERT INTO inscripciones_escritura
                (id_escritura, numero_inscripcion, seccion_inscripcion, tomo_inscripcion, fecha_inscripcion) VALUES '
            . implode(', ', $rows)
        )->execute($params);
    }

    public function insert(int $deedId, RegistrationInput $item): int
    {
        $this->db->prepare(
            'INSERT INTO inscripciones_escritura
                (id_escritura, numero_inscripcion, seccion_inscripcion, tomo_inscripcion, fecha_inscripcion)
             VALUES (:deedId, :number, :section, :volume, :date)'
        )->execute([
            'deedId' => $deedId,
            'number' => $item->number,
            'section' => $item->section,
            'volume' => $item->volume,
            'date' => $item->date,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $deedId, RegistrationInput $item): void
    {
        $this->db->prepare(
            'UPDATE inscripciones_escritura
             SET numero_inscripcion = :number, seccion_inscripcion = :section,
                 tomo_inscripcion = :volume, fecha_inscripcion = :date
             WHERE id_inscripcion = :id AND id_escritura = :deedId'
        )->execute([
            'id' => $id,
            'deedId' => $deedId,
            'number' => $item->number,
            'section' => $item->section,
            'volume' => $item->volume,
            'date' => $item->date,
        ]);
    }

    public function delete(int $id, int $deedId): void
    {
        $this->db->prepare(
            'DELETE FROM inscripciones_escritura WHERE id_inscripcion = :id AND id_escritura = :deedId'
        )->execute(['id' => $id, 'deedId' => $deedId]);
    }
}
