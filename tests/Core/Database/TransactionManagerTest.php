<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use App\Core\Database\TransactionManager;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TransactionManagerTest extends TestCase
{
    private PDO&MockObject $pdo;
    private TransactionManager $manager;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->manager = new TransactionManager($this->pdo);
    }

    public function testCommitsAndReturnsOperationResult(): void
    {
        $this->pdo->method('inTransaction')->willReturn(false);
        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->once())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');

        $result = $this->manager->transactional(fn (): int => 42);

        $this->assertSame(42, $result);
    }

    public function testRollsBackAndRethrowsTheSameException(): void
    {
        $error = new \RuntimeException('boom');
        $this->pdo->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->once())->method('rollBack');

        try {
            $this->manager->transactional(function () use ($error): void {
                throw $error;
            });
            $this->fail('Expected exception');
        } catch (\Throwable $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testRollsBackOnErrorsThatAreNotExceptions(): void
    {
        $this->pdo->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $this->pdo->expects($this->once())->method('rollBack');

        $this->expectException(\TypeError::class);

        $this->manager->transactional(fn () => throw new \TypeError('bad'));
    }

    public function testDoesNotNestWhenATransactionIsAlreadyOpen(): void
    {
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->expects($this->never())->method('beginTransaction');
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');

        $this->assertSame('ok', $this->manager->transactional(fn (): string => 'ok'));
    }
}
