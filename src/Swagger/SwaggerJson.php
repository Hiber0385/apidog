<?php
namespace Hyperf\Apidog\Swagger;

use Hyperf\Apidog\Annotation\ApiResponse;
use Hyperf\Apidog\Annotation\Body;
use Hyperf\Apidog\Annotation\Param;
use Hyperf\Apidog\ApiAnnotation;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Annotation\Mapping;
use Hyperf\Utils\ApplicationContext;

class SwaggerJson
{

    public $config;

    public $swagger;

    public function __construct()
    {
        $this->config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $this->swagger = $this->config->get('swagger');
    }

    public function addPath($className, $methodName, $prefix)
    {
        $classAnnotation = ApiAnnotation::classMetadata($className);
        $methodAnnotations = ApiAnnotation::methodMetadata($className, $methodName);
        $params = [];
        $responses = [];
        /** @var \Hyperf\Apidog\Annotation\GetApi $mapping */
        $mapping = null;
        foreach ($methodAnnotations as $option) {
            if ($option instanceof Mapping) {
                $mapping = $option;
            }
            if ($option instanceof Param) {
                $params[] = $option;
            }
            if ($option instanceof ApiResponse) {
                $responses[] = $option;
            }
        }
        $tag = $classAnnotation->tag ?: $className;
        if ($tag == 'swagger') {
            return;
        }
        $this->swagger['tags'][$tag] = [
            'name' => $tag,
            'description' => $classAnnotation->description,
        ];

        $path = $mapping->path;
        if ($path === '') {
            $path = $prefix;
        } elseif ($path[0] !== '/') {
            $path = $prefix . '/' . $path;
        }
        $method = strtolower($mapping->methods[0]);
        $this->swagger['paths'][$path][$method] = [
            'tags' => [
                $tag,
            ],
            'summary' => $mapping->summary,
            'parameters' => $this->makeParameters($params, $path),
            'consumes' => [
                "application/json",
            ],
            'produces' => [
                "application/json",
            ],
            'responses' => $this->makeResponses($responses, $path, $method),
            'description' => $mapping->description,
        ];

    }

    public function basePath($className)
    {
        $path = strtolower($className);
        $path = str_replace('\\', '/', $path);
        $path = str_replace('app/controller', '', $path);
        $path = str_replace('controller', '', $path);
        return $path;
    }

    public function initModel()
    {
        $arraySchema = [
            'type' => 'array',
            'required' => [],
            'items' => [
                'type' => 'string'
            ],
        ];
        $objectSchema = [
            'type' => 'object',
            'required' => [],
            'items' => [
                'type' => 'string'
            ],
        ];

        $this->swagger['definitions']['ModelArray'] = $arraySchema;
        $this->swagger['definitions']['ModelObject'] = $objectSchema;
    }

    public function rules2schema($rules)
    {
        $schema = [
            'type' => 'object',
            'required' => [],
            'properties' => [],
        ];
        foreach ($rules as $field => $rule) {
            $property = [];
            $fieldNameLabel = explode('|', $field);
            $fieldName = $fieldNameLabel[0];
            if (!is_array($rule)) {
                $type = $this->getTypeByRule($rule);
            } else {
                //TODO 结构体多层
                $type = 'string';
            }
            if ($type == 'array') {
                $property['$ref'] = '#/definitions/ModelArray';;
            }
            if ($type == 'object') {
                $property['$ref'] = '#/definitions/ModelObject';;
            }
            $property['type'] = $type;
            $property['description'] = $fieldNameLabel[1] ?? '';
            $schema['properties'][$fieldName] = $property;
        }

        return $schema;
    }

    public function getTypeByRule($rule)
    {
        $default = explode('|', preg_replace('/\[.*\]/', '', $rule));
        if (array_intersect($default, ['int', 'lt', 'gt', 'ge'])) {
            return 'integer';
        }
        if (array_intersect($default, ['array'])) {
            return 'array';
        }
        if (array_intersect($default, ['object'])) {
            return 'object';
        }
        return 'string';
    }

    public function makeParameters($params, $path)
    {
        $this->initModel();
        $path = str_replace(['{', '}'], '', $path);
        $parameters = [];
        /** @var \Hyperf\Apidog\Annotation\Query $item */
        foreach ($params as $item) {
            $parameters[$item->name] = [
                'in' => $item->in,
                'name' => $item->name,
                'description' => $item->description,
                'required' => $item->required,
                'type' => $item->type,
                'default' => $item->default,
            ];
            if ($item instanceof Body) {
                $modelName = implode('', array_map('ucfirst', explode('/', $path)));
                $schema = $this->rules2schema($item->rules);
                $this->swagger['definitions'][$modelName] = $schema;
                $parameters[$item->name]['schema']['$ref'] = '#/definitions/' . $modelName;
            }
        }

        return array_values($parameters);
    }

    public function makeResponses($responses, $path, $method)
    {
        $path = str_replace(['{', '}'], '', $path);
        $resp = [];
        /** @var ApiResponse $item */
        foreach ($responses as $item) {
            $resp[$item->code] = [
                'description' => $item->description,
            ];
            if ($item->schema) {
                $modelName = implode('', array_map('ucfirst', explode('/', $path))) . ucfirst($method) .'Response' . $item->code;
                $ret = $this->responseSchemaTodefinition($item->schema, $modelName);
                if ($ret) {
                    $resp[$item->code]['schema']['$ref'] = '#/definitions/' . $modelName;
                }
            }
        }

        return $resp;
    }

    public function responseSchemaTodefinition($schema, $modelName, $level = 0)
    {
        if (!$schema) {
            return false;
        }
        $definition = [];
        foreach ($schema as $key => $val) {
            $_key = str_replace('_', '', $key);
            $property = [];
            $property['type'] = gettype($val);
            if (is_array($val)) {
                $definitionName = $modelName . ucfirst($_key);
                if ($property['type'] == 'array' && isset($val[0])) {
                    if (is_array($val[0])) {
                        $property['type'] = 'array';
                        $ret = $this->responseSchemaTodefinition($val[0], $definitionName, 1);
                        $property['items']['$ref'] = '#/definitions/' . $definitionName;
                    } else {
                        $property['type'] = 'array';
                        $property['items']['type'] = gettype($val[0]);
                    }
                } else {
                    $property['type'] = 'object';
                    $ret = $this->responseSchemaTodefinition($val, $definitionName, 1);
                    $property['$ref'] = '#/definitions/' . $definitionName;
                }
                if (isset($ret)) {
                    $this->swagger['definitions'][$definitionName] = $ret;
                }
            } else {
                $property['default'] = $val;
            }
            $definition['properties'][$key] = $property;
        }
        if ($level === 0) {
            $this->swagger['definitions'][$modelName] = $definition;
        }

        return $definition;
    }

    public function save()
    {
        $this->swagger['tags'] = array_values($this->swagger['tags'] ?? []);
        $outputFile = $this->swagger['output_file'] ?? '';
        if (!$outputFile) {
            return;
        }
        unset($this->swagger['output_file']);
        file_put_contents($outputFile, json_encode($this->swagger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
