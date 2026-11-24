<?php

declare(strict_types=1);

namespace App\Module\Notary\Repository;

use App\Module\Notary\Entity\Notary;
use PDO;

class NotaryRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    public function findByIdActive(int $id): ?Notary
    {
        $sql = "SELECT * FROM notarias WHERE id_notaria = :id AND id_estatus = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Notary::fromRow($row) : null;
    }

    /**
     * Without status filter: lets the caller tell "does not exist" from "inactive".
     */
    public function findById(int $id): ?Notary
    {
        $stmt = $this->db->prepare('SELECT * FROM notarias WHERE id_notaria = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Notary::fromRow($row) : null;
    }

    public function update(int $id, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $setParts = [];
        $params = ['id' => $id];

        // Mapeo de campos API a columnas BD
        $columnMap = [
            'name' => 'nombre_notaria',
            'notaryName' => 'notario_titular',
            'city' => 'ciudad',
            'address' => 'direccion',
            'phone' => 'telefono',
            'rfc' => 'rfc',
            'logo' => 'logo',
        ];

        foreach ($fields as $field => $value) {
            if (isset($columnMap[$field])) {
                $column = $columnMap[$field];
                $setParts[] = "{$column} = :{$field}";
                $params[$field] = $value;
            }
        }

        if (empty($setParts)) {
            return;
        }

        $sql = "UPDATE notarias SET " . implode(', ', $setParts) . " WHERE id_notaria = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
