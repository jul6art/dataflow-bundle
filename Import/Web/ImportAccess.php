<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

/**
 * One attribute the actor must be granted to use an import, with its subject when it has one: `USER_CREATE`, or
 * `INDEX_EDIT` on the index the constituents belong to.
 */
final readonly class ImportAccess
{
    public function __construct(
        public string $attribute,
        public mixed $subject = null,
    ) {
    }
}
