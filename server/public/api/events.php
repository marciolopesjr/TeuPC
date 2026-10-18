<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Auth.php';

use TeuPC\Central\Database;
use TeuPC\Central\Auth;

header('Content-Type: application/json');

Auth::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !isset($data['machine_id']) || !isset($data['title'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido ou campos obrigatórios ausentes']);
    exit;
}

$db = Database::getConnection();

$machineId = $data['machine_id'];
$severity = $data['severity'] ?? 'Warning';
$category = $data['category'] ?? 'HardwareAnomaly';
$title = $data['title'];
$details = $data['details'] ?? '';
$now = date('Y-m-d H:i:s');

$stmt = $db->prepare("
    INSERT INTO events_log (machine_id, severity, category, title, details, created_at)
    VALUES (:id, :severity, :cat, :title, :details, :created)
");

$stmt->execute([
    ':id' => $machineId,
    ':severity' => $severity,
    ':cat' => $category,
    ':title' => $title,
    ':details' => $details,
    ':created' => $now,
]);

echo json_encode([
    'success' => true,
    'message' => 'Evento anômalo registrado com sucesso',
    'id' => $db->lastInsertId(),
]);
