<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use LaravelRsc\Support\ActionManifest;

class RscActionManifestCommand extends Command
{
    protected $signature = 'rsc:action-manifest';

    protected $description = 'Output RSC action mappings as JSON for the build system';

    public function handle(): int
    {
        $this->line(json_encode(ActionManifest::discover(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
