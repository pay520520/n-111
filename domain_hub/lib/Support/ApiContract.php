<?php

declare(strict_types=1);

class CfApiContract
{
    public static function routeKey(string $endpoint, ?string $action): string
    {
        return strtolower(trim($endpoint)) . ':' . strtolower(trim((string) $action));
    }

    public static function discoverRoutes(string $handlerPath): array
    {
        $content = @file_get_contents($handlerPath);
        if (!is_string($content) || $content === '') {
            return self::defaultRoutes();
        }

        $routes = [];
        preg_match_all(
            '/(?:if|elseif)\s*\(\$endpoint\s*===\s*\'([^\']+)\'([^\)]*)\)\s*\{/m',
            $content,
            $blocks,
            PREG_OFFSET_CAPTURE
        );

        $count = count($blocks[0] ?? []);
        for ($i = 0; $i < $count; $i++) {
            $full = (string) ($blocks[0][$i][0] ?? '');
            $offset = (int) ($blocks[0][$i][1] ?? 0);
            $endpoint = strtolower(trim((string) ($blocks[1][$i][0] ?? '')));
            if ($endpoint === '') {
                continue;
            }

            $nextOffset = $i + 1 < $count ? (int) ($blocks[0][$i + 1][1] ?? strlen($content)) : strlen($content);
            $segmentStart = $offset + strlen($full);
            $segmentLength = max(0, $nextOffset - $segmentStart);
            $segment = substr($content, $segmentStart, $segmentLength);
            if (!is_string($segment)) {
                $segment = '';
            }

            foreach (self::extractRoutesFromCondition($endpoint, $full) as $route) {
                $routes[] = $route;
            }
            foreach (self::extractRoutesFromBody($endpoint, $segment) as $route) {
                $routes[] = $route;
            }

            if ($endpoint === 'whois') {
                $routes[] = ['endpoint' => 'whois', 'action' => null, 'methods' => ['GET']];
            }
        }

        $normalized = self::normalizeRoutes($routes);
        if (empty($normalized)) {
            return self::defaultRoutes();
        }

        return $normalized;
    }

    public static function metadataForRoute(string $endpoint, ?string $action): array
    {
        $map = self::metadataMap();
        $key = self::routeKey($endpoint, $action);
        return $map[$key] ?? [
            'summary' => strtoupper($endpoint) . ' ' . strtoupper((string) ($action ?? 'query')),
            'description' => 'Auto-discovered API operation',
            'auth_required' => true,
            'query_parameters' => [],
            'request_body' => null,
        ];
    }

    public static function defaultRoutes(): array
    {
        return self::normalizeRoutes([
            ['endpoint' => 'subdomains', 'action' => 'list', 'methods' => ['GET']],
            ['endpoint' => 'subdomains', 'action' => 'register', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'subdomains', 'action' => 'get', 'methods' => ['GET']],
            ['endpoint' => 'subdomains', 'action' => 'delete', 'methods' => ['POST', 'DELETE']],
            ['endpoint' => 'subdomains', 'action' => 'renew', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'dns_records', 'action' => 'list', 'methods' => ['GET']],
            ['endpoint' => 'dns_records', 'action' => 'create', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'dns_records', 'action' => 'update', 'methods' => ['POST', 'PUT', 'PATCH']],
            ['endpoint' => 'dns_records', 'action' => 'delete', 'methods' => ['POST', 'DELETE']],
            ['endpoint' => 'permanent_upgrade', 'action' => 'list', 'methods' => ['GET']],
            ['endpoint' => 'permanent_upgrade', 'action' => 'create', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'permanent_upgrade', 'action' => 'assist', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'permanent_upgrade', 'action' => 'cancel', 'methods' => ['POST', 'DELETE']],
            ['endpoint' => 'keys', 'action' => 'list', 'methods' => ['GET']],
            ['endpoint' => 'keys', 'action' => 'create', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'keys', 'action' => 'delete', 'methods' => ['POST', 'DELETE']],
            ['endpoint' => 'keys', 'action' => 'regenerate', 'methods' => ['POST', 'PUT']],
            ['endpoint' => 'quota', 'action' => null, 'methods' => ['GET']],
            ['endpoint' => 'whois', 'action' => null, 'methods' => ['GET']],
        ]);
    }

