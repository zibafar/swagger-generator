<?php

namespace Zibafar\SwaggerGenerator\Commands;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Exception;
use Illuminate\Console\Command;
use ReflectionClass;
use Route;

class MakeSwaggerForController extends Command
{
    private string $namespace = 'App\\Documents\\Controllers';

    private string $src_namespace = 'App\\Http\\Controllers';

    private string $des_namespace = '';

    private string $des_class = '';

    private string $path = 'app/Documents/Controllers';

    protected bool $write = false;

    private $swagger;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swagger:controller {controller?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate swagger for controller';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $namespace_input_file[] = $this->argument('controller');

        $this->generateSwagger($namespace_input_file);
    }

    /**
     * getRules
     *
     * @return void
     */
    private function generateSwagger($namespace_input_file = [])
    {
        $all = empty(array_filter($namespace_input_file)) ? $this->loadControllers() : $namespace_input_file;
        $swagger = '';

        foreach ($all as $name) {
            try {
                $reflectionClass = new ReflectionClass($name);

                if (! $reflectionClass->isSubclassOf('Illuminate\Routing\Controller')) {
                    continue;
                }

                $swagger = $this->makeSwagger($name);
                $this->putToFile($swagger);
            } catch (Exception $ex) {
                $this->error($ex->getMessage());
            }
        }

        return $swagger;
    }

