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

if (!$data || !isset($data['machine_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido ou machine_id ausente']);
    exit;
}

$db = Database::getConnection();
$config = require __DIR__ . '/../../config.php';

// Extrair campos de telemetria
$machineId = $data['machine_id'];
$hostname = $data['hostname'] ?? 'Desconhecido';
$os = $data['os'] ?? 'Linux';
$kernel = $data['kernel'] ?? '';
$cpuBrand = $data['cpu_brand'] ?? '';
$cpuCores = (int)($data['cpu_cores'] ?? 0);
$memTotal = (int)($data['mem_total'] ?? 0);
$ipPublic = $data['ip_public'] ?? '';
$ipLocal = $data['ip_local'] ?? '';
$virt = $data['virtualization'] ?? 'Bare Metal';

$cpuUsage = (float)($data['cpu_usage'] ?? 0.0);
$memUsed = (int)($data['mem_used'] ?? 0);
$swapUsed = (int)($data['swap_used'] ?? 0);
$swapTotal = (int)($data['swap_total'] ?? 0);
$gpuUsage = isset($data['gpu_usage']) ? (float)$data['gpu_usage'] : null;
$diskReadKbs = (float)($data['disk_read_kbs'] ?? 0.0);
$diskWriteKbs = (float)($data['disk_write_kbs'] ?? 0.0);
$netRxKbs = (float)($data['net_rx_kbs'] ?? 0.0);
$netTxKbs = (float)($data['net_tx_kbs'] ?? 0.0);
$cpuTemp = isset($data['cpu_temp']) ? (float)$data['cpu_temp'] : null;
$raplWatts = isset($data['rapl_watts']) ? (float)$data['rapl_watts'] : null;
$load1m = (float)($data['load_1m'] ?? 0.0);

$now = date('Y-m-d H:i:s');

// 1. Upsert da máquina
$stmt = $db->prepare("
    INSERT INTO machines (
        machine_id, hostname, os, kernel, cpu_brand, cpu_cores, mem_total, 
        ip_public, ip_local, virtualization, status, last_seen
    ) VALUES (
        :id, :host, :os, :kernel, :cpu, :cores, :mem, :ip_pub, :ip_loc, :virt, 'online', :last_seen
    )
    ON CONFLICT(machine_id) DO UPDATE SET
        hostname = :host,
        os = :os,
        kernel = :kernel,
        cpu_brand = :cpu,
        cpu_cores = :cores,
        mem_total = :mem,
        ip_public = :ip_pub,
        ip_local = :ip_loc,
        virtualization = :virt,
        status = 'online',
        last_seen = :last_seen
");

$stmt->execute([
    ':id' => $machineId,
    ':host' => $hostname,
    ':os' => $os,
    ':kernel' => $kernel,
    ':cpu' => $cpuBrand,
    ':cores' => $cpuCores,
    ':mem' => $memTotal,
    ':ip_pub' => $ipPublic,
    ':ip_loc' => $ipLocal,
    ':virt' => $virt,
    ':last_seen' => $now,
]);

// 2. Inserir log de telemetria
$stmtLog = $db->prepare("
    INSERT INTO telemetry_logs (
        machine_id, cpu_usage, mem_used, mem_total, swap_used, swap_total,
        gpu_usage, disk_read_kbs, disk_write_kbs, net_rx_kbs, net_tx_kbs,
        cpu_temp, rapl_watts, load_1m, payload_json, created_at
    ) VALUES (
        :id, :cpu_u, :mem_u, :mem_t, :swap_u, :swap_t,
        :gpu_u, :disk_r, :disk_w, :net_rx, :net_tx,
        :temp, :rapl, :load1, :payload, :created
    )
");

$stmtLog->execute([
    ':id' => $machineId,
    ':cpu_u' => $cpuUsage,
    ':mem_u' => $memUsed,
    ':mem_t' => $memTotal,
    ':swap_u' => $swapUsed,
    ':swap_t' => $swapTotal,
    ':gpu_u' => $gpuUsage,
    ':disk_r' => $diskReadKbs,
    ':disk_w' => $diskWriteKbs,
    ':net_rx' => $netRxKbs,
    ':net_tx' => $netTxKbs,
    ':temp' => $cpuTemp,
    ':rapl' => $raplWatts,
    ':load1' => $load1m,
    ':payload' => $rawInput,
    ':created' => $now,
]);

// Retenção: mantém só os N registros mais recentes por máquina (sem isso o banco cresce
// ~5 KB por amostra para sempre). O cast para int é o que torna o LIMIT seguro aqui.
$retention = (int)($config['telemetry_retention_limit'] ?? 0);
if ($retention > 0) {
    $db->prepare("
        DELETE FROM telemetry_logs
        WHERE machine_id = :id AND id NOT IN (
            SELECT id FROM telemetry_logs WHERE machine_id = :id_keep ORDER BY id DESC LIMIT $retention
        )
    ")->execute([':id' => $machineId, ':id_keep' => $machineId]);
}

echo json_encode([
    'success' => true,
    'message' => 'Telemetria registrada com sucesso',
    'machine_id' => $machineId,
    'timestamp' => $now,
]);
