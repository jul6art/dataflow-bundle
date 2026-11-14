<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Tests\Import\Web;

use Jul6Art\DataflowBundle\Import\Web\ImportStartingEvent;
use Jul6Art\DataflowBundle\Tests\Import\Web\Fixtures\FruitImportDefinition;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The three screens of an import and its model, on a definition without a database (ADR-0001).
 */
final class ImportScreensTest extends WebTestCase
{
    private KernelBrowser $client;

    /** The top of the exception-handler stack before the kernel booted: what tearDown unwinds to. */
    private mixed $handlerBeforeBoot = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->handlerBeforeBoot = self::currentExceptionHandler();
        $this->client = self::createClient();
        // One kernel for the scenario: the session and the definitions' memory survive each request.
        $this->client->disableReboot();
        $this->client->loginUser(new InMemoryUser('importer', 'x', ['ROLE_IMPORTER']), 'main');

        $directory = $this->kernel()->importDirectory();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * ⚠️ With the lowest dependencies, the kernel leaves its exception handlers behind, which this suite's
     * `failOnRisky` turns into failures: unwound here, as AbstractFunctionalTestCase does.
     */
    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        for ($i = 0; $i < 16 && self::currentExceptionHandler() !== $this->handlerBeforeBoot; ++$i) {
            restore_exception_handler();
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ScreensKernel();
    }

    public function testTheUploadScreenAsksForTheFileAndOffersTheModel(): void
    {
        $crawler = $this->client->request('GET', '/fruit/import');

        self::assertResponseIsSuccessful();
        self::assertSame('Import fruits', $crawler->filter('h1')->text());
        self::assertSame('Import fruits · Orchard', $crawler->filter('title')->text(), 'The screen\'s title, then the layout\'s: the application\'s name.');
        self::assertCount(1, $crawler->filter('input[type=file][name=file]'));
        self::assertCount(1, $crawler->filter('[data-dataflow-template]'));
        self::assertCount(1, $crawler->filter('input[name=_token]'));
        self::assertSame('A CSV of fruits from Normandy', $crawler->filter('h1 + p')->text(), 'The object the import belongs to, named in its subtitle.');
        self::assertSame('Dates as YYYY-MM-DD.', $crawler->filter('[data-dataflow-hint]')->text(), 'The import\'s own advice.');
    }

    public function testAnActorWithoutTheImportsRightIsRefusedOnEveryAction(): void
    {
        $this->client->loginUser(new InMemoryUser('visitor', 'x', ['ROLE_USER']), 'main');

        foreach ([['GET', '/fruit/import'], ['GET', '/fruit/import/mapping'], ['POST', '/fruit/import/run'], ['GET', '/fruit/import/template']] as [$method, $uri]) {
            $this->client->request($method, $uri);
            self::assertResponseStatusCodeSame(403, $method.' '.$uri);
        }
    }

    public function testAFileTooLargeForTheImportIsRefusedOnItsScreen(): void
    {
        $crawler = $this->upload('/small/import', "name;colour\n".str_repeat("apple;red\n", 20));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The file is too large.', $crawler->filter('[data-dataflow-error]')->text());
        self::assertSame([], glob($this->kernel()->importDirectory().'/*') ?: [], 'Nothing is stored.');
    }

    public function testABinarySpreadsheetIsRefusedWithItsOwnMessage(): void
    {
        $crawler = $this->upload('/fruit/import', "PK\x03\x04".str_repeat("\x00", 64), 'fruits.xlsx');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This is a spreadsheet', $crawler->filter('[data-dataflow-error]')->text());
    }

