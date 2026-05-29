<?php

declare(strict_types=1);

class CfOpenApiSpec
{
    public static function build(string $baseUrl, string $handlerPath): array
    {
        $routes = CfApiContract::discoverRoutes($handlerPath);
        $paths = [];

        foreach ($routes as $route) {
            $endpoint = strtolower(trim((string) ($route['endpoint'] ?? '')));
            if ($endpoint === '') {
                continue;
            }
            $actionRaw = $route['action'] ?? null;
            $action = $actionRaw === null ? null : strtolower(trim((string) $actionRaw));
            if ($action === '') {
                $action = null;
            }

            $path = self::buildDocPath($endpoint, $action);
            $methods = $route['methods'] ?? [];
            if (!is_array($methods) || empty($methods)) {
                continue;
            }

            foreach ($methods as $method) {
                $methodUpper = strtoupper(trim((string) $method));
                if (!in_array($methodUpper, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }
                $paths[$path][strtolower($methodUpper)] = self::buildOperation($endpoint, $action, $methodUpper, $baseUrl);
            }
        }

        $paths['/docs/openapi'] = [
            'get' => [
                'tags' => ['Documentation'],
                'summary' => 'Get OpenAPI JSON specification',
                'description' => 'Returns auto-generated OpenAPI specification based on API handler implementation.',
                'responses' => [
                    '200' => [
                        'description' => 'OpenAPI JSON',
                    ],
                ],
                'x-whmcs-api' => [
                    'query' => [
                        'm' => defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub',
                        'endpoint' => 'docs',
                        'format' => 'openapi',
                    ],
                    'example_url' => self::buildExampleUrl($baseUrl, 'docs', null, ['format' => 'openapi']),
                ],
            ],
        ];

        $paths['/docs/swagger'] = [
            'get' => [
                'tags' => ['Documentation'],
                'summary' => 'Get Swagger UI',
                'description' => 'Returns interactive Swagger UI powered by the auto-generated OpenAPI specification.',
                'responses' => [
                    '200' => [
                        'description' => 'Swagger UI page',
                    ],
                ],
                'x-whmcs-api' => [
                    'query' => [
                        'm' => defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub',
                        'endpoint' => 'docs',
                        'action' => 'swagger',
                    ],
                    'example_url' => self::buildExampleUrl($baseUrl, 'docs', 'swagger'),
                ],
            ],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Domain Hub API',
                'description' => 'Auto-generated API specification for WHMCS Domain Hub module.',
                'version' => '2.0.0',
            ],
            'servers' => [
                [
                    'url' => rtrim($baseUrl, '/'),
                    'description' => 'Detected from current request',
                ],
            ],
            'tags' => [
                ['name' => 'Subdomains'],
                ['name' => 'DNS Records'],
                ['name' => 'API Keys'],
                ['name' => 'Quota'],
                ['name' => 'WHOIS'],
                ['name' => 'Documentation'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                    'ApiSecretAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Secret',
                    ],
                ],
                'schemas' => [
                    'ApiErrorResponse' => [
                        'type' => 'object',
                        'required' => ['success', 'error_code', 'message'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'error_code' => ['type' => 'string', 'example' => 'bad_request'],
                            'message' => ['type' => 'string', 'example' => 'Bad request'],
                            'details' => ['type' => 'object', 'additionalProperties' => true],
                            'error' => ['type' => 'string', 'example' => 'Bad request'],
                        ],
                    ],
                    'ApiSuccessResponse' => [
                        'type' => 'object',
                        'required' => ['success'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'message' => ['type' => 'string', 'example' => 'OK'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
            ],
        ];
    }

    private static function buildOperation(string $endpoint, ?string $action, string $method, string $baseUrl): array
    {
        $metadata = CfApiContract::metadataForRoute($endpoint, $action);
        $tag = self::resolveTag($endpoint);
        $operationId = self::buildOperationId($endpoint, $action, $method);
        $description = trim((string) ($metadata['description'] ?? ''));
        $description .= "\n\nActual request uses WHMCS query parameters: m, endpoint and action.";

        $operation = [
            'tags' => [$tag],
            'summary' => (string) ($metadata['summary'] ?? strtoupper($endpoint) . ' ' . strtoupper((string) ($action ?? 'query'))),
            'description' => $description,
            'operationId' => $operationId,
            'parameters' => self::buildParameters($endpoint, $action, $metadata),
            'responses' => self::buildResponses(),
            'x-whmcs-api' => [
                'query' => self::buildWhmcsQuery($endpoint, $action),
                'example_url' => self::buildExampleUrl($baseUrl, $endpoint, $action),
            ],
        ];

        if (!empty($metadata['auth_required'])) {
            $operation['security'] = [
                ['ApiKeyAuth' => []],
                ['ApiSecretAuth' => []],
            ];
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $requestBody = self::buildRequestBody($metadata);
            if ($requestBody !== null) {
                $operation['requestBody'] = $requestBody;
            }
        }

        return $operation;
    }

    private static function buildResponses(): array
    {
        return [
            '200' => [
                'description' => 'Success response',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/ApiSuccessResponse',
                        ],
                    ],
                ],
            ],
            'default' => [
                'description' => 'Error response',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/ApiErrorResponse',
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function buildParameters(string $endpoint, ?string $action, array $metadata): array
    {
        $params = [
            [
                'name' => 'm',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'string', 'default' => defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub'],
                'description' => 'WHMCS addon module slug',
            ],
            [
                'name' => 'endpoint',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'string', 'default' => $endpoint],
                'description' => 'Domain Hub endpoint name',
            ],
        ];

        if ($action !== null) {
            $params[] = [
                'name' => 'action',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'string', 'default' => $action],
                'description' => 'Domain Hub endpoint action',
            ];
        }

        foreach (($metadata['query_parameters'] ?? []) as $queryParameter) {
            $name = trim((string) ($queryParameter['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $params[] = [
                'name' => $name,
                'in' => 'query',
                'required' => !empty($queryParameter['required']),
                'schema' => [
                    'type' => (string) ($queryParameter['type'] ?? 'string'),
                ],
                'description' => (string) ($queryParameter['description'] ?? ''),
            ];
        }

        return $params;
    }

    private static function buildRequestBody(array $metadata): ?array
    {
        $config = $metadata['request_body'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $properties = [];
        foreach (($config['properties'] ?? []) as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $properties[(string) $name] = [
                'type' => (string) ($definition['type'] ?? 'string'),
                'description' => (string) ($definition['description'] ?? ''),
            ];
        }

        return [
            'required' => !empty($config['required']),
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'required' => array_values(array_map('strval', $config['required'] ?? [])),
                        'properties' => $properties,
                    ],
                ],
            ],
        ];
    }

    private static function resolveTag(string $endpoint): string
    {
        $map = [
            'subdomains' => 'Subdomains',
            'dns_records' => 'DNS Records',
            'keys' => 'API Keys',
            'quota' => 'Quota',
            'whois' => 'WHOIS',
            'docs' => 'Documentation',
        ];

        return $map[$endpoint] ?? 'API';
    }

    private static function buildOperationId(string $endpoint, ?string $action, string $method): string
    {
        $chunks = [strtolower($method), $endpoint];
        if ($action !== null && $action !== '') {
            $chunks[] = $action;
        }
        $id = implode('_', $chunks);
        return preg_replace('/[^a-z0-9_]+/i', '_', $id) ?: strtolower($method) . '_' . $endpoint;
    }

    private static function buildDocPath(string $endpoint, ?string $action): string
    {
        if ($action === null || $action === '') {
            return '/' . $endpoint;
        }
        return '/' . $endpoint . '/' . $action;
    }

    private static function buildWhmcsQuery(string $endpoint, ?string $action): array
    {
        $query = [
            'm' => defined('CF_MODULE_NAME') ? CF_MODULE_NAME : 'domain_hub',
            'endpoint' => $endpoint,
        ];
        if ($action !== null) {
            $query['action'] = $action;
        }
        return $query;
    }

    private static function buildExampleUrl(string $baseUrl, string $endpoint, ?string $action, array $extraQuery = []): string
    {
        $query = self::buildWhmcsQuery($endpoint, $action);
        foreach ($extraQuery as $key => $value) {
            $query[$key] = $value;
        }

        return rtrim($baseUrl, '/') . '/index.php?' . http_build_query($query);
    }
}
