<?php

declare(strict_types=1);

namespace Tests\Module\Act\DTO;

use App\Module\Act\DTO\UpdateActRequest;
use PHPUnit\Framework\TestCase;

final class UpdateActRequestTest extends TestCase
{
    public function testValidRequestIsValid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
        ]);

        $this->assertTrue($request->isValid());
        $this->assertEmpty($request->getErrors());
    }

    public function testMissingNameIsInvalid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actName', $request->getErrors());
    }

    public function testNameExceedingMaxLengthIsInvalid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => str_repeat('a', 201),
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actName', $request->getErrors());
    }

    public function testMissingDescriptionIsInvalid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'status' => 1,
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actDescription', $request->getErrors());
    }

    public function testDescriptionExceedingMaxLengthIsInvalid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => str_repeat('a', 501),
            'status' => 1,
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actDescription', $request->getErrors());
    }

    public function testMissingStatusIsInvalid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testInvalidStatusIsInvalid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 3,
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testStatusOneIsValid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
        ]);

        $this->assertTrue($request->isValid());
    }

    public function testStatusTwoIsValid(): void
    {
        $request = UpdateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 2,
        ]);

        $this->assertTrue($request->isValid());
    }
}
