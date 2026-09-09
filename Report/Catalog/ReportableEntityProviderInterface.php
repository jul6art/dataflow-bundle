<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Catalog;

/**
 * Declares which entities a module opens to reporting.
 *
 * Each business module of an application implements one, and the DI tag aggregates them: the
 * bundle owns the CONTRACT and the registry, never the content. A catalogue of reportable entities
 * is policy — it says what this product lets people export — and policy does not ship in a vendor
 * package.
 *
 * ```php
 * final class CrmReportableEntityProvider implements ReportableEntityProviderInterface
 * {
 *     public function entities(): array
 *     {
 *         return [
 *             Contact::class => new ReportableEntity('report.entity.contact', 'crm:contact:read', 'crm.manage'),
 *             Deal::class    => new ReportableEntity('report.entity.deal', 'crm:deal:read', 'crm.manage'),
 *         ];
 *     }
 * }
 * ```
 *
 * Tag it `dataflow.report.entity_provider`, or let a `_instanceof` block in `services.yaml` do it.
 *
 * ⚠️ **`#[AsTaggedItem]` alone does NOT add the tag** — it only indexes an item inside a tagged
 * iterator that already exists. An application that relies on it and nothing else gets an empty
 * iterator and a « format not supported » style failure at run time. Use `_instanceof`.
 */
interface ReportableEntityProviderInterface
{
    /**
     * @return array<class-string, ReportableEntity>
     */
    public function entities(): array;
}
