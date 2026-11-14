<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Jul6Art\DataflowBundle\Import\ImportReport;

/**
 * What a run produced: its report, and what else the report screen should say — the invitations already pending,
 * the members already there.
 */
final readonly class ImportResult
{
    /**
     * @param array<string, mixed> $variables handed to the report screen next to `report`
     */
    public function __construct(
        public ImportReport $report,
        public array $variables = [],
    ) {
    }
}
