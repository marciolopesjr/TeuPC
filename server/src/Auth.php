<?php

namespace TeuPC\Central;

class Auth
{
    public static function configuredKey(): string
    {
        $config = require __DIR__ . '/../config.php';
        return trim((string)($config['api_key'] ?? ''));
    }

    public static function keyMatches(string $candidate): bool
    {
        $expected = self::configuredKey();
        return $expected !== '' && hash_equals($expected, $candidate);
    }

    public static function validateRequest(): bool
    {
        // 1. Header X-TeuPC-Key
        $headerKey = $_SERVER['HTTP_X_TEUPC_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
        if ($headerKey && self::keyMatches($headerKey)) {
            return true;
        }

        // 2. Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            if (self::keyMatches(trim($matches[1]))) {
                return true;
            }
        }

        // 3. Query parameter ?api_key=
        if (isset($_GET['api_key']) && self::keyMatches((string)$_GET['api_key'])) {
            return true;
        }

        // 4. Sessão do dashboard (cookie HttpOnly + SameSite=Strict)
        if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
            self::startSession();
        }
        if (!empty($_SESSION['teupc_dashboard'])) {
            return true;
        }

        return false;
    }

    public static function startSession(): void
    {
        // Sessao longa (30 dias): o operador nao fica re-digitando a API key toda hora.
        $lifetime = 60 * 60 * 24 * 30;
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    // Páginas HTML: manda para o login em vez de devolver JSON.
    public static function requireDashboard(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            self::startSession();
        }
        if (empty($_SESSION['teupc_dashboard'])) {
            header('Location: login.php');
            exit;
        }
    }

    public static function requireAuth(): void
    {
        if (self::configuredKey() === '') {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'API Key não configurada',
                'message' => 'Defina a variável de ambiente TEUPC_API_KEY no servidor da Central.'
            ]);
            exit;
        }

        if (!self::validateRequest()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Não autorizado',
                'message' => 'API Key inválida ou ausente. Forneça o header X-TeuPC-Key ou Authorization: Bearer <token>.'
            ]);
            exit;
        }
    }
}
