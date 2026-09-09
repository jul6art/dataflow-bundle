<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Port;

use Jul6Art\DataflowBundle\Port\ExportRecord;
use Jul6Art\DataflowBundle\Port\NullExportAuditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportRecord::class)]
#[CoversClass(NullExportAuditor::class)]
final class ExportAuditorTest extends TestCase
{
    public function testARecordCarriesWhatAnAuditTrailNeeds(): void
    {
        $at = new \DateTimeImmutable('2026-09-09 11:00:00');

        $record = new ExportRecord('Monthly invoices', 'xlsx', 4812, actorId: 7, tenantId: 'acme', at: $at);

        self::assertSame('Monthly invoices', $record->subject);
        self::assertSame('xlsx', $record->format);
        self::assertSame(4812, $record->rows);
        self::assertSame(7, $record->actorId);
        self::assertSame('acme', $record->tenantId);
        self::assertSame($at, $record->at);
    }

    /**
     * ⚠️ Both nullable, and both for a real case: a scheduled export has no actor, and a
     * single-tenant application has no tenant. A record demanding either would be unusable in two
     * of the three target applications.
     */
    public function testAnActorAndATenantAreBothOptional(): void
    {
        $record = new ExportRecord('nightly', 'csv', 0);

        self::assertNull($record->actorId);
        self::assertNull($record->tenantId);
    }

    /**
     * The documented default: exports are not journalled, and asking for that must not throw. A
     * null object rather than a nullable argument, so no call site has to remember a `?->`.
     */
    public function testTheDefaultAuditorRecordsNothingAndSaysNothing(): void
    {
        $auditor = new NullExportAuditor();

        $auditor->record(new ExportRecord('anything', 'csv', 1));

        self::expectNotToPerformAssertions();
    }
}
