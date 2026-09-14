<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import;

use Jul6Art\DataflowBundle\Import\ErrorSinkInterface;
use Jul6Art\DataflowBundle\Import\ImportReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportReport::class)]
final class ImportReportTest extends TestCase
{
    public function testTheFourCountersAreDisjointAndSumToTheTotal(): void
    {
        $report = new ImportReport();

        $report->recordImported();
        $report->recordImported();
        $report->recordUpdated();
        $report->recordSkipped();
        $report->recordError(7, 'dataflow.import.error.duplicate');

        self::assertSame(2, $report->imported());
        self::assertSame(1, $report->updated());
        self::assertSame(1, $report->skipped());
        self::assertSame(1, $report->errorCount());
        self::assertSame(5, $report->total());
    }

    /**
     * ⚠️ `updated()` is its own counter, never folded into `imported()` — the two answer different
     * questions for an operator reading an upsert's result: how many accounts are new, against how
     * many already existed and changed. A report where every match still counted as "imported"
     * would make that number ambiguous the one time it matters.
     */
    public function testUpdatedIsZeroByDefaultAndNeverConflatedWithImported(): void
    {
        $report = new ImportReport();

        self::assertSame(0, $report->updated());

        $report->recordImported();

        self::assertSame(1, $report->imported());
        self::assertSame(0, $report->updated(), 'A creation must never bump the update counter.');
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

    /**
     * ⚠️ The whole point of the sink: it sees EVERY error, past the hundred {@see self::errors()}
     * keeps. Fed alone into the cap-proving test above, a sink that only received the retained
     * sample would pass unnoticed — this test's count must exceed the cap to mean anything.
     */
    public function testEveryErrorReachesTheSinkEvenPastTheCap(): void
    {
        $sink = new class implements ErrorSinkInterface {
            /** @var list<array{int, string}> */
            public array $seen = [];

            #[\Override]
            public function record(int $record, string $message): void
            {
                $this->seen[] = [$record, $message];
            }
        };

        $report = new ImportReport(errorSink: $sink);

        for ($i = 1; $i <= ImportReport::MAX_RETAINED_ERRORS + 5; ++$i) {
            $report->recordError($i, 'dataflow.import.error.duplicate');
        }

        self::assertCount(ImportReport::MAX_RETAINED_ERRORS + 5, $sink->seen);
        self::assertSame([ImportReport::MAX_RETAINED_ERRORS + 5, 'dataflow.import.error.duplicate'], array_last($sink->seen));
    }

    public function testNoSinkMeansNoCallAtAll(): void
    {
        // No sink given: recordError() must not assume one exists.
        $report = new ImportReport();

        $report->recordError(1, 'dataflow.import.error.duplicate');

        self::assertSame(1, $report->errorCount());
    }
}
