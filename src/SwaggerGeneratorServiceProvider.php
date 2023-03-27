<?php

namespace Zibafar\SwaggerGenerator;


use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zibafar\SwaggerGenerator\Commands\MakeSwaggerForController as CommandsMakeSwaggerForController;
use Zibafar\SwaggerGenerator\Commands\MakeSwaggerForModel;
use Zibafar\SwaggerGenerator\Commands\MakeSwaggerForRequest;
use Zibafar\SwaggerGenerator\Commands\SwaggerGeneratorCommand;

class SwaggerGeneratorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('swagger-generator')
            ->hasConfigFile()
            // ->hasViews()
            // ->hasMigration('create_swagger-generator_table')
            ->hasCommand(SwaggerGeneratorCommand::class)
            ->hasCommand(CommandsMakeSwaggerForController::class)
            ->hasCommand(MakeSwaggerForModel::class)
            ->hasCommand(MakeSwaggerForRequest::class)
            ;
}
}
