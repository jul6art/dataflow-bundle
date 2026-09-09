<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Port;

use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\DataflowBundle\Port\ConfiguredLimitsProvider;
use Jul6Art\DataflowBundle\Port\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Limits::class)]
#[CoversClass(ConfiguredLimitsProvider::class)]
final class LimitsTest extends TestCase
{
    /**
     * ⚠️ These are not chosen numbers, they are the reference application's current ones. Adopting
     * the bundle has to change no behaviour, and a migration that also moved a ceiling would be
     * impossible to tell apart from a regression.
     */
    public function testTheDefaultsAreTheReferenceApplicationsCurrentValues(): void
    {
        $limits = new Limits();

        self::assertSame(1000, $limits->reportRowLimit, 'report.run.default_limit');
        self::assertSame(50000, $limits->exportRowLimit, 'report.export.max_rows');
        self::assertSame(30, $limits->exportsPerHour, 'report.export.rate_limit_per_hour');
        self::assertSame(10000, $limits->exportRowsPerHour, 'the rate-limiter bucket');
        self::assertSame(10000, $limits->importRowLimit, 'crm.import.max_rows_per_file');
        self::assertSame(5, $limits->importsPerHour, 'crm.import.rate_limit_per_hour');
        self::assertSame(2, $limits->fieldMaxDepth, 'report.field.max_depth');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unusableValues(): iterable
    {
        yield 'no report row' => ['reportRowLimit', 0];
        yield 'no export row' => ['exportRowLimit', 0];
        yield 'no export' => ['exportsPerHour', 0];
        yield 'no row budget' => ['exportRowsPerHour', 0];
        yield 'no import row' => ['importRowLimit', 0];
        yield 'no import' => ['importsPerHour', 0];
        yield 'negative' => ['exportRowLimit', -1];
    }

    #[DataProvider('unusableValues')]
    public function testACeilingOfZeroIsRefusedByName(string $name, int $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"'.$name.'"/');

        new Limits(...[$name => $value]);
    }

    /**
     * ⚠️ Zero depth is the one legitimate zero: it offers the root entity's own columns and
     * traverses nothing, which is a real choice for an application that wants flat reports.
     */
    public function testADepthOfZeroIsAllowedAndANegativeOneIsNot(): void
    {
        self::assertSame(0, new Limits(fieldMaxDepth: 0)->fieldMaxDepth);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"fieldMaxDepth"/');

        new Limits(fieldMaxDepth: -1);
    }

    /**
     * ⚠️ The tenant is IGNORED, and asserting that is the point: two of the three target
     * applications have no tenant at all, so the default cannot be per-tenant — inventing a
     * settings table inside a bundle is the rule this ecosystem states as "a bundle interprets, a
     * project persists". An application whose ceilings do vary binds the interface instead.
     */
    public function testTheConfiguredProviderIgnoresTheTenant(): void
    {
        $limits = new Limits(exportRowLimit: 123);
        $provider = new ConfiguredLimitsProvider($limits);

        $tenant = self::createStub(AclTenantInterface::class);
        $tenant->method('getId')->willReturn(42);
        $tenant->method('getSlug')->willReturn('acme');

        self::assertSame($limits, $provider->limitsFor());
        self::assertSame($limits, $provider->limitsFor($tenant));
    }
}
