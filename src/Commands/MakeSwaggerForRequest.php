<?php

namespace Zibafar\SwaggerGenerator\Commands;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Exception;
use Illuminate\Console\Command;
use ReflectionClass;

class MakeSwaggerForRequest extends Command
{
    private string $namespace = "App\\Documents\\Requests";
    private string $path = "app/Documents/Requests";
    protected bool $write = false;

    private $swagger;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swagger:requests {request?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate swagger for request';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $namespace_input_file[] = $this->argument('request');
        $this->generateSwagger($namespace_input_file);
    }

    /**
     * getRules
     *
     * @return void
     */
    private function generateSwagger($namespace_input_file = [])
    {
        $all = empty(array_filter($namespace_input_file)) ? $this->loadRequests() : $namespace_input_file;

        $swagger = "";

        foreach ($all as $name) {
            try {
                $reflectionClass = new ReflectionClass($name);

                if (!$reflectionClass->isSubclassOf('Illuminate\Foundation\Http\FormRequest')) {
                    continue;
                }
                $swagger = $this->makeSwagger($name);

                $this->generateDoc($name, $swagger);
            } catch (Exception $ex) {
                $this->error($ex->getMessage());
            }
        }
        return $swagger;
    }


    private function generateDoc($name, $content)
    {
        $path = str_replace('App\\Http\\Requests', $this->path, $name);
        $path = str_replace('\\', '/', $path);
        $filename = substr($path, strrpos($path, '/') + 1) . 'Swagger.php';
        $path = substr($path, 0, strrpos($path, '/'));


        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $fullName = $path . '/' . $filename;
        file_put_contents($fullName, $content . "\n }");
        $this->info($fullName);
    }

    private function makeSwagger($name)
    {
        $swaggerRules = $this->getRules($name)[0];
        $swaggerHeader = $this->getHeader($name, $this->getRules($name)[1]);
        return $swaggerHeader .
            $swaggerRules;
    }

    private function getHeader(string $name, $required_str)
    {
        $namespace = str_replace('App\\Http\\Requests', $this->namespace, $name);
        $className = \Str::afterLast($namespace, "\\") . "Swagger";
        $namespace = \Str::beforeLast($namespace, "\\");

        return "<?php
        namespace {$namespace};
        /**
        * @OA\Schema(
        *      title=\"{$className}\",
        *      description=\"{$name}\",
        *      type=\"object\",
        *      {$required_str}
        * )
        */
        class {$className}{
        ";
    }

    private function getRules(string $name)
    {
        $class = new $name();
        $rules = [];
        $required_list = [];
        try {
            $rules = $class->rules();
        } catch (Exception $ex) {
            $this->error($ex->getMessage());
        } finally {

            $swaggerRules = "";
            $all = [];
            foreach (($rules) as $key => $rule) {
                $result = [];
                if ($this->isRequired($rule)) {
                    array_push($required_list, $key);
                }
                $type = $this->findType($rule, $key);
                $key = str_replace(".*", "", $key);
                $example = $this->getExampleByType($type);
                $result['key'] = $key;
                $result['type'] = $type;
                $result['example'] = $example;
                $result['php_type'] = ($type == "json") ? "string" : $type;
                $all[] = $result;
            }

            $swaggerRules = $this->makeSwaggerForEachFiled($all);

            $required_str = self::getRequiredStr($required_list);

            return [$swaggerRules, $required_str];
        }
    }

    private function makeSwaggerForEachFiled($results)
    {
        $collections = collect($results);

        $uniqueByKey = $collections->reject(function ($item) use ($collections) {
            return  $item['type'] !== "json" && $collections->countBy('key')[$item['key']] > 1;
        });

        $swaggerRules = "";
        foreach ($uniqueByKey as $result) {
            $key = $result['key'];
            $type  = $result['type'];
            $example = $result['example'];
            $php_type = $result['php_type'];
            if (\Str::contains($key, ".")) {
                continue;
            }
            $swagger = "
            /**
            * @OA\\Property(
            *      title=\"$key\",
            *      description=\"$key\",";

            $swagger = $swagger . "
            *      type=\"$type\",";

            $swagger = $swagger . "
            *      example=\"$example\"";

            $swagger = $swagger . "
            * )
            *";
            $swagger = $swagger . "* @var $php_type
            */";
            $key = str_replace('-', '_', $key);
            $swagger = $swagger . "
            public \$$key;";
            $swaggerRules .= $swagger;
        }
        return $swaggerRules;
    }

    private function isRequired($rules)
    {
        return \Str::contains(strtolower(json_encode($rules)), ['required']);
    }

    private static function getRequiredStr(array $required_list)
    {
        $j = "{";
        foreach ($required_list as $item) {
            $item = str_replace(".*", "", $item);
            $j .= "\"{$item}\"";
            if ($item !== str_replace(".*", "", end($required_list))) {
                $j .= ",";
            }
        }
        return "required= " . $j . "}";
    }

    private function findType($rules, $key): string
    {
        try {

            $type = "string";
            if (\Str::contains($key, [".*"])) {
                return 'json';
            }
            if (is_string($rules)) {
                $rules = explode("|", $rules);
            }
            if (!is_array($rules)) {
                return $type;
            }

            foreach ($rules as $rule) {
                if (!is_string($rule)) {
                    if (\Str::contains(get_class($rule), ['Illuminate\Validation\Rules\In'])) {
                        $type = 'json';
                        break;
                    }
                    continue;
                }
                $rule = strtolower($rule);
                if ($rule)
                    if ($rule == 'string') {
                        $type = "string";
                        break;
                    }
                if ($rule == 'int' || $rule == 'integer') {
                    $type = "string";
                    break;
                }
                if ($rule == 'string') {
                    $type = "string";
                    break;
                }
                if ($rule == 'bool' || $rule == 'boolean') {
                    $type = "boolean";
                    break;
                }
                if ($rule == 'enum' || $rule == 'in:') {
                    $type = "boolean";
                    break;
                }
            }
            return $type;
        } catch (Exception $ex) {
            $this->error($ex->getMessage());
        }
    }

    private function getExampleByType(string $type): string
    {
        return match ($type) {
            'int' => fake()->numberBetween(1, 1000),
            'integer' => fake()->numberBetween(1, 1000),
            'string' => 'This is a text',
            'boolean' => rand(0, 1) < 0.5,
            'bool' => rand(0, 1) < 0.5,
            'json' => "{\"\"key\"\":\"\"value\"\",\"\"key2\"\":\"\"value2\"\"}"
        };
    }

    protected function loadRequests()
    {
        $requests = [];
        $dir = 'app/Http/Requests';
        if (is_dir(base_path($dir))) {
            $dir = base_path($dir);
        }

        $dirs = glob($dir, GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $this->error("Cannot locate directory '{$dir}'");
                continue;
            }

            if (file_exists($dir)) {
                $classMap = ClassMapGenerator::createMap($dir);

                // Sort list so it's stable across different environments
                ksort($classMap);

                foreach ($classMap as $request => $path) {
                    if (\Str::contains($request, ["Abstract"])) {
                        continue;
                    }
                    $requests[] = $request;
                }
            }
        }

        return $requests;
    }
}
