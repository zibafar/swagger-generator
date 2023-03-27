<?php

namespace Zibafar\SwaggerGenerator\Commands;

use Illuminate\Console\Command;

class SwaggerGeneratorCommand extends Command
{
    public $signature = 'swagger-generator';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
