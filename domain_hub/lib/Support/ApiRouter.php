<?php

declare(strict_types=1);

class CfApiRouter
{
    public static function isApiRequest(): bool
    {
        if (isset($_GET['api']) || isset($_POST['api'])) {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_API_KEY']) || !empty($_SERVER['HTTP_X_API_SECRET'])) {
            return true;
        }
        if (isset($_REQUEST['endpoint']) && cf_is_module_request()) {
            return true;
        }
        return false;
    }

    public static function dispatch(): void
    {
        try {
            require_once __DIR__ . '/../CloudflareAPI.php';
            require_once __DIR__ . '/../../api_handler.php';
            handleApiRequest();
        } catch (\Throwable $e) {
            if (function_exists('api_json')) {
                api_json(['error' => 'server error'], 500);
            } else {
                while (ob_get_level() > 0) {
                    if (!@ob_end_clean()) {
                        break;
                    }
                }
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store, no-cache, must-revalidate');
                    header('X-Domain-Hub-API-Error: 1');
                }
                echo json_encode([
                    'success' => false,
                    'error_code' => 'server_error',
                    'message' => 'server error',
                    'error' => 'server error',
                    'details' => new \stdClass(),
                ], JSON_UNESCAPED_UNICODE);
            }
        }
        exit;
    }
}
