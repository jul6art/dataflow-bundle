<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Functional;

use Jul6Art\DataflowBundle\Import\HeaderInspection;
use Jul6Art\DataflowBundle\Import\ImportReport;
use PHPUnit\Framework\Attributes\CoversNothing;
use Twig\Environment;

/**
 * The shipped partials, RENDERED — not parsed, not linted.
 *
 * ## Why rendering is the assertion
 *
 * ⚠️ A lint pass proves the template compiles. What broke the screen this is extracted from was
 * not a syntax error: it was **thirty-four translation keys rendered raw**, in production, on every
 * panel. Nothing failed, because asking a translator for a key it does not have is not an error
 * anywhere — it returns the key.
 *
 * So the test loads the bundle's own English catalogue and asserts the output carries no
 * `dataflow.` substring at all. Any key the catalogue is missing shows up as itself, and that is
 * the one check no static scan can be talked out of.
 *
 * `strict_variables` is on in the test kernel, so an undefined variable is an exception rather
 * than an empty string — which is how a partial comes to render a button with no label.
 */
#[CoversNothing]
final class PartialsTest extends AbstractFunctionalTestCase
{
    public function testTheBuilderRendersEveryStepAndItsWiring(): void
    {
        $html = $this->render('@Dataflow/report/_builder.html.twig', [
            'entities' => ['App\\Entity\\Invoice' => ['label' => 'Invoices']],
            'fields_url' => '/reports/fields',
            'run_url' => '/reports/run',
            'export_url' => '/reports/export',
            'save_url' => '/reports/save',
            'load_url' => '/reports/load/{id}',
            'can_share' => true,
        ]);

        self::assertStringContainsString('data-controller="dataflow--report-builder"', $html);

        foreach (['fields', 'run', 'export', 'save'] as $endpoint) {
            self::assertStringContainsString(
                \sprintf('data-dataflow--report-builder-%s-url-value="/reports/%s"', $endpoint, $endpoint),
                $html,
            );
        }

        // ⚠️ The placeholder has to survive escaping: `{` and `}` are not HTML-escaped, but a
        // template that URL-encoded the value would ship `%7Bid%7D` and every load would 404.
        self::assertStringContainsString('load-url-value="/reports/load/{id}"', $html);

        foreach (['stepEntity', 'stepColumns', 'stepFilters', 'stepExport', 'previewBody', 'shareToggle'] as $target) {
            self::assertStringContainsString('-target="'.$target.'"', $html);
        }

        self::assertStringContainsString('Invoices', $html);
    }

    /**
     * ⚠️ The whole point of this file. A key the catalogue lacks renders as itself.
     */
    public function testNoPartialLeaksARawTranslationKey(): void
    {
        $rendered = [
            $this->render('@Dataflow/report/_builder.html.twig', [
                'entities' => [],
                'fields_url' => '/f',
                'run_url' => '/r',
                'export_url' => '/e',
                'save_url' => '/s',
                'load_url' => '/l/{id}',
                'can_share' => false,
            ]),
            $this->render('@Dataflow/import/_mapper.html.twig', [
                'inspection' => new HeaderInspection(['Email', 'e-mail', 'salesforce_id'], [], [0, 1], [2], ['name']),
                'fields' => ['name', 'email'],
            ]),
            $this->render('@Dataflow/import/_report.html.twig', ['report' => $this->reportWithEverything()]),
        ];

        foreach ($rendered as $html) {
            self::assertStringNotContainsString('dataflow.', $html, 'A translation key reached the page.');
        }
    }

    /**
     * ⚠️ Keyed by INDEX. The implementation this replaces emitted `mapping[Email]`, so a file with
     * two columns both called `email` produced one field and the second column vanished — and the
     * import then read a plausible wrong column for every row of the file.
     */
    public function testTheMapperNamesItsFieldsByColumnIndex(): void
    {
        $html = $this->render('@Dataflow/import/_mapper.html.twig', [
            'inspection' => new HeaderInspection(['Email', 'e-mail'], [], [0, 1]),
            'fields' => ['email'],
        ]);

        self::assertStringContainsString('name="mapping[0]"', $html);
        self::assertStringContainsString('name="mapping[1]"', $html);
        self::assertStringNotContainsString('mapping[Email]', $html);
    }

    public function testTheMapperPreselectsAnUnambiguousSuggestion(): void
    {
        $html = $this->render('@Dataflow/import/_mapper.html.twig', [
            'inspection' => new HeaderInspection(['E-Mail'], [0 => 'email']),
            'fields' => ['name', 'email'],
        ]);

        self::assertStringContainsString('<option value="email" selected>', $html);
        self::assertStringContainsString('<option value="name" >', $html);
    }

    /**
     * ⚠️ A dry run says so on the page. Without it a panel announcing "1 240 imported" after a
     * preview is why somebody runs the real import a second time.
     */
    public function testADryRunIsAnnouncedAndARealRunIsNot(): void
    {
        $dry = $this->render('@Dataflow/import/_report.html.twig', ['report' => new ImportReport(dryRun: true)]);
        $real = $this->render('@Dataflow/import/_report.html.twig', ['report' => new ImportReport()]);

        self::assertStringContainsString('dry run', $dry);
        self::assertStringNotContainsString('dry run', $real);
    }

    /**
     * ⚠️ And the truncation too: showing only the sample implies the file had exactly a hundred
     * problems, when the count says otherwise.
     */
    public function testTheTruncationOfTheErrorSampleIsAnnounced(): void
    {
        $html = $this->render('@Dataflow/import/_report.html.twig', ['report' => $this->reportWithEverything()]);

        self::assertStringContainsString('Only the first', $html);
        self::assertStringContainsString('320', $html, 'The exact count, not the sample size.');
    }

    private function reportWithEverything(): ImportReport
    {
        $report = new ImportReport();
        $report->recordImported();
        $report->recordSkipped();

        for ($i = 1; $i <= 320; ++$i) {
            $report->recordError($i, 'dataflow.import.error.duplicate');
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): string
    {
        $twig = $this->boot(withTwig: true)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render($template, $context);
    }
}
