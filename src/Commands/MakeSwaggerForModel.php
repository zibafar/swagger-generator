<?php

namespace Zibafar\SwaggerGenerator\Commands;
use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Serializer as DocBlockSerializer;
use Barryvdh\Reflection\DocBlock\Tag;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Types\Type;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\Types\ContextFactory;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionType;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class MakeSwaggerForModel extends Command
{
        /**
         * @var Filesystem $files
         */
        protected $files;

        /**
         * The console command name.
         *
         * @var string
         */
        protected $name = 'swagger:model';

        /**
         * @var string
         */
        protected $filename;

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Generate swagger for models';

        protected $properties = [];
        protected $methods = [];
        protected $write = false;
        protected $dirs = [];
        protected $keep_text;
        /**
         * @var bool[string]
         */
        protected $nullableColumns = [];

        /**
         * During initialization we use Laravels Date Facade to
         * determine the actual date class and store it here.
         *
         * @var string
         */
        protected $dateClass;

        /**
         * @param Filesystem $files
         */
        public function __construct(Filesystem $files)
        {
            parent::__construct();
            $this->files = $files;
        }

        /**
         * Execute the console command.
         *
         * @return void
         */
        public function handle()
        {

            $this->write = $this->option('write');
            $this->dirs = ['app/Models'];

            $models = $this->argument('model');

            if (empty($models)) {
                $models = $this->loadModels();
            }

            foreach ($models as $model) {

                $path = str_replace('App\\', 'app/Documents/', $model);
                $path = str_replace('\\', '/', $path);
                $name = substr($path, strrpos($path, '/') + 1) . 'Swagger.php';
                $path = substr($path, 0, strrpos($path, '/'));


                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }

                $filename = $path . '/' . $name;

                $ignore = $this->option('ignore');



                //If filename is default and Write is not specified, ask what to do
                if (!$this->write && $filename === $this->filename && !$this->option('nowrite')) {
                    if (
                        $this->confirm(
                            "Do you want to overwrite the existing model files? Choose no to write to $filename instead"
                        )
                    ) {
                        $this->write = true;
                    }
                }

                $this->dateClass = 'datetime';

                if (file_exists($filename)) {
                    unlink($filename);
                }
                touch($filename);


                $content = $this->generateDocs([$model], $ignore);
                file_put_contents($filename, $content);
                $this->info("Model information was written to $filename");
            }
        }


        /**
         * Get the console command arguments.
         *
         * @return array
         */
        protected function getArguments()
        {
            return [
                ['model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', []],
            ];
        }

        /**
         * Get the console command options.
         *
         * @return array
         */
        protected function getOptions()
        {
            return [
                ['filename', 'F', InputOption::VALUE_OPTIONAL, 'The path to the helper file'],
                [
                    'dir',
                    'D', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                    'The model dir, supports glob patterns',
                    [],
                ],
                ['write', 'W', InputOption::VALUE_NONE, 'Write to Model file'],
                [
                    'write-mixin',
                    'M', InputOption::VALUE_NONE,
                    "Write models to {$this->filename} and adds @mixin to each model, avoiding IDE duplicate declaration warnings",
                ],
                ['nowrite', 'N', InputOption::VALUE_NONE, 'Don\'t write to Model file'],

                ['ignore', 'I', InputOption::VALUE_OPTIONAL, 'Which models to ignore', ''],
            ];
        }

        protected function generateDocs($loadModels, $ignore = '')
        {
            $output = "<?php
            /**
             * swagger generate
             *
             * @author Tesmino
             */
            \n\n";

            $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

            if (empty($loadModels)) {
                $models = $this->loadModels();
            } else {
                $models = [];
                foreach ($loadModels as $model) {
                    $models = array_merge($models, explode(',', $model));
                }
            }

            $ignore = array_merge(
                explode(',', $ignore),
                $this->laravel['config']->get('swagger-generator.ignored_models', [])
            );

            foreach ($models as $name) {
                if (in_array($name, $ignore)) {
                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->comment("Ignoring model '$name'");
                    }
                    continue;
                }
                $this->properties = [];
                $this->methods = [];
                if (class_exists($name)) {
                    try {
                        // handle abstract classes, interfaces, ...
                        $reflectionClass = new ReflectionClass($name);

                        if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                            continue;
                        }

                        $this->comment("Loading model '$name'", OutputInterface::VERBOSITY_VERBOSE);

                        if (!$reflectionClass->IsInstantiable()) {
                            // ignore abstract class or interface
                            continue;
                        }

                        $model = $this->laravel->make($name);

                        if ($hasDoctrine) {
                            $this->getPropertiesFromTable($model);
                        }

                        if (method_exists($model, 'getCasts')) {
                            $this->castPropertiesType($model);
                        }

                        $output .= $this->createPhpDocs($name);
                        $ignore[] = $name;
                        $this->nullableColumns = [];
                    } catch (Throwable $e) {
                        $this->error('Exception: ' . $e->getMessage() .
                            "\nCould not analyze class $name.\n\nTrace:\n" .
                            $e->getTraceAsString());
                    }
                }
            }

            return $output;
        }


        protected function loadModels()
        {
            $models = [];
            foreach ($this->dirs as $dir) {
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

                        foreach ($classMap as $model => $path) {
                            $models[] = $model;
                        }
                    }
                }
            }
            return $models;
        }

        /**
         * cast the properties's type from $casts.
         *
         * @param \Illuminate\Database\Eloquent\Model $model
         */
        public function castPropertiesType($model)
        {
            $casts = $model->getCasts();
            foreach ($casts as $name => $type) {
                if (Str::startsWith($type, 'decimal:')) {
                    $type = 'decimal';
                } elseif (Str::startsWith($type, 'custom_datetime:')) {
                    $type = 'date';
                } elseif (Str::startsWith($type, 'date:')) {
                    $type = 'date';
                } elseif (Str::startsWith($type, 'datetime:')) {
                    $type = 'date';
                } elseif (Str::startsWith($type, 'immutable_custom_datetime:')) {
                    $type = 'immutable_date';
                } elseif (Str::startsWith($type, 'encrypted:')) {
                    $type = Str::after($type, ':');
                }

                $params = [];

                switch ($type) {
                    case 'encrypted':
                        $realType = 'string';
                        break;
                    case 'boolean':
                    case 'bool':
                        $realType = 'boolean';
                        break;
                    case 'decimal':
                    case 'string':
                        $realType = 'string';
                        break;
                    case 'array':
                    case 'json':
                        $realType = 'array';
                        break;
                    case 'object':
                        $realType = 'object';
                        break;
                    case 'int':
                    case 'integer':
                    case 'timestamp':
                        $realType = 'integer';
                        break;
                    case 'real':
                    case 'double':
                    case 'float':
                        $realType = 'float';
                        break;
                    case 'date':
                    case 'datetime':
                        $realType = 'datetime';
                        break;
                    case 'immutable_date':
                    case 'immutable_datetime':
                        $realType = 'datetime';
                        break;
                    case 'collection':
                        $realType = 'array';
                        break;
                    default:
                        // In case of an optional custom cast parameter , only evaluate
                        // the `$type` until the `:`
                        $type = strtok($type, ':');
                        $this->setProperty($name, null, true, true);

                        $params = strtok(':');
                        $params = $params ? explode(',', $params) : [];
                        break;
                }

                if (!isset($this->properties[$name])) {
                    continue;
                }
                if ($this->isInboundCast($realType)) {
                    continue;
                }

                $realType = $this->checkForCastableCasts($realType, $params);
                $realType = $this->checkForCustomLaravelCasts($realType);
                $realType = $this->getTypeOverride($realType);
                $this->properties[$name]['type'] = $this->getTypeInModel($model, $realType);

                if (isset($this->nullableColumns[$name])) {
                    $this->properties[$name]['type'] .= '|null';
                }
            }
        }

        /**
         * Returns the override type for the give type.
         *
         * @param string $type
         * @return string|null
         */
        protected function getTypeOverride($type)
        {
            $typeOverrides = $this->laravel['config']->get('swagger.type_overrides', []);

            return $typeOverrides[$type] ?? $type;
        }

        /**
         * Load the properties from the database table.
         *
         * @param \Illuminate\Database\Eloquent\Model $model
         *
         * @throws DBALException If custom field failed to register
         */
        public function getPropertiesFromTable($model)
        {
            $database = $model->getConnection()->getDatabaseName();
            $table = $model->getConnection()->getTablePrefix() . $model->getTable();
            $schema = $model->getConnection()->getDoctrineSchemaManager();

            $databasePlatform = $schema->getDatabasePlatform();
            $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

            $platformName = $databasePlatform->getName();
            $customTypes = $this->laravel['config']->get("swagger-generator.custom_db_types.{$platformName}", []);
            foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
                try {
                    if (!Type::hasType($yourTypeName)) {
                        Type::addType($yourTypeName, get_class(Type::getType($doctrineTypeName)));
                    }
                } catch (DBALException $exception) {
                    $this->error("Failed registering custom db type \"$yourTypeName\" as \"$doctrineTypeName\"");
                    throw $exception;
                }
                $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
            }

            $columns = $schema->listTableColumns($table, $database);

            if (!$columns) {
                return;
            }
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = 'datetime';
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                        case 'decimal':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'boolean':
                            switch ($platformName) {
                                case 'sqlite':
                                case 'mysql':
                                    $type = 'integer';
                                    break;
                                default:
                                    $type = 'boolean';
                                    break;
                            }
                            break;
                        case 'float':
                            $type = 'float';
                            break;
                        default:
                            $type = 'string';
                            break;
                    }
                }

                $comment = $column->getComment();
                if (!$column->getNotnull()) {
                    $this->nullableColumns[$name] = true;
                }
                $this->setProperty(
                    $name,
                    $this->getTypeInModel($model, $type),
                    true,
                    true,
                    $comment,
                    !$column->getNotnull()
                );
            }
        }

        /**
         * @param \Illuminate\Database\Eloquent\Model $model
         */
        public function getPropertiesFromMethods($model)
        {
            $methods = get_class_methods($model);
            if ($methods) {
                sort($methods);
                foreach ($methods as $method) {
                    $reflection = new \ReflectionMethod($model, $method);
                    $type = $this->getReturnTypeFromReflection($reflection);
                    $isAttribute = is_a($type, '\Illuminate\Database\Eloquent\Casts\Attribute', true);
                    if (
                        Str::startsWith($method, 'get') && Str::endsWith(
                            $method,
                            'Attribute'
                        ) && $method !== 'getAttribute'
                    ) {
                        //Magic get<name>Attribute
                        $name = Str::snake(substr($method, 3, -9));
                        if (!empty($name)) {
                            $type = $this->getReturnType($reflection);
                            $type = $this->getTypeInModel($model, $type);
                            $comment = $this->getCommentFromDocBlock($reflection);
                            $this->setProperty($name, $type, true, null, $comment);
                        }
                    } elseif ($isAttribute) {
                        $name = Str::snake($method);
                        $types = $this->getAttributeReturnType($model, $method);

                        if ($types->has('get')) {
                            $type = $this->getTypeInModel($model, $types['get']);
                            $comment = $this->getCommentFromDocBlock($reflection);
                            $this->setProperty($name, $type, true, null, $comment);
                        }

                        if ($types->has('set')) {
                            $comment = $this->getCommentFromDocBlock($reflection);
                            $this->setProperty($name, null, null, true, $comment);
                        }
                    } elseif (
                        Str::startsWith($method, 'set') && Str::endsWith(
                            $method,
                            'Attribute'
                        ) && $method !== 'setAttribute'
                    ) {
                        //Magic set<name>Attribute
                        $name = Str::snake(substr($method, 3, -9));
                        if (!empty($name)) {
                            $comment = $this->getCommentFromDocBlock($reflection);
                            $this->setProperty($name, null, null, true, $comment);
                        }
                    } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                        //Magic set<name>Attribute
                        $name = Str::camel(substr($method, 5));
                        if (!empty($name)) {
                            $comment = $this->getCommentFromDocBlock($reflection);
                            $args = $this->getParameters($reflection);
                            //Remove the first ($query) argument
                            array_shift($args);
                            $builder = $this->getClassNameInDestinationFile(
                                $reflection->getDeclaringClass(),
                                get_class($model->newModelQuery())
                            );
                            $modelName = $this->getClassNameInDestinationFile(
                                $reflection->getDeclaringClass(),
                                $reflection->getDeclaringClass()->getName()
                            );
                            $this->setMethod($name, $builder . '|' . $modelName, $args, $comment);
                        }
                    } elseif (in_array($method, ['query', 'newQuery', 'newModelQuery'])) {
                        $builder = $this->getClassNameInDestinationFile($model, get_class($model->newModelQuery()));

                        $this->setMethod(
                            $method,
                            $builder . '|' . $this->getClassNameInDestinationFile($model, get_class($model))
                        );


                    } elseif (
                        !method_exists('Illuminate\Database\Eloquent\Model', $method)
                        && !Str::startsWith($method, 'get')
                    ) {
                        //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                        if ($returnType = $reflection->getReturnType()) {
                            $type = $returnType instanceof ReflectionNamedType
                                ? $returnType->getName()
                                : (string) $returnType;
                        } else {
                            // php 7.x type or fallback to docblock
                            $type = (string) $this->getReturnTypeFromDocBlock($reflection);
                        }

                        $file = new \SplFileObject($reflection->getFileName());
                        $file->seek($reflection->getStartLine() - 1);

                        $code = '';
                        while ($file->key() < $reflection->getEndLine()) {
                            $code .= $file->current();
                            $file->next();
                        }
                        $code = trim(preg_replace('/\s\s+/', '', $code));
                        $begin = strpos($code, 'function(');
                        $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);

                        foreach ($this->getRelationTypes() as $relation => $impl) {
                            $search = '$this->' . $relation . '(';
                            if (stripos($code, $search) || ltrim($impl, '\\') === ltrim((string) $type, '\\')) {
                                //Resolve the relation's model to a Relation object.
                                $methodReflection = new \ReflectionMethod($model, $method);
                                if ($methodReflection->getNumberOfParameters()) {
                                    continue;
                                }

                                $comment = $this->getCommentFromDocBlock($reflection);
                                // Adding constraints requires reading model properties which
                                // can cause errors. Since we don't need constraints we can
                                // disable them when we fetch the relation to avoid errors.
                                $relationObj = Relation::noConstraints(function () use ($model, $method) {
                                    try {
                                        return $model->$method();
                                    } catch (Throwable $e) {
                                        $this->warn(sprintf('Error resolving relation model of %s:%s() : %s', get_class($model), $method, $e->getMessage()));

                                        return null;
                                    }
                                });

                                if ($relationObj instanceof Relation) {
                                    $relatedModel = $this->getClassNameInDestinationFile(
                                        $model,
                                        get_class($relationObj->getRelated())
                                    );

                                    if (
                                        strpos(get_class($relationObj), 'Many') !== false ||
                                        ($this->getRelationReturnTypes()[$relation] ?? '') === 'many'
                                    ) {
                                        //Collection or array of models (because Collection is Arrayable)
                                        $relatedClass = '\\' . get_class($relationObj->getRelated());
                                        $collectionClass = $this->getCollectionClass($relatedClass);
                                        $collectionClassNameInModel = $this->getClassNameInDestinationFile(
                                            $model,
                                            $collectionClass
                                        );
                                        $this->setProperty(
                                            $method,
                                            $collectionTypeHint,
                                            true,
                                            null,
                                            $comment
                                        );

                                    } elseif (
                                        $relation === 'morphTo' ||
                                        ($this->getRelationReturnTypes()[$relation] ?? '') === 'morphTo'
                                    ) {
                                        // Model isn't specified because relation is polymorphic
                                        $this->setProperty(
                                            $method,
                                            $this->getClassNameInDestinationFile($model, Model::class) . '|\Eloquent',
                                            true,
                                            null,
                                            $comment
                                        );
                                    } else {
                                        //Single model is returned
                                        $this->setProperty(
                                            $method,
                                            $relatedModel,
                                            true,
                                            null,
                                            $comment,
                                            $this->isRelationNullable($relation, $relationObj)
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }


        /**
         * @param string      $name
         * @param string|null $type
         * @param bool|null   $read
         * @param bool|null   $write
         * @param string|null $comment
         * @param bool        $nullable
         */
        public function setProperty($name, $type = null, $read = null, $write = null, $comment = '', $nullable = false)
        {
            if (!isset($this->properties[$name])) {
                $this->properties[$name] = [];
                $this->properties[$name]['type'] = 'mixed';
                $this->properties[$name]['read'] = false;
                $this->properties[$name]['write'] = false;
                $this->properties[$name]['comment'] = (string) $comment;
            }
            if ($type !== null) {
                $newType = $this->getTypeOverride($type);
                if ($nullable) {
                    $newType .= '|null';
                }
                $this->properties[$name]['type'] = $newType;
            }
            if ($read !== null) {
                $this->properties[$name]['read'] = $read;
            }
            if ($write !== null) {
                $this->properties[$name]['write'] = $write;
            }
        }

        public function setMethod($name, $type = '', $arguments = [], $comment = '')
        {
            $methods = array_change_key_case($this->methods, CASE_LOWER);

            if (!isset($methods[strtolower($name)])) {
                $this->methods[$name] = [];
                $this->methods[$name]['type'] = $type;
                $this->methods[$name]['arguments'] = $arguments;
                $this->methods[$name]['comment'] = $comment;
            }
        }

        public function unsetMethod($name)
        {
            unset($this->methods[strtolower($name)]);
        }

        public function getMethodType(Model $model, string $classType)
        {
            $modelName = $this->getClassNameInDestinationFile($model, get_class($model));
            $builder = $this->getClassNameInDestinationFile($model, $classType);
            return $builder . '|' . $modelName;
        }

        /**
         * @param string $class
         * @return string
         */
        protected function createPhpDocs($class)
        {
            $reflection = new ReflectionClass($class);
            $namespace = $reflection->getNamespaceName();
            $example = $class::query()->first();
            if (($example) == null) {
                $this->error("{$class} doesnt have value in database ");
                return 'not';
            }
            $example = $example->toArray();
            $classname = $reflection->getShortName();



                $phpdoc = new DocBlock($reflection, new Context($namespace));

            if (!$phpdoc->getText()) {
                $phpdoc->setText($class);
            }

            $properties = [];
            $methods = [];
            foreach ($phpdoc->getTags() as $tag) {
                $name = $tag->getName();
                if ($name == 'property' || $name == 'property-read' || $name == 'property-write') {
                    $properties[] = $tag->getVariableName();
                } elseif ($name == 'method') {
                    $methods[] = $tag->getMethodName();
                }
            }

            foreach ($this->properties as $name => $property) {
                $just_name = $name;
                $name = "\$$name";

                if ($this->hasCamelCaseModelProperties()) {
                    $name = Str::camel($name);
                }

                if (in_array($name, $properties)) {
                    continue;
                }
                if (!isset($example[$just_name])) {
                    continue;
                }

                if (is_array($example[$just_name])) {
                    $example[$just_name] = json_encode($example[$just_name]);
                }
                $property["type"] = str_replace('\\', '', $property["type"]);
                $my_type = $property["type"];
                if ($property["type"] == "datetime" || $property["type"] == "datetime|null") {
                    $property["type"] = "string\"
                 format=\"datetime\" \n";
                    $my_type = "\DateTime";
                }


                $tagLine = trim("@OA\Property(
                 title=\"{$just_name}\"
                 type=\"{$property['type']}\"
                 example=\"{$example[$just_name]}\"
                 )
                @var {$my_type}

                ");
                $tag = Tag::createInstance($tagLine, $phpdoc);
                $phpdoc->appendTag($tag);
            }



            $serializer = new DocBlockSerializer();
            $docComment = $serializer->getDocComment($phpdoc);

            $head = "
            /**
            * @OA\Schema(
            *     title=\"{$classname}\",
            *     description=\"{$namespace} {$classname} model\",
            *
            * )
            */
            ";
            $target_namespace = str_replace('App\\', 'App\Documents\\', $namespace);
            $output = "namespace {$target_namespace};\n
             {$head} class {$classname}Swagger
              {\n{$docComment}\n\t
              ";




            return $output . "\n}\n\n";
        }

        /**
         * Get the parameters and format them correctly
         *
         * @param $method
         * @return array
         * @throws \ReflectionException
         */
        public function getParameters($method)
        {
            //Loop through the default values for parameters, and make the correct output string
            $paramsWithDefault = [];
            /** @var \ReflectionParameter $param */
            foreach ($method->getParameters() as $param) {
                $paramStr = $param->isVariadic() ? '...$' . $param->getName() : '$' . $param->getName();

                if ($paramType = $this->getParamType($method, $param)) {
                    $paramStr = $paramType . ' ' . $paramStr;
                }

                if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                    $default = $param->getDefaultValue();
                    if (is_bool($default)) {
                        $default = $default ? 'true' : 'false';
                    } elseif (is_array($default)) {
                        $default = '[]';
                    } elseif (is_null($default)) {
                        $default = 'null';
                    } elseif (is_int($default)) {
                        //$default = $default;
                    } else {
                        $default = "'" . trim($default) . "'";
                    }

                    $paramStr .= " = $default";
                }

                $paramsWithDefault[] = $paramStr;
            }
            return $paramsWithDefault;
        }

        /**
         * Determine a model classes' collection type.
         *
         * @see http://laravel.com/docs/eloquent-collections#custom-collections
         * @param string $className
         * @return string
         */
        protected function getCollectionClass($className)
        {
            // Return something in the very very unlikely scenario the model doesn't
            // have a newCollection() method.
            if (!method_exists($className, 'newCollection')) {
                return '\Illuminate\Database\Eloquent\Collection';
            }

            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $className();
            return '\\' . get_class($model->newCollection());
        }



        /**
         * @return bool
         */
        protected function hasCamelCaseModelProperties()
        {
            return $this->laravel['config']->get('swagger-generator.model_camel_case_properties', false);
        }

        protected function getAttributeReturnType(Model $model, string $method): Collection
        {
            /** @var Attribute $attribute */
            $attribute = $model->{$method}();

            return collect([
                'get' => $attribute->get ? optional(new \ReflectionFunction($attribute->get))->getReturnType() : null,
                'set' => $attribute->set ? optional(new \ReflectionFunction($attribute->set))->getReturnType() : null,
            ])
                ->filter()
                ->map(function ($type) {
                    if ($type instanceof \ReflectionUnionType) {
                        $types = collect($type->getTypes())
                            /** @var ReflectionType $reflectionType */
                            ->map(
                                function ($reflectionType) {
                                    return collect($this->extractReflectionTypes($reflectionType));
                                }
                            )
                            ->flatten();
                    } else {
                        $types = collect($this->extractReflectionTypes($type));
                    }

                    if ($type->allowsNull()) {
                        $types->push('null');
                    }

                    return $types->join('|');
                });
        }

        protected function getReturnType(\ReflectionMethod $reflection): ?string
        {
            $type = $this->getReturnTypeFromDocBlock($reflection);
            if ($type) {
                return $type;
            }

            return $this->getReturnTypeFromReflection($reflection);
        }

        /**
         * Get method comment based on it DocBlock comment
         *
         * @param \ReflectionMethod $reflection
         *
         * @return null|string
         */
        protected function getCommentFromDocBlock(\ReflectionMethod $reflection)
        {
            $phpDocContext = (new ContextFactory())->createFromReflector($reflection);
            $context = new Context(
                $phpDocContext->getNamespace(),
                $phpDocContext->getNamespaceAliases()
            );
            $comment = '';
            $phpdoc = new DocBlock($reflection, $context);

            if ($phpdoc->hasTag('comment')) {
                $comment = $phpdoc->getTagsByName('comment')[0]->getContent();
            }

            return $comment;
        }

        /**
         * Get method return type based on it DocBlock comment
         *
         * @param \ReflectionMethod $reflection
         *
         * @return null|string
         */
        protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection, \Reflector $reflectorForContext = null)
        {
            $phpDocContext = (new ContextFactory())->createFromReflector($reflectorForContext ?? $reflection);
            $context = new Context(
                $phpDocContext->getNamespace(),
                $phpDocContext->getNamespaceAliases()
            );
            $type = null;
            $phpdoc = new DocBlock($reflection, $context);

            if ($phpdoc->hasTag('return')) {
                $type = $phpdoc->getTagsByName('return')[0]->getType();
            }

            return $type;
        }

        protected function getReturnTypeFromReflection(\ReflectionMethod $reflection): ?string
        {
            $returnType = $reflection->getReturnType();
            if (!$returnType) {
                return null;
            }

            $types = $this->extractReflectionTypes($returnType);

            $type = implode('|', $types);

            if ($returnType->allowsNull()) {
                $type .= '|null';
            }

            return $type;
        }




        /**
         * @param ReflectionClass $reflection
         * @return string
         */
        protected function getClassKeyword(ReflectionClass $reflection)
        {
            if ($reflection->isFinal()) {
                $keyword = 'final ';
            } elseif ($reflection->isAbstract()) {
                $keyword = 'abstract ';
            } else {
                $keyword = '';
            }

            return $keyword;
        }

        protected function isInboundCast(string $type): bool
        {
            return class_exists($type) && is_subclass_of($type, CastsInboundAttributes::class);
        }

        protected function checkForCastableCasts(string $type, array $params = []): string
        {
            if (!class_exists($type) || !interface_exists(Castable::class)) {
                return $type;
            }

            $reflection = new \ReflectionClass($type);

            if (!$reflection->implementsInterface(Castable::class)) {
                return $type;
            }

            $cast = call_user_func([$type, 'castUsing'], $params);

            if (is_string($cast) && !is_object($cast)) {
                return $cast;
            }

            $castReflection = new ReflectionObject($cast);

            $methodReflection = $castReflection->getMethod('get');

            return $this->getReturnTypeFromReflection($methodReflection) ??
                $this->getReturnTypeFromDocBlock($methodReflection, $reflection) ??
                $type;
        }

        /**
         * @param  string  $type
         * @return string|null
         * @throws \ReflectionException
         */
        protected function checkForCustomLaravelCasts(string $type): ?string
        {
            if (!class_exists($type) || !interface_exists(CastsAttributes::class)) {
                return $type;
            }

            $reflection = new \ReflectionClass($type);

            if (!$reflection->implementsInterface(CastsAttributes::class)) {
                return $type;
            }

            $methodReflection = new \ReflectionMethod($type, 'get');

            $reflectionType = $this->getReturnTypeFromReflection($methodReflection);

            if ($reflectionType === null) {
                $reflectionType = $this->getReturnTypeFromDocBlock($methodReflection);
            }

            if ($reflectionType === 'static' || $reflectionType === '$this') {
                $reflectionType = $type;
            }

            return $reflectionType;
        }

        protected function getTypeInModel(object $model, ?string $type): ?string
        {
            if ($type === null) {
                return null;
            }

            if (class_exists($type)) {
                $type = $this->getClassNameInDestinationFile($model, $type);
            }

            return $type;
        }

        protected function getClassNameInDestinationFile(object $model, string $className): string
        {
            $className = trim($className, '\\');
            return '\\' . $className;

        }



        protected function getParamType(\ReflectionMethod $method, \ReflectionParameter $parameter): ?string
        {
            if ($paramType = $parameter->getType()) {
                $types = $this->extractReflectionTypes($paramType);

                $type = implode('|', $types);

                if ($paramType->allowsNull()) {
                    if (count($types) == 1) {
                        $type = '?' . $type;
                    } else {
                        $type .= '|null';
                    }
                }

                return $type;
            }

            $docComment = $method->getDocComment();

            if (!$docComment) {
                return null;
            }

            preg_match(
                '/@param ((?:(?:[\w?|\\\\<>])+(?:\[])?)+)/',
                $docComment ?? '',
                $matches
            );
            $type = $matches[1] ?? '';

            if (strpos($type, '|') !== false) {
                $types = explode('|', $type);

                // if we have more than 2 types
                // we return null as we cannot use unions in php yet
                if (count($types) > 2) {
                    return null;
                }

                $hasNull = false;

                foreach ($types as $currentType) {
                    if ($currentType === 'null') {
                        $hasNull = true;
                        continue;
                    }

                    // if we didn't find null assign the current type to the type we want
                    $type = $currentType;
                }

                // if we haven't found null type set
                // we return null as we cannot use unions with different types yet
                if (!$hasNull) {
                    return null;
                }

                $type = '?' . $type;
            }

            // convert to proper type hint types in php
            $type = str_replace(['boolean', 'integer'], ['bool', 'int'], $type);

            $allowedTypes = [
                'int',
                'bool',
                'string',
                'float',
            ];

            // we replace the ? with an empty string so we can check the actual type
            if (!in_array(str_replace('?', '', $type), $allowedTypes)) {
                return null;
            }

            // if we have a match on index 1
            // then we have found the type of the variable if not we return null
            return $type;
        }

        protected function extractReflectionTypes(ReflectionType $reflection_type)
        {
            if ($reflection_type instanceof ReflectionNamedType) {
                $types[] = $this->getReflectionNamedType($reflection_type);
            } else {
                $types = [];
                foreach ($reflection_type->getTypes() as $named_type) {
                    if ($named_type->getName() === 'null') {
                        continue;
                    }

                    $types[] = $this->getReflectionNamedType($named_type);
                }
            }

            return $types;
        }

        protected function getReflectionNamedType(ReflectionNamedType $paramType): string
        {
            $parameterName = $paramType->getName();
            if (!$paramType->isBuiltin()) {
                $parameterName = '\\' . $parameterName;
            }

            return $parameterName;
        }




    }
