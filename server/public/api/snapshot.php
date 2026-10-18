<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Auth.php';

use TeuPC\Central\Database;
use TeuPC\Central\Auth;

header('Content-Type: application/json');

Auth::requireAuth();

$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data || !isset($data['machine_id']) || !isset($data['snapshot'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Payload inválido ou campos ausentes']);
        exit;
    }

    $machineId = $data['machine_id'];
    $snapshotJson = is_string($data['snapshot']) ? $data['snapshot'] : json_encode($data['snapshot']);
    $now = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        INSERT INTO snapshots (machine_id, snapshot_json, created_at)
        VALUES (:id, :json, :created)
    ");

    $stmt->execute([
        ':id' => $machineId,
        ':json' => $snapshotJson,
        ':created' => $now,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Snapshot armazenado com sucesso',
        'id' => $db->lastInsertId(),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $machineId = $_GET['machine_id'] ?? null;
    if ($machineId) {
        $stmt = $db->prepare("SELECT * FROM snapshots WHERE machine_id = :id ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([':id' => $machineId]);
    } else {
        $stmt = $db->query("SELECT * FROM snapshots ORDER BY created_at DESC LIMIT 20");
    }

    $snapshots = $stmt->fetchAll();
    echo json_encode(['snapshots' => $snapshots]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);
