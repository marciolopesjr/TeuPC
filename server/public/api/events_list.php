<?php

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Auth.php';

use TeuPC\Central\Database;
use TeuPC\Central\Auth;

header('Content-Type: application/json');

Auth::requireAuth();

$db = Database::getConnection();

// Filtros opcionais: maquina, severidade e busca textual (LIKE em title/details/category)
$conditions = [];
$bindings = [];

$machineId = $_GET['machine_id'] ?? '';
if ($machineId !== '') {
    $conditions[] = 'e.machine_id = :machine_id';
    $bindings[':machine_id'] = $machineId;
}

$severity = $_GET['severity'] ?? '';
if ($severity !== '') {
    $conditions[] = 'e.severity = :severity';
    $bindings[':severity'] = $severity;
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $like = '%' . addslashes($q) . '%'; // escapa % e _ para o LIKE
    $conditions[] = '(e.title LIKE :q1 OR e.details LIKE :q2 OR e.category LIKE :q3)';
    $bindings[':q1'] = $like;
    $bindings[':q2'] = $like;
    $bindings[':q3'] = $like;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// LIMIT/OFFSET interpolados como inteiros (cast evita qualquer injecao na paginacao)
$limit = min(max((int)($_GET['limit'] ?? 50), 1), 200);
$offset = max((int)($_GET['offset'] ?? 0), 0);

$stmt = $db->prepare("
    SELECT e.*, m.hostname
    FROM events_log e
    LEFT JOIN machines m ON e.machine_id = m.machine_id
    {$where}
    ORDER BY e.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($bindings);
$events = $stmt->fetchAll();

echo json_encode([
    'events' => $events,
    'count' => count($events),
]);
