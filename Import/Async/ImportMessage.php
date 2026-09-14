<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Async;

use Jul6Art\DataflowBundle\Import\Spec\DuplicatePolicy;
use Jul6Art\DataflowBundle\Import\Spec\ImportSpec;

/**
 * Carries everything {@see ImportMessageHandler} needs to run one import off the request thread.
 *
 * ## Why this is not an `ImportSpec`
 *
 * ⚠️ `ImportSpec` names its reader and its mapper as OBJECTS, handed to `ImportRunner::run()`
 * directly — right for a synchronous call, where the caller already holds them. A message crossing
 * a real transport is serialised to text and reconstructed later, in a different process: an object
 * holding an `EntityManager`, a `Connection`, or a tenant entity does not survive that. This class
 * carries IDENTIFIERS instead — `mapperId`, `resolverId`, and a plain-scalar `context` — and
 * {@see ImportMapperFactoryInterface} turns them back into real objects inside the worker, where a
 * fresh `EntityManager` is exactly what is needed.
 *
 * ⚠️ **The reader is not named at all.** {@see ImportMessageHandler} resolves it from the file's
 * CONTENT, the same tagged-iterator mechanism a controller uses (lot 2.1) — naming a reader here
 * would let a message and its file quietly disagree about what the file is.
 *
 * ⚠️ **`importId` is the application's, not this bundle's.** It is the correlation key
 * {@see ImportProgressStoreInterface} keys its rows on; a UUID an application already generates for
 * its own upload record is the usual choice, not something this class invents.
 */
final readonly class ImportMessage
{
    /**
     * @param array<int, string>                        $mapping column index → field key
     * @param array<string, bool|int|float|string|null> $context passed to
     *                                                            {@see ImportMapperFactoryInterface}
     *                                                            unmodified
     */
    public function __construct(
        public string $importId,
        public string $filePath,
        public array $mapping,
        public string $mapperId,
        public ?string $resolverId = null,
        public array $context = [],
        public DuplicatePolicy $onDuplicate = DuplicatePolicy::Skip,
        public int $batchSize = ImportSpec::DEFAULT_BATCH_SIZE,
        public int $maxRows = ImportSpec::DEFAULT_MAX_ROWS,
        public bool $atomic = true,
    ) {
    }
}
