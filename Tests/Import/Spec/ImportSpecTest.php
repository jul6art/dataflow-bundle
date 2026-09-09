<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import\Spec;

use Jul6Art\DataflowBundle\Import\Spec\DuplicatePolicy;
use Jul6Art\DataflowBundle\Import\Spec\ImportSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportSpec::class)]
final class ImportSpecTest extends TestCase
{
    public function testTheDefaultsAreTheSafeOnes(): void
    {
        $spec = new ImportSpec('/tmp/x.csv', [0 => 'email']);

        self::assertFalse($spec->dryRun);
        self::assertTrue($spec->atomic, 'A partially committed import is the worse default.');
        self::assertSame(DuplicatePolicy::Skip, $spec->onDuplicate);
        self::assertSame(['email'], $spec->fields());
    }

    /**
     * @return iterable<string, array{array<int, string>, int, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'no column' => [[], 50, 100, 'at least one mapped column'];
        yield 'batch of zero' => [[0 => 'email'], 0, 100, 'batch size must be at least 1'];
        yield 'no row allowed' => [[0 => 'email'], 50, 0, 'row cap must be at least 1'];
        yield 'negative index' => [[-1 => 'email'], 50, 100, 'cannot be negative'];
        yield 'empty field' => [[0 => ''], 50, 100, 'mapped to an empty field'];
        yield 'two columns, one field' => [[0 => 'email', 3 => 'email'], 50, 100, 'same field'];
    }

    /**
     * @param array<int, string> $mapping
     */
    #[DataProvider('refusals')]
    public function testAnUnusableSpecIsRefusedOnConstruction(array $mapping, int $batchSize, int $maxRows, string $because): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($because, '/').'/');

        new ImportSpec('/tmp/x.csv', $mapping, batchSize: $batchSize, maxRows: $maxRows);
    }
}
