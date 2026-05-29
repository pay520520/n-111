<?php

declare(strict_types=1);

if (!defined('CF_MODULE_NAME')) {
    define('CF_MODULE_NAME', 'domain_hub');
}

require_once dirname(__DIR__) . '/lib/autoload.php';

$baseUrl = isset($argv[1]) ? trim((string) $argv[1]) : 'https://example.com';
if ($baseUrl === '') {
    $baseUrl = 'https://example.com';
}
$outFile = isset($argv[2]) ? trim((string) $argv[2]) : dirname(__DIR__) . '/docs/openapi.json';
if ($outFile === '') {
    $outFile = dirname(__DIR__) . '/docs/openapi.json';
}

$spec = CfOpenApiSpec::build(rtrim($baseUrl, '/'), dirname(__DIR__) . '/api_handler.php');
$json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || $json === '') {
    fwrite(STDERR, "Failed to serialize OpenAPI specification.\n");
    exit(1);
}

if (file_put_contents($outFile, $json . PHP_EOL) === false) {
    fwrite(STDERR, "Failed to write OpenAPI file: {$outFile}\n");
    exit(1);
}

fwrite(STDOUT, "OpenAPI spec generated: {$outFile}\n");
