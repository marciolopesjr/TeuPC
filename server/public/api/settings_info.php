<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Auth.php';

use TeuPC\Central\Database;
use TeuPC\Central\Auth;

header('Content-Type: application/json');

Auth::requireAuth();

$config = require __DIR__ . '/../../config.php';
$offlineThreshold = $config['offline_threshold_secs'] ?? 15;

$db = Database::getConnection();

function countRows(PDO $db, string $table): int
{
    // Nome de tabela vem do código, nunca do input; sem parâmetro no COUNT(*).
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table}");
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

echo json_encode([
    'offline_threshold_secs' => (int) $offlineThreshold,
    'php_version' => PHP_VERSION,
    'counts' => [
        'machines'   => countRows($db, 'machines'),
        'events'     => countRows($db, 'events_log'),
        'snapshots'  => countRows($db, 'snapshots'),
    ],
    'server_time' => date('Y-m-d H:i:s'),
]);