    private static function extractRoutesFromCondition(string $endpoint, string $condition): array
    {
        $routes = [];

        if (preg_match('/\$action\s*===\s*\'([^\']+)\'/i', $condition, $actionMatch)) {
            $action = strtolower(trim((string) ($actionMatch[1] ?? '')));
            $methods = self::extractMethods($condition);
            if (!empty($methods)) {
                $routes[] = ['endpoint' => $endpoint, 'action' => $action, 'methods' => $methods];
            }
            return $routes;
        }

        $methods = self::extractMethods($condition);
        if (!empty($methods)) {
            $routes[] = ['endpoint' => $endpoint, 'action' => null, 'methods' => $methods];
        }

        return $routes;
    }

    private static function extractRoutesFromBody(string $endpoint, string $segment): array
    {
        $routes = [];

        $patternA = '/\$action\s*===\s*\'([^\']+)\'\s*&&\s*\$method\s*===\s*\'([^\']+)\'/i';
        preg_match_all($patternA, $segment, $matchesA, PREG_SET_ORDER);
        foreach ($matchesA as $match) {
            $routes[] = [
                'endpoint' => $endpoint,
                'action' => strtolower(trim((string) ($match[1] ?? ''))),
                'methods' => [strtoupper(trim((string) ($match[2] ?? 'GET')))],
            ];
        }

        $patternB = '/\$action\s*===\s*\'([^\']+)\'\s*&&\s*in_array\(\$method,\s*\[([^\]]+)\]/i';
        preg_match_all($patternB, $segment, $matchesB, PREG_SET_ORDER);
        foreach ($matchesB as $match) {
            $routes[] = [
                'endpoint' => $endpoint,
                'action' => strtolower(trim((string) ($match[1] ?? ''))),
                'methods' => self::extractMethods((string) ($match[2] ?? '')),
            ];
        }

        $patternC = '/in_array\(\$method,\s*\[([^\]]+)\]\)\s*&&\s*\$action\s*===\s*\'([^\']+)\'/i';
        preg_match_all($patternC, $segment, $matchesC, PREG_SET_ORDER);
        foreach ($matchesC as $match) {
            $routes[] = [
                'endpoint' => $endpoint,
                'action' => strtolower(trim((string) ($match[2] ?? ''))),
                'methods' => self::extractMethods((string) ($match[1] ?? '')),
            ];
        }

        return $routes;
    }

    private static function extractMethods(string $source): array
    {
        $methods = [];
        preg_match_all('/\'([A-Z]+)\'/i', $source, $matches);
        foreach (($matches[1] ?? []) as $value) {
            $method = strtoupper(trim((string) $value));
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $methods[] = $method;
            }
        }

