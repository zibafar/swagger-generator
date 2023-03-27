<?php

namespace Zibafar\SwaggerGenerator\Commands;

use Illuminate\Console\Command;

class SwaggerGeneratorCommand extends Command
{
    public $signature = 'swagger:generator';

    public $description = 'My command';

    public function handle(): int
    {
        $this->command('swagger:controller');
        $this->command('swagger:request');
        $this->command('swagger:model');
        $this->comment('All done');

        return self::SUCCESS;
    }
}
