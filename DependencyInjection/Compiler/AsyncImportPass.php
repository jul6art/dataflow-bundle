<?php

declare(strict_types=1);

namespace Jul6Art\DataflowBundle\DependencyInjection\Compiler;

use Jul6Art\DataflowBundle\Import\Async\ImportMessageHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes {@see ImportMessageHandler} when the application has not configured Messenger.
 *
 * ⚠️ **This bundle does not require `symfony/messenger`.** It sits in `require-dev` here — used by
 * this bundle's own tests, exactly like `jul6art/core-bundle` — for the same reason neither
 * `api-platform` nor that bundle is a hard dependency: an application that only imports files
 * synchronously should not have to install a message bus it will never use.
 *
 * ⚠️ **`messenger.default_bus` is the check, by name, because that is what `FrameworkBundle`
 * actually sets — verified in `FrameworkExtension`, not assumed.** It is an ALIAS to the default
 * bus's service id, registered only once `framework.messenger` is configured with at least one
 * bus — not a container parameter, which a first version of this pass assumed and which left the
 * handler wired in an application with no bus at all until a wiring test caught it.
 *
 * ⚠️ **Removing the handler, not merely leaving it unwired.** The handler's `messenger.message_handler`
 * tag is written by hand in `services.yaml` — this file's own `_defaults` turn autoconfiguration
 * off, so `#[AsMessageHandler]` is never processed for a service THIS bundle registers, only for an
 * application's own. The tag stands regardless of whether a bus exists to read it, so without a bus
 * the handler would be fully wired and reachable, doing nothing useful — exactly the kind of thing
 * `debug:container` should not have to explain, which is why it is removed rather than left inert.
 */
final class AsyncImportPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias('messenger.default_bus') || $container->hasDefinition('messenger.default_bus')) {
            return;
        }

        $container->removeDefinition(ImportMessageHandler::class);
    }
}
