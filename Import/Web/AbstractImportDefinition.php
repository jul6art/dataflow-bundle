<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;

/**
 * The defaults of an import: no required field, the bundle's templates, no extra variable, no preview, the
 * configured size ceiling.
 *
 * ⚠️ The reader and the writer are NOT defaulted: the dialect is the one choice a definition must make out loud. A
 * file exported from a spreadsheet set up in French uses semicolons; a default of commas would read it as one
 * column and map nothing, with no error anywhere.
 */
abstract class AbstractImportDefinition implements ImportDefinitionInterface
{
    #[\Override]
    public function requiredFields(): array
    {
        return [];
    }

    #[\Override]
    public function templateContext(ImportContext $context): array
    {
        return [];
    }

    #[\Override]
    public function templates(): array
    {
        return [];
    }

    #[\Override]
    public function preview(): bool
    {
        return false;
    }

    #[\Override]
    public function duplicateResolver(ImportContext $context): ?DuplicateResolverInterface
    {
        return null;
    }

    #[\Override]
    public function maxBytes(): ?int
    {
        return null;
    }
}
