<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Report\Format;

/**
 * The three renderings a report column asks for — same shape as `jul6art/core-bundle`'s
 * `NumberFormatter`, deliberately, so a consumer that already has one binds it here and gets its
 * application's own convention for free.
 *
 * ## Why a port, and not a hard dependency on `core-bundle`
 *
 * ⚠️ This bundle requires neither `api-bundle` nor `api-platform` on the same principle: it does
 * not assume every consumer has made the same choices it has. `jul6art/core-bundle` sits in
 * `require-dev` here — used by this bundle's OWN tests, never by its production code — for exactly
 * the reason `LimitsProviderInterface` and `ExportAuditorInterface` are ports instead of concrete
 * types: an application that has not installed it still gets a working bundle, with
 * {@see PassthroughNumberFormatter} bound by default.
 *
 * ⚠️ **A consumer with `core-bundle` binds ITS `NumberFormatter` to this interface** (they already
 * share the same three method names and signatures) and every report column that opts into
 * `ColumnFormat::number()`/`money()`/`percent()` renders in the application's own configured
 * convention — the whole point of lot 2.6, without this bundle ever importing the class.
 */
interface NumberFormatterInterface
{
    public function format(int|float|string|null $value, ?int $decimals = null): string;

    public function formatMoney(int|float|string|null $value, string $currency, ?int $decimals = null): string;

    public function formatPercent(int|float|string|null $value, int $decimals = 0): string;
}