    private function putToFile($content)
    {
        $path = $this->des_namespace;
        $filename = $this->des_class;

        $path = lcfirst(str_replace('\\', '/', $path));

        if (! file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $fullName = $path.'/'.$filename.'.php';
        file_put_contents($fullName, $content."\n }");
        $this->info($fullName);
        // dd("ok");
    }

    private function makeSwagger($name)
    {
        $data = $this->getAllFunctionsInfoInControllerByNameSpace($name);

        $doc = $this->generateDoc($data);

        $header = $this->getHeader($data->first());

        return $header.
            $doc;
    }

    private function generateDoc($data)
    {
        $str = '';
        foreach ($data as $item) {
            $method = $item['method'];

            $reqs = $this->swaggerRequest($method, $item);
            $response = $this->swaggerResponse($method, $item);
            $str .= $this->swaggerForMethod($method, $item, $reqs, $response);
        }

        return $str;
    }

    private function swaggerRequest($method, $item)
    {
        if (count($item['requests']) === 0) {
            return '';
        }
        $str = "* @OA\RequestBody(
            ";
        foreach ($item['requests'] as $i) {
            $str .= "* required=true,
                     *   @OA\JsonContent(ref=\"#/components/schemas/{$i}\")
     ";
        }
        $str .= '*),';

        return $str;
    }

    private function swaggerResponse($method, $item)
    {
        if (! isset($item['response'])) {
            return '';
        }
        $str = "* @OA\Response(response=200, description=\"Successful operation\",
        * @OA\JsonContent(";
        $item['response'] = 'UserSwagger';
        $str .= "  * @OA\Property(ref=\"#/components/schemas/{$item['response']}\"),)),
            ";

        $str .= '*
            * )';

        return $str;
    }

    private function swaggerForMethod($method, $item, $reqs, $response)
    {
        if ($method == 'Get') {
            return $this->swaggerForGet($item, $reqs, $response);
        }
        // if ($method == "Post") {
        //     return $this->swaggerForPost($item, $reqs, $response);
        // }
    }

    private function swaggerForGet($item, $reqs, $response)
    {
        $str = "/**
            * @OA\\Get(
            * path=\"{$item['uri']}\",
            * tags={\"{$item['tag']}\"},
            * summary=\"{$item['summary']}\",
            {$reqs}
            {$response}
            *
            *
            */
            public function {$item['function']}()
            {
            }
            ";

        return $str;
    }

    private function getHeader($data)
    {
        $namespace = $data['namespace'];
        $className = $data['className'];
        $this->des_namespace = $data['namespace'];
        $this->des_class = $data['className'];

        return "<?php
        namespace {$namespace};
        class {$className}{
        ";
    }

    private function getAllFunctionsInfoInControllerByNameSpace(string $namespace, $first = false)
    {
        $data = collect(Route::getRoutes())->filter(function ($item) use ($namespace) {
            if (isset($item->action['controller'])) {
                $str = $item->action['controller'];
                $class_name = \Str::beforeLast($str, '@');
                $reflectionClass = new ReflectionClass($class_name);

                return $reflectionClass->isSubclassOf('Illuminate\Routing\Controller')
                    && \Str::contains($str, $namespace, true);
            }
        })->map(function ($row) {
            $full_class_name = $row->action['controller'];
            $class_info = $this->getClassIno($full_class_name);
            $funcName = $this->getFunctionName($full_class_name);
            $item = $row;

            return $class_info + [
                'uri' => $row->uri,
                'tag' => self::getTag($row->uri),
                'method' => \Str::title($row->methods[0]),
                'function' => $funcName,
                'summary' => self::getSummary($item, $funcName),
                'requests' => self::getArgumentTypesOfFunction($full_class_name, $funcName),
                'response' => self::getReturnTypeOfFunction($full_class_name, $funcName),
            ];
        });

        return $first ? $data->first() : $data;
    }

    private function getFunctionName($str)
    {
        return \Str::afterLast($str, '@') ?? '_invoke';
    }

    private static function getTag($str, $separator = '.')
    {
        return implode($separator, array_map('Str::title', explode('/', $str)));
    }

    private static function getSummary($row, $funcName)
    {
        $txt = self::getTag($row->uri, ' ');

        return "{$row->methods[0]}  {$txt} function name is:  {$funcName} ";
    }

    private function getClassIno($str)
    {
        $old_namespace = \Str::beforeLast($str, '@');
        $namespace = str_replace($this->src_namespace, $this->namespace, $old_namespace);
        $className = \Str::afterLast($namespace, '\\').'Swagger';
        $namespace = \Str::beforeLast($namespace, '\\');

        return ['namespace' => $namespace, 'className' => $className];
    }

    protected function loadControllers()
    {
        $controllers = [];
        $dir = 'app/Http/Controllers';
        if (is_dir(base_path($dir))) {
            $dir = base_path($dir);
        }

        $dirs = glob($dir, GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                $this->error("Cannot locate directory '{$dir}'");

                continue;
            }

            if (file_exists($dir)) {
                $classMap = ClassMapGenerator::createMap($dir);

                // Sort list so it's stable across different environments
                ksort($classMap);

                foreach ($classMap as $c => $path) {
                    if (\Str::contains($c, ['Abstract'])) {
                        continue;
                    }
                    $controllers[] = $c;
                }
            }
        }

        return $controllers;
    }

    private static function getArgumentTypesOfFunction($controllerName, $methodName): array
    {
        $controllerName = \Str::beforeLast($controllerName, '@');

        $types = [];
        if (method_exists($controllerName, $methodName)) {
            $reflectionFunction = new \ReflectionMethod($controllerName, $methodName);
            $params = $reflectionFunction->getParameters();

            foreach ($params as $param) {
                if (isset($param)) {
                    $types[] = \Str::afterLast($param?->getType()?->getName(), '\\').'Swagger';
                }
            }
        }

        return $types;
    }

    private static function getReturnTypeOfFunction($controllerName, $methodName): string
    {
        $controllerName = \Str::beforeLast($controllerName, '@');

        $type = '';
        if (method_exists($controllerName, $methodName)) {
            $reflectionFunction = new \ReflectionMethod($controllerName, $methodName);
            $params = $reflectionFunction->getReturnType();
            if (isset($params)) {
                $s = $params->getName() ?? '';
                $type = \Str::afterLast($s, '\\');
            }
        }

        return $type;
    }
}
