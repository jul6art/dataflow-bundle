<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\Import\Web;

/**
 * The names of an import's routes. The bundle declares none: the application routes {@see ImportController} under
 * the names its templates and menus already use, and tells the bundle which is which.
 */
final readonly class ImportRoutes
{
    /**
     * @param string|null $template the downloadable model's route; `null`: no model is offered
     */
    public function __construct(
        public string $upload,
        public string $mapping,
        public string $run,
        public ?string $template = null,
    ) {
    }
}
