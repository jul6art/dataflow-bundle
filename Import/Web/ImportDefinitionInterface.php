<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

use Jul6Art\DataflowBundle\Import\DuplicateResolverInterface;
use Jul6Art\DataflowBundle\Io\Reader\TabularReaderInterface;
use Jul6Art\DataflowBundle\Io\TabularWriterInterface;

/**
 * One import the screens of {@see ImportController} can run: what the file may carry, how it is read and written,
 * who may run it, and where its screens live (ADR-0001).
 *
 * A definition is a service tagged `dataflow.import` under a key (`user`, `customer`…) — by
 * {@see AsImportDefinition} on an autoconfigured service, or by the tag itself. Extend
 * {@see AbstractImportDefinition} for the defaults; everything here is what a screen needs and nothing it can guess.
 */
interface ImportDefinitionInterface
{
    /**
     * The field keys a column can be mapped to, in the order the mapping screen offers them.
     *
     * @return list<string>
     */
    public function fields(): array;

    /**
     * The fields the run refuses to start without: a mapping that leaves one out goes back to the mapping screen
     * with the reason, before a single row is read.
     *
     * @return list<string>
     */
    public function requiredFields(): array;

    /** How the file is read — its dialect, for a CSV. */
    public function reader(): TabularReaderInterface;

    /** How the downloadable model is written: the same dialect the reader expects, or the model comes back wrong. */
    public function writer(): TabularWriterInterface;

    /**
     * Runs the import.
     *
     * @param array<int, string> $mapping column position → field key, every field at most once
     *
     * @throws \DomainException a refusal of the run, its message a translation key: back to the mapping screen
     * @throws \Jul6Art\DataflowBundle\Exception\ImportFailedException a batch that could not be written
     */
    public function import(string $filePath, array $mapping, ImportContext $context): ImportResult;

    /**
     * Every attribute the actor must be granted, checked on each of the four actions.
     *
     * @return list<ImportAccess>
     */
    public function access(ImportContext $context): array;

    /** The catalogue of the screens' own words: titles, field labels, the definition's error keys. */
    public function translationDomain(): string;

    /**
     * The prefix of the screens' keys, in {@see translationDomain()}: `<prefix>.new.title`, `<prefix>.map.title`,
     * `<prefix>.result.title` (and their `.subtitle`), `<prefix>.field.<field>` for a field's label.
     */
    public function translationPrefix(): string;

    /** The names of this import's routes, which the application declares. */
    public function routes(): ImportRoutes;

    /** Where "back" and "see what was imported" lead. */
    public function returnTo(ImportContext $context): ImportLink;

    /**
     * Variables every screen of this import receives besides the bundle's: the object the import belongs to, say.
     *
     * @return array<string, mixed>
     */
    public function templateContext(ImportContext $context): array;

    /**
     * Templates replacing the bundle's, by screen: `upload`, `mapping`, `report`.
     *
     * @return array<string, string>
     */
    public function templates(): array;

    /** Whether the mapping screen shows the first rows read with the mapping, and what the run would create or update. */
    public function preview(): bool;

    /** Finds the rows that already exist, for the preview's count; `null` counts every row as new. */
    public function duplicateResolver(ImportContext $context): ?DuplicateResolverInterface;

    /** The largest file accepted, in bytes; `null` keeps `dataflow.import.max_bytes`. */
    public function maxBytes(): ?int;
}
