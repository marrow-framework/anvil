<?php

declare(strict_types=1);

namespace Marrow\Anvil;

use Marrow\Anvil\Commands\AnvilInstallCommand;
use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

/**
 * Registers `php forge anvil:install`. No routes/views/migrations — this
 * module exists purely so the package's console command becomes available
 * the moment it's composer-required, via Marrow's package auto-discovery
 * (see docs/modules.md#distributing-a-module-as-a-package). Nothing here
 * is added to config/modules.php by the consuming app.
 */
#[Module(name: 'anvil', commands: [AnvilInstallCommand::class])]
class AnvilModule extends BaseModule
{
}
