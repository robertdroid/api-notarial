<?php

declare(strict_types=1);

namespace Tests\Module\Deed\Service;

use App\Module\Act\Entity\Act;
use App\Module\Act\Repository\ActRepository;
use App\Module\Deed\Exception\DeedError;
use App\Module\Deed\Exception\DeedException;
use App\Module\Deed\Service\DeedReferenceValidator;
use App\Module\DeedType\Entity\DeedType;
use App\Module\DeedType\Repository\DeedTypeRepository;
use App\Module\Subdivision\Entity\Subdivision;
use App\Module\Subdivision\Repository\SubdivisionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeedReferenceValidatorTest extends TestCase
{
    private const NOTARY = 1;

    private ActRepository&MockObject $acts;
    private DeedTypeRepository&MockObject $types;
    private SubdivisionRepository&MockObject $subdivisions;
    private DeedReferenceValidator $validator;

    protected function setUp(): void
    {
        $this->acts = $this->createMock(ActRepository::class);
        $this->types = $this->createMock(DeedTypeRepository::class);
        $this->subdivisions = $this->createMock(SubdivisionRepository::class);
        $this->validator = new DeedReferenceValidator($this->acts, $this->types, $this->subdivisions);
    }

    private function type(int $max = 2, int $enableSubdivisions = 0, int $status = 1): DeedType
    {
        return new DeedType(3, self::NOTARY, 'Type', '', $max, $enableSubdivisions, 1, new \DateTime(), $status);
    }

    private function assertDeedError(DeedError $expected, callable $operation): void
    {
        try {
            $operation();
            $this->fail('DeedException expected');
        } catch (DeedException $e) {
            $this->assertSame($expected, $e->error);
        }
    }

    // ---- act -------------------------------------------------------------

    public function testActiveActPasses(): void
    {
        $this->acts->method('findByIdAndNotary')->with(5, self::NOTARY)
            ->willReturn(new Act(5, self::NOTARY, 'A', '', 1, new \DateTime(), 1));

        $this->validator->requireActiveAct(5, self::NOTARY);

        $this->addToAssertionCount(1);
    }

    public function testMissingActIsUnavailable(): void
    {
        $this->acts->method('findByIdAndNotary')->willReturn(null);

        $this->assertDeedError(DeedError::ActUnavailable, fn () => $this->validator->requireActiveAct(5, self::NOTARY));
    }

    public function testInactiveActIsUnavailable(): void
    {
        $this->acts->method('findByIdAndNotary')
            ->willReturn(new Act(5, self::NOTARY, 'A', '', 1, new \DateTime(), 2));

        $this->assertDeedError(DeedError::ActUnavailable, fn () => $this->validator->requireActiveAct(5, self::NOTARY));
    }

    // ---- deed type -------------------------------------------------------

    public function testActiveTypeIsReturned(): void
    {
        $type = $this->type();
        $this->types->method('findByIdAndNotary')->with(3, self::NOTARY)->willReturn($type);

        $this->assertSame($type, $this->validator->requireActiveDeedType(3, self::NOTARY));
    }

    public function testMissingTypeIsUnavailable(): void
    {
        $this->types->method('findByIdAndNotary')->willReturn(null);

        $this->assertDeedError(
            DeedError::DeedTypeUnavailable,
            fn () => $this->validator->requireActiveDeedType(3, self::NOTARY)
        );
    }

    public function testInactiveTypeIsUnavailable(): void
    {
        $this->types->method('findByIdAndNotary')->willReturn($this->type(status: 2));

        $this->assertDeedError(
            DeedError::DeedTypeUnavailable,
            fn () => $this->validator->requireActiveDeedType(3, self::NOTARY)
        );
    }

    public function testFindDeedTypeDoesNotCheckStatus(): void
    {
        $inactive = $this->type(status: 2);
        $this->types->method('findByIdAndNotary')->willReturn($inactive);

        $this->assertSame($inactive, $this->validator->findDeedType(3, self::NOTARY));
    }

    // ---- subdivision -----------------------------------------------------

    public function testActiveSubdivisionPasses(): void
    {
        $this->subdivisions->method('findByIdAndNotary')->with(2, self::NOTARY)
            ->willReturn(new Subdivision(2, self::NOTARY, 'S', 1, new \DateTime(), 1));

        $this->validator->requireActiveSubdivision(2, self::NOTARY);

        $this->addToAssertionCount(1);
    }

    public function testMissingSubdivisionIsUnavailable(): void
    {
        $this->subdivisions->method('findByIdAndNotary')->willReturn(null);

        $this->assertDeedError(
            DeedError::SubdivisionUnavailable,
            fn () => $this->validator->requireActiveSubdivision(2, self::NOTARY)
        );
    }

    public function testInactiveSubdivisionIsUnavailable(): void
    {
        $this->subdivisions->method('findByIdAndNotary')
            ->willReturn(new Subdivision(2, self::NOTARY, 'S', 1, new \DateTime(), 2));

        $this->assertDeedError(
            DeedError::SubdivisionUnavailable,
            fn () => $this->validator->requireActiveSubdivision(2, self::NOTARY)
        );
    }

    // ---- subdivision allowed ---------------------------------------------

    public function testSubdivisionNotAllowedWhenTypeDisablesThem(): void
    {
        try {
            $this->validator->assertSubdivisionAllowed($this->type(enableSubdivisions: 0), 2);
            $this->fail('DeedException expected');
        } catch (DeedException $e) {
            $this->assertSame(DeedError::SubdivisionNotAllowed, $e->error);
            $this->assertSame(['deedTypeId' => 3], $e->details);
        }
    }

    public function testSubdivisionAllowedWhenTypeEnablesThem(): void
    {
        $this->validator->assertSubdivisionAllowed($this->type(enableSubdivisions: 1), 2);

        $this->addToAssertionCount(1);
    }

    public function testNoSubdivisionIsAlwaysAllowed(): void
    {
        $this->validator->assertSubdivisionAllowed($this->type(enableSubdivisions: 0), null);

        $this->addToAssertionCount(1);
    }

    // ---- registration limit ----------------------------------------------

    public function testCountEqualToLimitPasses(): void
    {
        $this->validator->assertRegistrationLimit($this->type(max: 2), 2);

        $this->addToAssertionCount(1);
    }

    public function testCountAboveLimitFailsWithDetails(): void
    {
        try {
            $this->validator->assertRegistrationLimit($this->type(max: 2), 3);
            $this->fail('DeedException expected');
        } catch (DeedException $e) {
            $this->assertSame(DeedError::RegistrationLimitExceeded, $e->error);
            $this->assertSame(['maxRegistrations' => 2, 'currentRegistrations' => 3], $e->details);
            $this->assertSame('Deed type allows at most 2 registrations', $e->getMessage());
        }
    }

    public function testZeroOrNegativeLimitMeansNoLimit(): void
    {
        $this->validator->assertRegistrationLimit($this->type(max: 0), 99);
        $this->validator->assertRegistrationLimit($this->type(max: -1), 99);

        $this->addToAssertionCount(1);
    }

    public function testMissingTypeMeansNoLimit(): void
    {
        $this->validator->assertRegistrationLimit(null, 99);

        $this->addToAssertionCount(1);
    }
}
