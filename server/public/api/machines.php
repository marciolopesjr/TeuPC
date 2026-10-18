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

$machineId = $_GET['id'] ?? null;

if ($machineId) {
    // 1. Dados detalhados de 1 máquina
    $stmt = $db->prepare("SELECT * FROM machines WHERE machine_id = :id");
    $stmt->execute([':id' => $machineId]);
    $machine = $stmt->fetch();

    if (!$machine) {
        http_response_code(404);
        echo json_encode(['error' => 'Máquina não encontrada']);
        exit;
    }

    // Calcular status online
    $lastSeen = strtotime($machine['last_seen']);
    $isOnline = (time() - $lastSeen) <= $offlineThreshold;
    $machine['is_online'] = $isOnline;
    $machine['status'] = $isOnline ? 'online' : 'offline';

    // Última telemetria completa
    $stmtLast = $db->prepare("SELECT * FROM telemetry_logs WHERE machine_id = :id ORDER BY id DESC LIMIT 1");
    $stmtLast->execute([':id' => $machineId]);
    $lastLog = $stmtLast->fetch();

    $parsedPayload = null;
    if ($lastLog && !empty($lastLog['payload_json'])) {
        $parsedPayload = json_decode($lastLog['payload_json'], true);
        unset($lastLog['payload_json']); // Economizar tráfego na resposta
    }

    // Histórico das últimas 40 amostras para gráficos
    $stmtHistory = $db->prepare("
        SELECT id, cpu_usage, mem_used, mem_total, gpu_usage, disk_read_kbs, disk_write_kbs, net_rx_kbs, net_tx_kbs, cpu_temp, rapl_watts, load_1m, created_at
        FROM telemetry_logs 
        WHERE machine_id = :id 
        ORDER BY id DESC 
        LIMIT 40
    ");
    $stmtHistory->execute([':id' => $machineId]);
    $history = array_reverse($stmtHistory->fetchAll());

    // Últimos 30 eventos / anomalias
    $stmtEvents = $db->prepare("SELECT * FROM events_log WHERE machine_id = :id ORDER BY id DESC LIMIT 30");
    $stmtEvents->execute([':id' => $machineId]);
    $events = $stmtEvents->fetchAll();

    echo json_encode([
        'machine' => $machine,
        'latest' => $lastLog,
        'details' => $parsedPayload,
        'history' => $history,
        'events' => $events,
    ]);
    exit;
}

// 2. Lista resumida de todas as máquinas
$stmt = $db->query("SELECT * FROM machines ORDER BY last_seen DESC");
$machines = $stmt->fetchAll();

foreach ($machines as &$m) {
    $lastSeen = strtotime($m['last_seen']);
    $isOnline = (time() - $lastSeen) <= $offlineThreshold;
    $m['is_online'] = $isOnline;
    $m['status'] = $isOnline ? 'online' : 'offline';

    // Obter última telemetria rápida
    $stmtQuick = $db->prepare("SELECT cpu_usage, mem_used, mem_total, cpu_temp, rapl_watts, load_1m FROM telemetry_logs WHERE machine_id = :id ORDER BY id DESC LIMIT 1");
    $stmtQuick->execute([':id' => $m['machine_id']]);
    $m['telemetry'] = $stmtQuick->fetch() ?: null;
}

// Alertas recentes globais
$stmtGlobalEvents = $db->query("
    SELECT e.*, m.hostname 
    FROM events_log e 
    LEFT JOIN machines m ON e.machine_id = m.machine_id 
    ORDER BY e.id DESC 
    LIMIT 15
");
$recentEvents = $stmtGlobalEvents->fetchAll();

echo json_encode([
    'machines' => $machines,
    'recent_events' => $recentEvents,
    'server_time' => date('Y-m-d H:i:s'),
]);
