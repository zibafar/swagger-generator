<?php

namespace Zibafar\SwaggerGenerator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Zibafar\SwaggerGenerator\SwaggerGenerator
 */
class SwaggerGenerator extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Zibafar\SwaggerGenerator\SwaggerGenerator::class;
    }
}
