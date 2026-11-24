<?php

declare(strict_types=1);

namespace Tests\Module\Act\DTO;

use App\Module\Act\DTO\CreateActRequest;
use PHPUnit\Framework\TestCase;

final class CreateActRequestTest extends TestCase
{
    public function testValidRequestIsValid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 1,
        ]);

        $this->assertTrue($request->isValid());
        $this->assertEmpty($request->getErrors());
    }

    public function testMissingNameIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actName', $request->getErrors());
    }

    public function testEmptyNameIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => '',
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actName', $request->getErrors());
    }

    public function testWhitespaceOnlyNameIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => '   ',
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actName', $request->getErrors());
    }

    public function testNameExceedingMaxLengthIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => str_repeat('a', 201),
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actName', $request->getErrors());
    }

    public function testMissingDescriptionIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actDescription', $request->getErrors());
    }

    public function testDescriptionExceedingMaxLengthIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => str_repeat('a', 501),
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('actDescription', $request->getErrors());
    }

    public function testInvalidStatusIsInvalid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 3,
        ]);

        $this->assertFalse($request->isValid());
        $this->assertArrayHasKey('status', $request->getErrors());
    }

    public function testDefaultStatusIsOne(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
        ]);

        $this->assertTrue($request->isValid());
        $this->assertEquals(1, $request->status);
    }

    public function testStatusTwoIsValid(): void
    {
        $request = CreateActRequest::fromArray([
            'actName' => 'Compraventa',
            'actDescription' => 'Acto de compraventa',
            'status' => 2,
        ]);

        $this->assertTrue($request->isValid());
        $this->assertEquals(2, $request->status);
    }
}