    public function testAFileThatIsNotTextIsRefused(): void
    {
        $crawler = $this->upload('/fruit/import', "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64), 'fruits.png');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('This file is not a CSV file.', $crawler->filter('[data-dataflow-error]')->text());
    }

    public function testAnUploadWithoutItsTokenIsRefused(): void
    {
        $this->client->request('POST', '/fruit/import', ['_token' => 'forged'], ['file' => $this->file("name\napple\n")]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAStoredFileIsNamedAtRandomAndOnlyItsNameIsInTheSession(): void
    {
        $this->upload('/fruit/import', "name;colour\napple;red\n");

        self::assertResponseRedirects('/fruit/import/mapping', 303);
        $stored = glob($this->kernel()->importDirectory().'/*') ?: [];
        self::assertCount(1, $stored);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.csv$/', basename($stored[0]));
        self::assertSame(basename($stored[0]), $this->client->getRequest()->getSession()->get('dataflow.import.fruit'));
    }

    public function testTheMappingScreenOffersEveryFieldWithItsLabelAndPreselectsTheRecognisedOnes(): void
    {
        $this->upload('/fruit/import', "Name;Colour;Shape\napple;red;round\n");
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('Map the columns', $crawler->filter('h1')->text());
        self::assertSame('name', $crawler->filter('select[name="mapping[0]"] option[selected]')->attr('value'));
        self::assertSame('colour', $crawler->filter('select[name="mapping[1]"] option[selected]')->attr('value'));
        self::assertCount(0, $crawler->filter('select[name="mapping[2]"] option[selected]'), 'An unknown column is ignored unless chosen.');
        self::assertSame('Fruit name *', trim($crawler->filter('select[name="mapping[0]"] option[value=name]')->text()), 'The label, and the mark of a required field.');
        self::assertSame('origin', trim($crawler->filter('select[name="mapping[0]"] option[value=origin]')->text()), 'A field without a label keeps its key.');
        self::assertSame('ui--select2', $crawler->filter('select[name="mapping[0]"]')->attr('data-controller'), 'A select of these applications is a Select2.');
        self::assertCount(0, $crawler->filter('[data-dataflow-preview]'), 'No preview unless the import asks for it.');
    }

    public function testTheRunImportsByColumnPositionAndReportsWhatItDid(): void
    {
        $this->upload('/fruit/import', "name;colour\napple;red\npear;green\n");
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('form')->form(['mapping[0]' => 'name', 'mapping[1]' => 'colour']));

        self::assertResponseIsSuccessful();
        $report = $this->client->getCrawler();
        self::assertSame('Fruits imported', $report->filter('h1')->text());
        self::assertSame([['name' => 'apple', 'colour' => 'red'], ['name' => 'pear', 'colour' => 'green']], $this->definition('fruit')->imported);
        self::assertSame([], glob($this->kernel()->importDirectory().'/*') ?: [], 'The file goes with the report.');
        self::assertNull($this->client->getRequest()->getSession()->get('dataflow.import.fruit'));
    }

    public function testAFileIsConsumedOnce(): void
    {
        $this->upload('/fruit/import', "name\napple\n");
        $form = $this->client->followRedirect()->filter('form')->form(['mapping[0]' => 'name']);
        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The uploaded file is no longer available. Upload it again.', $crawler->filter('[data-dataflow-error]')->text());
        self::assertCount(1, $this->definition('fruit')->imported, 'The second submission imported nothing.');
    }

    public function testAForgedNameInTheSessionDesignatesNoFile(): void
    {
        file_put_contents($this->kernel()->importDirectory().'/../secret.csv', "name\nstolen\n");

        foreach (['../secret.csv', '/etc/passwd', str_repeat('a', 32).'.csv'] as $forged) {
            $this->upload('/fruit/import', "name\napple\n");
            $this->client->getRequest()->getSession()->set('dataflow.import.fruit', $forged);
            $this->client->getRequest()->getSession()->save();

            $this->client->request('GET', '/fruit/import/mapping');
            self::assertResponseRedirects('/fruit/import', null, $forged);
        }
    }

    public function testTwoColumnsForOneFieldAreRefusedAndTheMappingIsKept(): void
    {
        $this->upload('/fruit/import', "name;colour\napple;red\n");
        $crawler = $this->client->followRedirect();

        $crawler = $this->client->submit($crawler->filter('form')->form(['mapping[0]' => 'name', 'mapping[1]' => 'name']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Two columns are mapped to the same field: keep only one.', $crawler->filter('[data-dataflow-error]')->text());
        self::assertSame('name', $crawler->filter('select[name="mapping[1]"] option[selected]')->attr('value'), 'What was submitted stays selected.');
        self::assertSame([], $this->definition('fruit')->imported);
    }

    public function testARequiredFieldWithoutAColumnIsRefused(): void
    {
        $this->upload('/fruit/import', "name;colour\napple;red\n");
        $crawler = $this->client->followRedirect();

        $crawler = $this->client->submit($crawler->filter('form')->form(['mapping[0]' => '', 'mapping[1]' => 'colour']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('These fields need a column: Fruit name.', $crawler->filter('[data-dataflow-error]')->text(), 'By its label, the word of the select to change.');
    }

    public function testARunWithoutItsTokenIsRefused(): void
    {
        $this->upload('/fruit/import', "name\napple\n");
        $this->client->followRedirect();

        $this->client->request('POST', '/fruit/import/run', ['_token' => 'forged', 'mapping' => ['0' => 'name']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->definition('fruit')->imported);
    }

    public function testARefusalOfTheRunGoesBackToTheMappingWithTheFileKept(): void
    {
        $this->definition('fruit')->refuseNext = 'fruit.import.error.refused';
        $this->upload('/fruit/import', "name\napple\n");
        $form = $this->client->followRedirect()->filter('form')->form(['mapping[0]' => 'name']);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The orchard is closed.', $crawler->filter('[data-dataflow-error]')->text(), 'The definition\'s key, in its domain.');
        self::assertCount(1, glob($this->kernel()->importDirectory().'/*') ?: [], 'The file waits for the corrected mapping.');

        $this->definition('fruit')->refuseNext = null;
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
    }

    public function testABatchThatCouldNotBeWrittenSaysWhereAndWhetherItWasRolledBack(): void
    {
        $this->definition('fruit')->failNext = true;
        $this->upload('/fruit/import', "name\napple\npear\n");

        $crawler = $this->client->submit($this->client->followRedirect()->filter('form')->form(['mapping[0]' => 'name']));

        self::assertResponseIsSuccessful();
        self::assertSame('The import stopped at record 2: a batch could not be written. Nothing was kept: what had been written was rolled back.', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-dataflow-failure]')->text())));
    }

    public function testAListenerCanAnswerInsteadOfTheRun(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ImportStartingEvent::class, static function (ImportStartingEvent $event): void {
            $event->setResponse(new RedirectResponse('/upgrade?rows='.\count($event->mapping)));
        });
        $this->upload('/fruit/import', "name\napple\n");

        $this->client->submit($this->client->followRedirect()->filter('form')->form(['mapping[0]' => 'name']));

        self::assertResponseRedirects('/upgrade?rows=1');
        self::assertSame([], $this->definition('fruit')->imported, 'Nothing was run.');
    }

    public function testTheReportReceivesTheDefinitionsVariablesAndItsTemplate(): void
    {
        $this->upload('/fruit/import', "name\napple\n");

        $crawler = $this->client->submit($this->client->followRedirect()->filter('form')->form(['mapping[0]' => 'name']));

        self::assertCount(1, $crawler->filter('a.btn-primary[href="/fruits"]'), 'Back to the list the definition names.');
        self::assertSame('ripe', $crawler->filter('[data-fruit-note]')->text(), 'The definition\'s template, with the variables of its result.');
    }

    public function testThePreviewShowsTheFirstRowsAndWhatWouldBeOverwritten(): void
    {
        $this->upload('/previewed/import', "name;colour\napple;red\npear;green\nplum;purple\n");

        $crawler = $this->client->followRedirect();

        self::assertCount(3, $crawler->filter('[data-dataflow-preview] tbody tr'));
        self::assertSame('3 rows: 2 to create, 1 to update.', $crawler->filter('[data-dataflow-impact] strong')->text());
        self::assertStringContainsString('2 to create, 1 to update', $crawler->filter('button[type=submit]')->text(), 'The button carries the count.');
    }

    public function testTheModelIsTheFieldKeysInTheReadersDialect(): void
    {
        $this->client->request('GET', '/fruit/import/template');
        $content = $this->client->getInternalResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('name;colour;origin', $content);
        self::assertStringContainsString('attachment; filename="template_fruit_', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAnImportWithoutAModelAnswers404(): void
    {
        $this->client->loginUser(new InMemoryUser('importer', 'x', ['ROLE_IMPORTER']), 'main');
        $this->client->request('GET', '/small/import/template');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOneRouteServesSeveralImportsAndListsThemInTheirOrder(): void
    {
        $crawler = $this->client->request('GET', '/many/many_veg');

        self::assertResponseIsSuccessful();
        self::assertSame(['1. Import fruits', '2. Import vegetables'], $crawler->filter('[data-dataflow-imports] li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame('/many/many_fruit', $crawler->filter('[data-dataflow-imports] a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-dataflow-hint]'), 'No advice when the import gives none.');

        $this->upload('/many/many_veg', "name\ncarrot\n");
        self::assertResponseRedirects('/many/many_veg/mapping', 303);
        $this->client->submit($this->client->followRedirect()->filter('form')->form(['mapping[0]' => 'name']));
        self::assertSame([['name' => 'carrot']], $this->definition('many_veg')->imported);
        self::assertSame([], $this->definition('many_fruit')->imported);
    }

    public function testAnUnknownImportAnswers404(): void
    {
        $this->client->request('GET', '/many/many_stones');

        self::assertResponseStatusCodeSame(404);
    }

    private static function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private function upload(string $uri, string $content, string $name = 'fruits.csv'): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        $form = $crawler->filter('form')->form();
        $field = $form['file'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($this->path($content, $name));

        return $this->client->submit($form);
    }

    private function file(string $content): UploadedFile
    {
        return new UploadedFile($this->path($content, 'fruits.csv'), 'fruits.csv', null, null, true);
    }

    private function path(string $content, string $name): string
    {
        $path = sys_get_temp_dir().'/dataflow-import-test-'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function definition(string $id): FruitImportDefinition
    {
        $definition = self::getContainer()->get($id);
        self::assertInstanceOf(FruitImportDefinition::class, $definition);

        return $definition;
    }

    private function kernel(): ScreensKernel
    {
        $kernel = $this->client->getKernel();
        self::assertInstanceOf(ScreensKernel::class, $kernel);

        return $kernel;
    }
}
