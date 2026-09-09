<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Port;

/**
 * The ceilings one run is bounded by.
 *
 * ## Why one object and not seven getters
 *
 * An application that keeps these per tenant reads them from a settings table, and reading a table
 * seven times to answer seven questions is how a limits provider becomes the slowest thing on the
 * page. One call, one object.
 *
 * ## The defaults are not invented
 *
 * ⚠️ Every value below is the number the reference application already applies — `report.run
 * .default_limit` 1000, `report.export.max_rows` 50 000, `report.export.rate_limit_per_hour` 30,
 * the framework bucket 10 000, `crm.import.max_rows_per_file` 10 000, `crm.import
 * .rate_limit_per_hour` 5, `report.field.max_depth` 2. Adopting this bundle therefore changes no
 * behaviour: the migration is a deletion, and a deletion that also changed a ceiling would be
 * impossible to tell apart from a regression.
 *
 * ## `exportRowsPerHour` is the fix for a duplicated literal
 *
 * ⚠️ It exists because the reference application wrote `10000` twice — once in
 * `config/packages/rate_limiter.yaml` as the bucket size, once in PHP as
 * `$consumed = 10000 - $token->getRemainingTokens()` — with nothing linking them. Changing the YAML
 * made the arithmetic wrong, silently. The bundle exposes every value as a container parameter
 * (`%dataflow.limits.export_rows_per_hour%`) so that the YAML and the code can read the SAME one.
 */
final readonly class Limits
{
    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public int $reportRowLimit = 1000,
        public int $exportRowLimit = 50000,
        public int $exportsPerHour = 30,
        public int $exportRowsPerHour = 10000,
        public int $importRowLimit = 10000,
        public int $importsPerHour = 5,
        public int $fieldMaxDepth = 2,
    ) {
        foreach ([
            'reportRowLimit' => $reportRowLimit,
            'exportRowLimit' => $exportRowLimit,
            'exportsPerHour' => $exportsPerHour,
            'exportRowsPerHour' => $exportRowsPerHour,
            'importRowLimit' => $importRowLimit,
            'importsPerHour' => $importsPerHour,
        ] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be at least 1, got %d.', $name, $value));
            }
        }

        // ⚠️ Zero IS meaningful here, unlike the others: a depth of zero offers the root entity's
        // own columns and traverses nothing, which is a legitimate configuration for an
        // application that wants flat reports only.
        if ($fieldMaxDepth < 0) {
            throw new \InvalidArgumentException(\sprintf('"fieldMaxDepth" cannot be negative, got %d.', $fieldMaxDepth));
        }
    }
}