        $methods = array_values(array_unique($methods));
        sort($methods);
        return $methods;
    }

    private static function normalizeRoutes(array $routes): array
    {
        $normalized = [];
        foreach ($routes as $route) {
            $endpoint = strtolower(trim((string) ($route['endpoint'] ?? '')));
            if ($endpoint === '' || $endpoint === 'docs') {
                continue;
            }
            $actionRaw = $route['action'] ?? null;
            $action = $actionRaw === null ? null : strtolower(trim((string) $actionRaw));
            if ($action === '') {
                $action = null;
            }

            $methods = $route['methods'] ?? [];
            if (!is_array($methods) || empty($methods)) {
                continue;
            }
            $methods = array_values(array_unique(array_map(static function ($value): string {
                return strtoupper(trim((string) $value));
            }, $methods)));
            sort($methods);

            $key = $endpoint . '|' . (string) ($action ?? '-') . '|' . implode(',', $methods);
            $normalized[$key] = [
                'endpoint' => $endpoint,
                'action' => $action,
                'methods' => $methods,
            ];
        }

        return array_values($normalized);
    }

    private static function metadataMap(): array
    {
        return [
            'subdomains:list' => [
                'summary' => 'List subdomains',
                'description' => 'Returns current API key owner subdomains with pagination and filters.',
                'auth_required' => true,
                'query_parameters' => [
                    ['name' => 'page', 'type' => 'integer', 'required' => false, 'description' => 'Page number, starts from 1'],
                    ['name' => 'per_page', 'type' => 'integer', 'required' => false, 'description' => 'Page size, max 500'],
                    ['name' => 'search', 'type' => 'string', 'required' => false, 'description' => 'Search keyword'],
                    ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'active / suspended / expired'],
                ],
                'request_body' => null,
            ],
            'subdomains:register' => [
                'summary' => 'Register subdomain',
                'description' => 'Registers a new subdomain under an active root domain.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['subdomain', 'rootdomain'],
                    'properties' => [
                        'subdomain' => ['type' => 'string', 'description' => 'Subdomain prefix'],
                        'rootdomain' => ['type' => 'string', 'description' => 'Root domain'],
                    ],
                ],
            ],
            'subdomains:get' => [
                'summary' => 'Get subdomain detail',
                'description' => 'Returns one subdomain and linked DNS records.',
                'auth_required' => true,
                'query_parameters' => [
                    ['name' => 'subdomain_id', 'type' => 'integer', 'required' => true, 'description' => 'Subdomain ID'],
                ],
                'request_body' => null,
            ],
            'subdomains:delete' => [
                'summary' => 'Delete subdomain',
                'description' => 'Deletes a subdomain (subject to runtime switches and domain policy).',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['subdomain_id'],
                    'properties' => [
                        'subdomain_id' => ['type' => 'integer', 'description' => 'Subdomain ID'],
                    ],
                ],
            ],
            'subdomains:renew' => [
                'summary' => 'Renew subdomain',
                'description' => 'Renews an expiring subdomain following grace/redemption policies.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['subdomain_id'],
                    'properties' => [
                        'subdomain_id' => ['type' => 'integer', 'description' => 'Subdomain ID'],
                    ],
                ],
            ],
            'dns_records:list' => [
                'summary' => 'List DNS records',
                'description' => 'Lists DNS records for a specified subdomain.',
                'auth_required' => true,
                'query_parameters' => [
                    ['name' => 'subdomain_id', 'type' => 'integer', 'required' => true, 'description' => 'Subdomain ID'],
                ],
                'request_body' => null,
            ],
            'dns_records:create' => [
                'summary' => 'Create DNS record',
                'description' => 'Creates DNS records including A/AAAA/CNAME/MX/TXT/NS/SRV/CAA.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['subdomain_id', 'type'],
                    'properties' => [
                        'subdomain_id' => ['type' => 'integer', 'description' => 'Subdomain ID'],
                        'type' => ['type' => 'string', 'description' => 'A/AAAA/CNAME/MX/TXT/NS/SRV/CAA'],
                        'name' => ['type' => 'string', 'description' => 'Record name, @ means root of subdomain'],
                        'content' => ['type' => 'string', 'description' => 'Record content'],
                        'ttl' => ['type' => 'integer', 'description' => 'TTL seconds'],
                    ],
                ],
            ],
            'dns_records:update' => [
                'summary' => 'Update DNS record',
                'description' => 'Updates an existing DNS record by id or record_id.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => [],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Local record ID'],
                        'record_id' => ['type' => 'string', 'description' => 'Provider record ID'],
                        'type' => ['type' => 'string', 'description' => 'Target record type'],
                        'name' => ['type' => 'string', 'description' => 'Target record name'],
                        'content' => ['type' => 'string', 'description' => 'Target content'],
                        'ttl' => ['type' => 'integer', 'description' => 'TTL seconds'],
                    ],
                ],
            ],
            'dns_records:delete' => [
                'summary' => 'Delete DNS record',
                'description' => 'Deletes a DNS record by local id or provider record_id.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => [],
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Local record ID'],
                        'record_id' => ['type' => 'string', 'description' => 'Provider record ID'],
                    ],
                ],
            ],
            'permanent_upgrade:list' => [
                'summary' => 'List permanent upgrade state',
                'description' => 'Returns permanent upgrade requests, assist logs and eligible domains.',
                'auth_required' => true,
                'query_parameters' => [
                    ['name' => 'page', 'type' => 'integer', 'required' => false, 'description' => 'Page number'],
                    ['name' => 'per_page', 'type' => 'integer', 'required' => false, 'description' => 'Page size, max 50'],
                ],
                'request_body' => null,
            ],
            'permanent_upgrade:create' => [
                'summary' => 'Create permanent upgrade request',
                'description' => 'Create or reuse a pending upgrade request and return assist code.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['subdomain_id'],
                    'properties' => [
                        'subdomain_id' => ['type' => 'integer', 'description' => 'Target subdomain ID'],
                    ],
                ],
            ],
            'permanent_upgrade:assist' => [
                'summary' => 'Assist permanent upgrade by code',
                'description' => 'Submit an assist code to help another user upgrade to permanent.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['assist_code'],
                    'properties' => [
                        'assist_code' => ['type' => 'string', 'description' => 'Assist code'],
                    ],
                ],
            ],
            'permanent_upgrade:cancel' => [
                'summary' => 'Cancel permanent upgrade request',
                'description' => 'Cancel a pending upgrade request created by current user.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['request_id'],
                    'properties' => [
                        'request_id' => ['type' => 'integer', 'description' => 'Upgrade request ID'],
                    ],
                ],
            ],
            'keys:list' => [
                'summary' => 'List API keys',
                'description' => 'Lists API keys owned by current user.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => null,
            ],
            'keys:create' => [
                'summary' => 'Create API key',
                'description' => 'Creates a new API key/secret pair for the current user.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => ['key_name'],
                    'properties' => [
                        'key_name' => ['type' => 'string', 'description' => 'API key display name'],
                        'ip_whitelist' => ['type' => 'string', 'description' => 'Comma/newline separated IP entries'],
                    ],
                ],
            ],
            'keys:delete' => [
                'summary' => 'Delete API key',
                'description' => 'Deletes one API key by key_id or id.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => [],
                    'properties' => [
                        'key_id' => ['type' => 'integer', 'description' => 'API key ID'],
                        'id' => ['type' => 'integer', 'description' => 'API key ID'],
                    ],
                ],
            ],
            'keys:regenerate' => [
                'summary' => 'Regenerate API secret',
                'description' => 'Regenerates API secret for an existing key.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => [
                    'required' => [],
                    'properties' => [
                        'key_id' => ['type' => 'integer', 'description' => 'API key ID'],
                        'id' => ['type' => 'integer', 'description' => 'API key ID'],
                    ],
                ],
            ],
            'quota:' => [
                'summary' => 'Get user quota',
                'description' => 'Returns quota usage for current API key user.',
                'auth_required' => true,
                'query_parameters' => [],
                'request_body' => null,
            ],
            'whois:' => [
                'summary' => 'WHOIS lookup',
                'description' => 'Looks up a domain WHOIS profile. Can be public depending on runtime setting.',
                'auth_required' => false,
                'query_parameters' => [
                    ['name' => 'domain', 'type' => 'string', 'required' => true, 'description' => 'Domain to query'],
                ],
                'request_body' => null,
            ],
        ];
    }
}
