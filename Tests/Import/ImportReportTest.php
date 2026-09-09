<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import;

use Jul6Art\DataflowBundle\Import\ImportReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportReport::class)]
final class ImportReportTest extends TestCase
{
    public function testTheThreeCountersAreDisjointAndSumToTheTotal(): void
    {
        $report = new ImportReport();

        $report->recordImported();
        $report->recordImported();
        $report->recordSkipped();
        $report->recordError(7, 'dataflow.import.error.duplicate');

        self::assertSame(2, $report->imported());
        self::assertSame(1, $report->skipped());
        self::assertSame(1, $report->errorCount());
        self::assertSame(4, $report->total());
    }

    /**
     * ⚠️ A file read with the wrong delimiter fails on every row. Keeping 50 000 error entries in
     * memory to render a page nobody reads is the defect this cap exists for — and the COUNT stays
     * exact, so no screen can imply the file had exactly a hundred problems.
     */
    public function testTheErrorListIsCappedWhileTheCountIsNot(): void
    {
        $report = new ImportReport();

        for ($i = 1; $i <= ImportReport::MAX_RETAINED_ERRORS + 250; ++$i) {
            $report->recordError($i, 'dataflow.import.error.duplicate');
        }

        self::assertCount(ImportReport::MAX_RETAINED_ERRORS, $report->errors());
        self::assertSame(ImportReport::MAX_RETAINED_ERRORS + 250, $report->errorCount());
        self::assertTrue($report->errorsWereTruncated());
    }

    public function testTheRetainedErrorsAreTheFirstOnesInOrder(): void
    {
        $report = new ImportReport();

        $report->recordError(4, 'first');
        $report->recordError(9, 'second');

        self::assertSame([
            ['record' => 4, 'message' => 'first'],
            ['record' => 9, 'message' => 'second'],
        ], $report->errors());
        self::assertFalse($report->errorsWereTruncated());
    }

    public function testADryRunSaysSoSoAScreenCanWordItsCountCorrectly(): void
    {
        self::assertTrue(new ImportReport(dryRun: true)->isDryRun());
        self::assertFalse(new ImportReport()->isDryRun());
    }
}
