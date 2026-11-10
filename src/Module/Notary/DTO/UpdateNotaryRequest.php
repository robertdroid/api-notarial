<?php

declare(strict_types=1);

namespace App\Module\Notary\DTO;

final class UpdateNotaryRequest
{
    public readonly string $name;
    public readonly string $notaryName;
    public readonly ?string $city;
    public readonly ?string $address;
    public readonly ?string $phone;
    public readonly ?string $rfc;

    private array $errors = [];
    private array $providedFields = [];

    private function __construct(
        string $name,
        string $notaryName,
        ?string $city,
        ?string $address,
        ?string $phone,
        ?string $rfc,
        array $providedFields,
    ) {
        $this->name = trim($name);
        $this->notaryName = trim($notaryName);
        $this->city = $city !== null ? trim($city) : null;
        $this->address = $address !== null ? trim($address) : null;
        $this->phone = $phone !== null ? trim($phone) : null;
        $this->rfc = $rfc !== null ? trim($rfc) : null;
        $this->providedFields = $providedFields;
    }

    public static function fromArray(array $data): self
    {
        $providedFields = array_keys($data);

        $instance = new self(
            name: $data['name'] ?? '',
            notaryName: $data['notaryName'] ?? '',
            city: $data['city'] ?? null,
            address: $data['address'] ?? null,
            phone: $data['phone'] ?? null,
            rfc: $data['rfc'] ?? null,
            providedFields: $providedFields,
        );

        $instance->validate();

        return $instance;
    }

    private function validate(): void
    {
        // Required fields
        if (empty($this->name)) {
            $this->errors['name'] = 'Notary name is required';
        } elseif (mb_strlen($this->name) > 500) {
            $this->errors['name'] = 'Notary name must not exceed 500 characters';
        }

        if (empty($this->notaryName)) {
            $this->errors['notaryName'] = 'Notary titular name is required';
        } elseif (mb_strlen($this->notaryName) > 500) {
            $this->errors['notaryName'] = 'Notary titular name must not exceed 500 characters';
        }

        // Optional fields - validate only if provided
        if ($this->city !== null && mb_strlen($this->city) > 150) {
            $this->errors['city'] = 'City must not exceed 150 characters';
        }

        if ($this->address !== null && mb_strlen($this->address) > 500) {
            $this->errors['address'] = 'Address must not exceed 500 characters';
        }

        if ($this->phone !== null) {
            if (mb_strlen($this->phone) > 20) {
                $this->errors['phone'] = 'Phone must not exceed 20 characters';
            } elseif ($this->phone !== '' && !preg_match('/^[\d\s\-]+$/', $this->phone)) {
                $this->errors['phone'] = 'Phone must contain only digits, spaces, and hyphens';
            }
        }

        if ($this->rfc !== null && mb_strlen($this->rfc) > 20) {
            $this->errors['rfc'] = 'RFC must not exceed 20 characters';
        }
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns true if the field was sent in the request (even if empty)
     */
    public function hasField(string $field): bool
    {
        return in_array($field, $this->providedFields, true);
    }

    /**
     * Returns only the fields that were sent and have a value
     */
    public function getFieldsToUpdate(): array
    {
        $fields = [];

        // Required fields always get updated
        $fields['name'] = $this->name;
        $fields['notaryName'] = $this->notaryName;

        // Optional fields only if they came in the request
        if ($this->hasField('city') && $this->city !== null) {
            $fields['city'] = $this->city;
        }
        if ($this->hasField('address') && $this->address !== null) {
            $fields['address'] = $this->address;
        }
        if ($this->hasField('phone') && $this->phone !== null) {
            $fields['phone'] = $this->phone;
        }
        if ($this->hasField('rfc') && $this->rfc !== null) {
            $fields['rfc'] = $this->rfc;
        }

        return $fields;
    }
}
