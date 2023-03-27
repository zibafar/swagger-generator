<?php

namespace Zibafar\SwaggerGenerator\Commands;

use Illuminate\Console\Command;

class MakeSwaggerForModel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swagger:model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate swagger for models';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        return Command::SUCCESS;
    }
}
