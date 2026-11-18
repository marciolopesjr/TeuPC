<?php
// View "Visao geral": NOC com regioes HTMX auto-poluiveis.
// Partes (?part=): strip | fleet | vitals | tab | snapshot-json. Sem part = pagina completa.

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Settings.php';

use TeuPC\Central\Database;
use TeuPC\Central\Settings;

$db = Database::getConnection();
$interval = Settings::get('interval_ms', 2000);
$thresholds = Settings::all();

// ---- helpers (protegidos contra redeclaro, pois este arquivo se inclui recursivamente) --

if (!function_exists('overview_esc')) {
    function overview_esc($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('overview_machine_id')) {
    function overview_machine_id(PDO $db, ?string $requested): ?array
    {
        if ($requested) {
            $stmt = $db->prepare("SELECT * FROM machines WHERE machine_id = :id");
            $stmt->execute([':id' => $requested]);
            $m = $stmt->fetch();
            if ($m) {
                return $m;
            }
        }
        $stmt = $db->query("SELECT * FROM machines WHERE last_seen >= datetime('now', '-30 seconds') ORDER BY last_seen DESC LIMIT 1");
        $m = $stmt->fetch();
        if (!$m) {
            $m = $db->query("SELECT * FROM machines ORDER BY last_seen DESC LIMIT 1")->fetch();
        }
        return $m ?: null;
    }
}

if (!function_exists('overview_telemetry')) {
    function overview_telemetry(PDO $db, string $machineId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM telemetry_logs WHERE machine_id = :id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':id' => $machineId]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('overview_bar_class')) {
    function overview_bar_class(float $pct, int $threshold): string
    {
        if ($pct > $threshold) {
            return 'bg-rh-red';
        }
        if ($pct > $threshold / 2) {
            return 'bg-rh-amber';
        }
        return 'bg-rh-green';
    }
}

$part = $_GET['part'] ?? 'page';

// part=detail: hero + vitals + tabs de uma maquina (troca de maquina na frota)
if ($part === 'detail') {
    $machine = overview_machine_id($db, $_GET['machine_id'] ?? null);
    if (!$machine) {
        echo '<div class="card border-dashed p-12 text-center text-fg-dim text-sm">Máquina não encontrada.</div>';
        return;
    }
    $machineId = $machine['machine_id'];
    $tab = 'charts';
    require __DIR__ . '/inc-overview-detail.php';
    return;
}

// ---- fragmentos ----------------------------------------------------------

if ($part === 'strip') {
    $total = (int) $db->query("SELECT COUNT(*) c FROM machines")->fetch()['c'];
    $online = (int) $db->query("SELECT COUNT(*) c FROM machines WHERE last_seen >= datetime('now', '-30 seconds')")->fetch()['c'];
    $alerts = (int) $db->query("SELECT COUNT(*) c FROM events_log")->fetch()['c'];
    $avgCpu = $db->query("
        SELECT AVG(t.cpu_usage) a FROM telemetry_logs t
        JOIN machines m ON m.machine_id = t.machine_id
        WHERE t.id IN (SELECT MAX(id) FROM telemetry_logs GROUP BY machine_id)
    ")->fetch()['a'];
    $kpis = [
        ['label' => 'Máquinas totais', 'value' => $total, 'fmt' => '%d', 'icon' => 'fa-server', 'color' => 'text-fg'],
        ['label' => 'Hosts online', 'value' => $online, 'fmt' => '%d', 'icon' => 'fa-signal', 'color' => 'text-rh-green'],
        ['label' => 'Anomalias / Alertas', 'value' => $alerts, 'fmt' => '%d', 'icon' => 'fa-triangle-exclamation', 'color' => 'text-rh-amber'],
        ['label' => 'Carga média CPU', 'value' => (float) ($avgCpu ?: 0), 'fmt' => '%.1f%%', 'icon' => 'fa-microchip', 'color' => 'text-fg'],
    ];
    ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <?php foreach ($kpis as $k): ?>
        <div class="card p-4 flex items-center justify-between hover:border-ink-400 transition-colors">
            <div>
                <span class="label block mb-1.5"><?= overview_esc($k['label']) ?></span>
                <span class="text-2xl lg:text-3xl font-disp font-bold font-mono <?= $k['color'] ?>"><?= sprintf($k['fmt'], $k['value']) ?></span>
            </div>
            <i class="fas <?= $k['icon'] ?> text-fg-dim text-lg"></i>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return;
}

if ($part === 'fleet') {
    $machines = $db->query("SELECT * FROM machines ORDER BY last_seen DESC")->fetchAll();
    $events = $db->query("
        SELECT e.*, m.hostname FROM events_log e
        LEFT JOIN machines m ON e.machine_id = m.machine_id
        ORDER BY e.id DESC LIMIT 8
    ")->fetchAll();
    $selected = $_GET['machine_id'] ?? '';
    ?>
    <div class="card p-4 flex flex-col">
        <div class="flex items-center justify-between mb-3">
            <h2 class="label !text-fg flex items-center gap-2"><i class="fas fa-network-wired text-fg-mid"></i> Frota de hosts</h2>
            <span class="text-[10px] bg-ink-600 border border-ink-400 text-fg-mid px-2 py-0.5 rounded-full font-mono font-medium">
                <?= count(array_filter($machines, fn($m) => strtotime($m['last_seen']) >= time() - 30)) ?> / <?= count($machines) ?> online
            </span>
        </div>
        <div class="relative mb-3">
            <i class="fas fa-search absolute left-3 top-2.5 text-fg-dim text-xs"></i>
            <input type="text" x-data oninput="teupcFilterFleet(this.value)" placeholder="Filtrar por nome, IP, CPU..."
                   class="w-full bg-ink-900 border border-ink-500 rounded-md pl-8 pr-3 py-1.5 text-xs text-fg placeholder-fg-dim focus:outline-none focus:border-rh-red/70 transition-colors">
        </div>
        <div id="fleet-container" class="space-y-2 max-h-[420px] overflow-y-auto custom-scrollbar pr-1">
            <?php if (!$machines): ?>
            <div class="text-center py-8 text-fg-dim text-xs">
                <i class="fas fa-satellite-dish text-lg block mb-2 text-fg-mid"></i>
                Nenhuma máquina conectada.<br>Inicie o daemon: <code class="text-rh-red text-[11px]">./teupc --daemon</code>
            </div>
            <?php else: foreach ($machines as $m):
                $online = strtotime($m['last_seen']) >= time() - 30;
                $tel = overview_telemetry($db, $m['machine_id']);
                $cpuVal = $tel ? (float) $tel['cpu_usage'] : 0;
                $cpu = $tel ? sprintf('%.1f%%', $cpuVal) : '--';
                $ram = $tel ? sprintf('%.1f GB', $tel['mem_used'] / 1073741824) : '--';
            ?>
            <div data-fleet-card data-hostname="<?= overview_esc($m['hostname']) ?>" data-cpu="<?= overview_esc($m['cpu_brand']) ?>"
                 data-ip="<?= overview_esc($m['ip_local']) ?><?= overview_esc($m['ip_public']) ?>"
                 hx-get="/?view=overview&part=detail&machine_id=<?= overview_esc($m['machine_id']) ?>"
                 hx-target="#detail-region" hx-swap="innerHTML transition:true"
                 @click="$store.noc.selected = '<?= overview_esc($m['machine_id']) ?>'; $store.noc.tab = 'charts'"
                 :class="$store.noc.selected === '<?= overview_esc($m['machine_id']) ?>' ? 'border-rh-red bg-[#262626] ring-1 ring-rh-red/60' : 'border-ink-500 bg-ink-900/70 hover:border-ink-400 hover:bg-ink-700'"
                 class="cursor-pointer border rounded-lg p-3 transition-colors">
                <div class="flex items-center justify-between mb-1.5">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full <?= $online ? 'bg-rh-green' : 'bg-ink-400' ?>"></span>
                        <span class="font-semibold text-fg text-xs truncate max-w-[140px]"><?= overview_esc($m['hostname']) ?></span>
                    </div>
                    <span class="text-[9px] px-1.5 py-0.5 rounded border font-mono font-medium <?= $online ? 'border-rh-green/40 text-rh-green' : 'border-ink-400 text-fg-dim' ?>">
                        <?= $online ? 'ONLINE' : 'OFFLINE' ?>
                    </span>
                </div>
                <div class="text-[10px] text-fg-dim truncate mb-2 font-mono"><?= overview_esc($m['cpu_brand'] ?: $m['os']) ?></div>
                <div class="w-full bg-ink-600 h-1 rounded-full mb-2 overflow-hidden">
                    <div class="<?= overview_bar_class($cpuVal, $thresholds['cpu']) ?> h-full rounded-full" style="width: <?= min($cpuVal, 100) ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-[10px] text-fg-dim border-t border-ink-500/70 pt-1.5 font-mono">
                    <span>CPU: <strong class="text-fg font-medium"><?= $cpu ?></strong></span>
                    <span>RAM: <strong class="text-fg font-medium"><?= $ram ?></strong></span>
                    <span>IP: <strong class="text-fg-mid"><?= overview_esc($m['ip_local'] ?: $m['ip_public'] ?: '-') ?></strong></span>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="label !text-fg flex items-center gap-2"><i class="fas fa-bell text-rh-amber"></i> Anomalias &amp; spikes</h2>
            <span class="text-[10px] text-fg-dim font-mono">tempo real</span>
        </div>
        <div id="fleet-events" class="space-y-2 max-h-[300px] overflow-y-auto custom-scrollbar text-xs">
            <?php if (!$events): ?>
            <div class="text-fg-dim text-center py-4">Nenhuma anomalia registrada.</div>
            <?php else: foreach ($events as $e):
                $sev = $e['severity'] === 'Critical' ? 'text-rh-red border-rh-red/40 bg-rh-red/10'
                     : ($e['severity'] === 'Warning' ? 'text-rh-amber border-rh-amber/40 bg-rh-amber/10'
                     : 'text-rh-blue border-rh-blue/40 bg-rh-blue/10');
            ?>
            <div class="border border-ink-500 rounded-md p-2.5 bg-ink-900/60 hover:border-ink-400 transition-colors">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-[9px] px-1.5 py-0.5 rounded border font-mono font-medium <?= $sev ?>"><?= overview_esc($e['category']) ?></span>
                    <span class="text-[10px] text-fg-dim font-mono"><?= overview_esc(explode(' ', $e['created_at'])[1] ?? '') ?></span>
                </div>
                <div class="font-semibold text-fg text-[11px] truncate"><?= overview_esc($e['title']) ?></div>
                <div class="text-[10px] text-fg-dim truncate mt-0.5 font-mono"><?= overview_esc($e['details']) ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php
    return;
}

// maquina selecionada para detail/vitals/tab/snapshot
$machine = overview_machine_id($db, $_GET['machine_id'] ?? null);
$machineId = $machine['machine_id'] ?? null;

if ($part === 'vitals') {
    if (!$machine) {
        echo '<div class="text-fg-dim text-xs text-center py-4">Nenhuma máquina conectada.</div>';
        return;
    }
    $tel = overview_telemetry($db, $machineId);
    $cpu = $tel ? (float) $tel['cpu_usage'] : 0;
    $ramPct = ($tel && $tel['mem_total'] > 0) ? ($tel['mem_used'] / $tel['mem_total'] * 100) : 0;
    $temp = $tel ? (float) ($tel['cpu_temp'] ?? 0) : 0;
    $cards = [
        ['label' => 'Uso de CPU', 'value' => sprintf('%.1f%%', $cpu), 'sub' => '', 'bar' => true, 'pct' => $cpu, 'color' => overview_bar_class($cpu, $thresholds['cpu'])],
        ['label' => 'Memória RAM', 'value' => sprintf('%.1f / %.1f GB', ($tel['mem_used'] ?? 0) / 1073741824, ($tel['mem_total'] ?? 0) / 1073741824), 'sub' => '', 'bar' => true, 'pct' => $ramPct, 'color' => overview_bar_class($ramPct, 90)],
        ['label' => 'Temp. pacote CPU', 'value' => $temp ? sprintf('%.0f °C', $temp) : '-- °C', 'sub' => '', 'bar' => true, 'pct' => min($temp, 100), 'color' => $temp > 80 ? 'bg-rh-red' : 'bg-rh-amber'],
        ['label' => 'Consumo RAPL', 'value' => ($tel['rapl_watts'] ?? null) ? sprintf('%.1f W', $tel['rapl_watts']) : '-- W', 'sub' => 'Package Power'],
        ['label' => 'Load average', 'value' => sprintf('%.2f', $tel['load_1m'] ?? 0), 'sub' => '1m Load'],
    ];
    ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
        <?php foreach ($cards as $c): ?>
        <div class="panel p-3 text-center">
            <span class="label block"><?= overview_esc($c['label']) ?></span>
            <span class="text-xl lg:text-2xl font-disp font-bold text-fg mt-1 block font-mono"><?= overview_esc($c['value']) ?></span>
            <?php if (!empty($c['bar'])): ?>
            <div class="w-full bg-ink-600 h-1 rounded-full mt-2 overflow-hidden">
                <div class="<?= $c['color'] ?> h-full rounded-full transition-all duration-500" style="width: <?= min($c['pct'], 100) ?>%"></div>
            </div>
            <?php else: ?>
            <span class="text-[10px] text-fg-dim block mt-1"><?= overview_esc($c['sub']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return;
}

if ($part === 'tab') {
    $tab = $_GET['tab'] ?? 'charts';
    $tel = $machineId ? overview_telemetry($db, $machineId) : null;
    $details = $tel && !empty($tel['payload_json']) ? (json_decode($tel['payload_json'], true) ?: []) : [];
    ?>
    <div id="tab-pane-<?= overview_esc($tab) ?>">
    <?php if ($tab === 'charts'): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php
            $chartCards = [
                ['id' => 'cpu', 'title' => 'Histórico carga de CPU (%)', 'icon' => 'fa-microchip', 'id2' => 'chart-cpu-val'],
                ['id' => 'ram', 'title' => 'Histórico consumo de RAM (GB)', 'icon' => 'fa-memory', 'id2' => 'chart-ram-val'],
                ['id' => 'disk', 'title' => 'I/O de disco (Read / Write KB/s)', 'icon' => 'fa-hard-drive', 'id2' => null],
                ['id' => 'net', 'title' => 'Tráfego de rede (RX / TX KB/s)', 'icon' => 'fa-network-wired', 'id2' => null],
            ];
            foreach ($chartCards as $cc): ?>
            <div class="card p-4">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="label !text-fg-mid flex items-center gap-2"><i class="fas <?= $cc['icon'] ?>"></i> <?= overview_esc($cc['title']) ?></h4>
                    <?php if ($cc['id2']): ?><span id="<?= $cc['id2'] ?>" class="text-xs font-mono font-medium text-fg">--</span><?php endif; ?>
                </div>
                <div class="h-56"><canvas id="chart-<?= $cc['id'] ?>"></canvas></div>
            </div>
            <?php endforeach; ?>
        </div>
        <script>
        (function () {
            if (!window.Chart || !document.getElementById('chart-cpu')) return;
            const GRID = '#262626', TICK = '#8a8a8a';
            const FONT = { family: '"Red Hat Mono", monospace', size: 10 };
            const TOOLTIP = { backgroundColor: '#262626', borderColor: '#3f3f3f', borderWidth: 1, titleColor: '#f2f2f2', bodyColor: '#a1a1a1', titleFont: FONT, bodyFont: FONT, padding: 8, cornerRadius: 4 };
            const ds = (color) => ({ data: [], borderColor: color, backgroundColor: color + '14', fill: true, borderWidth: 1.5, tension: 0.3, pointRadius: 0, pointHoverRadius: 3 });
            window.teupcCharts = window.teupcCharts || {};
            for (const k of ['cpu', 'ram', 'disk', 'net']) { if (teupcCharts[k]) { teupcCharts[k].destroy(); delete teupcCharts[k]; } }
            const mk = (canvas, datasets, legend = false) => new Chart(canvas, {
                type: 'line',
                data: { labels: [], datasets },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    plugins: { legend: { display: legend, labels: { color: '#a1a1a1', boxWidth: 12, boxHeight: 2, font: FONT } }, tooltip: TOOLTIP },
                    scales: { x: { display: false }, y: { grid: { color: GRID }, border: { display: false }, ticks: { color: TICK, font: FONT } } }
                }
            });
            const mid = '<?= overview_esc($machineId) ?>';
            teupcCharts.cpu = mk(document.getElementById('chart-cpu'), [ds('#0088CE')]);
            teupcCharts.ram = mk(document.getElementById('chart-ram'), [ds('#00B8AF')]);
            teupcCharts.disk = mk(document.getElementById('chart-disk'), [ds('#4CA64C'), ds('#F0AB00')], true);
            teupcCharts.net = mk(document.getElementById('chart-net'), [ds('#0088CE'), ds('#EE0000')], true);

            async function pollHistory() {
                try {
                    const res = await fetch('api/machines.php?id=' + encodeURIComponent(mid));
                    const data = await res.json();
                    if (data.machine && data.machine.machine_id !== mid) return;
                    const labels = (data.history || []).map(h => (h.created_at.split(' ')[1] || ''));
                    const push = (chart, datasets) => {
                        chart.data.labels = labels;
                        datasets.forEach((values, i) => chart.data.datasets[i].data = values);
                        chart.update('none');
                    };
                    push(teupcCharts.cpu, [(data.history || []).map(h => h.cpu_usage)]);
                    push(teupcCharts.ram, [(data.history || []).map(h => +(h.mem_used / 1073741824).toFixed(2))]);
                    push(teupcCharts.disk, [(data.history || []).map(h => h.disk_read_kbs), (data.history || []).map(h => h.disk_write_kbs)]);
                    push(teupcCharts.net, [(data.history || []).map(h => h.net_rx_kbs), (data.history || []).map(h => h.net_tx_kbs)]);
                    if (data.history && data.history.length) {
                        const last = data.history[data.history.length - 1];
                        const el = document.getElementById('chart-cpu-val');
                        if (el) el.innerText = (+last.cpu_usage).toFixed(1) + '%';
                        const el2 = document.getElementById('chart-ram-val');
                        if (el2) el2.innerText = (last.mem_used / 1073741824).toFixed(2) + ' GB';
                    }
                    const st = document.getElementById('server-status');
                    if (st) { st.innerText = 'Conectado'; document.getElementById('status-pulse-dot').className = 'w-2 h-2 rounded-full bg-rh-green pulse'; }
                } catch (e) {
                    const st = document.getElementById('server-status');
                    if (st) { st.innerText = 'Erro'; document.getElementById('status-pulse-dot').className = 'w-2 h-2 rounded-full bg-rh-red'; }
                }
            }
            window.teupcStopCharts = () => { if (window.teupcPollTimer) { clearInterval(window.teupcPollTimer); window.teupcPollTimer = null; } };
            window.teupcStopCharts();
            pollHistory();
            window.teupcPollTimer = setInterval(pollHistory, window.TEUPC.interval_ms || 2000);
            // Para o polling se a aba de charts sair da DOM (troca de tab/maquina)
            document.addEventListener('htmx:afterSwap', function guard(e) {
                if (!document.getElementById('chart-cpu')) {
                    window.teupcStopCharts();
                    document.removeEventListener('htmx:afterSwap', guard);
                }
            });
        })();
        </script>
    <?php else: ?>
        <?php
        // tabs server-side (dados do payload da ultima telemetria)
        if ($tab === 'processes'):
            $procs = $details['top_processes'] ?? []; ?>
        <div class="card p-4 space-y-3" hx-get="/?view=overview&part=tab&machine_id=<?= overview_esc($machineId) ?>&tab=processes" hx-trigger="every <?= (int) max($interval, 2000) ?>s" hx-swap="innerHTML">
            <h4 class="label !text-fg flex items-center gap-2"><i class="fas fa-tasks"></i> Top processos ativos no host</h4>
            <div class="overflow-x-auto custom-scrollbar max-h-[480px] overflow-y-auto">
                <table class="w-full text-left text-xs font-mono data-table">
                    <thead class="text-fg-dim border-b border-ink-500">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">PID</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Nome</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Usuário</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">CPU %</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Memória</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Disk R</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Disk W</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Threads</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-500/60">
                        <?php if (!$procs): ?>
                        <tr><td colspan="8" class="p-4 text-center text-fg-dim">Nenhum processo reportado.</td></tr>
                        <?php else: foreach ($procs as $p):
                            $cpuP = (float) $p['cpu_usage'];
                            $cpuClass = $cpuP > $thresholds['cpu'] ? 'text-rh-red font-semibold' : ($cpuP > $thresholds['cpu'] / 2 ? 'text-rh-amber' : 'text-fg-mid'); ?>
                        <tr>
                            <td class="p-2.5 text-fg-dim"><?= (int) $p['pid'] ?></td>
                            <td class="p-2.5 font-semibold text-fg"><?= overview_esc($p['name']) ?></td>
                            <td class="p-2.5 text-fg-dim"><?= overview_esc($p['user']) ?></td>
                            <td class="p-2.5 <?= $cpuClass ?>"><?= sprintf('%.1f%%', $cpuP) ?></td>
                            <td class="p-2.5 text-fg"><?= sprintf('%.1f MB', $p['mem_bytes'] / 1048576) ?></td>
                            <td class="p-2.5 text-rh-green"><?= sprintf('%.1f KB/s', $p['read_kb_s'] ?? 0) ?></td>
                            <td class="p-2.5 text-rh-amber"><?= sprintf('%.1f KB/s', $p['write_kb_s'] ?? 0) ?></td>
                            <td class="p-2.5 text-fg-mid"><?= (int) ($p['threads'] ?? 1) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($tab === 'disks'): ?>
        <div class="space-y-4" hx-get="/?view=overview&part=tab&machine_id=<?= overview_esc($machineId) ?>&tab=disks" hx-trigger="every 5s" hx-swap="innerHTML">
            <div class="card p-4">
                <h4 class="label !text-fg mb-4 flex items-center gap-2"><i class="fas fa-hard-drive"></i> Unidades de disco &amp; agendadores I/O</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php $disks = $details['disks'] ?? []; ?>
                    <?php if (!$disks): ?>
                    <div class="text-fg-dim text-center py-6 col-span-2 text-xs">Nenhum disco reportado na telemetria recente.</div>
                    <?php else: foreach ($disks as $d): ?>
                    <div class="panel p-4 space-y-3 font-mono">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-hdd text-fg-mid text-base"></i>
                                <span class="font-semibold text-fg text-xs"><?= overview_esc($d['name']) ?></span>
                            </div>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-ink-600 text-fg-mid border border-ink-400"><?= overview_esc($d['scheduler'] ?? 'none') ?></span>
                        </div>
                        <div class="text-[11px] text-fg-dim truncate"><?= overview_esc($d['model'] ?? 'Dispositivo de Bloco') ?></div>
                        <div class="grid grid-cols-2 gap-2 text-xs border-t border-ink-500/70 pt-2">
                            <div><span class="text-fg-dim">Read:</span> <strong class="text-rh-green font-medium"><?= sprintf('%.1f KB/s', $d['read_kb_s'] ?? 0) ?></strong></div>
                            <div><span class="text-fg-dim">Write:</span> <strong class="text-rh-amber font-medium"><?= sprintf('%.1f KB/s', $d['write_kb_s'] ?? 0) ?></strong></div>
                            <div><span class="text-fg-dim">Total R:</span> <strong class="text-fg-mid"><?= sprintf('%.2f GB', $d['total_read_gb'] ?? 0) ?></strong></div>
                            <div><span class="text-fg-dim">Total W:</span> <strong class="text-fg-mid"><?= sprintf('%.2f GB', $d['total_write_gb'] ?? 0) ?></strong></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="card p-4">
                <h4 class="label !text-fg mb-4 flex items-center gap-2"><i class="fas fa-folder-tree"></i> Filesystems montados</h4>
                <?php $snapRow = overview_telemetry($db, $machineId); ?>
                <div class="text-fg-dim text-center py-4 text-xs">Disponível na página Rede (snapshot técnico do host).</div>
            </div>
        </div>
        <?php elseif ($tab === 'sensors'): ?>
        <div class="space-y-4" hx-get="/?view=overview&part=tab&machine_id=<?= overview_esc($machineId) ?>&tab=sensors" hx-trigger="every 5s" hx-swap="innerHTML">
            <div class="card p-4">
                <h4 class="label !text-fg mb-3 flex items-center gap-2"><i class="fas fa-thermometer-half"></i> Sensores térmicos, voltagens &amp; coolers</h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    <?php $sensors = $details['sensors'] ?? []; ?>
                    <?php if (!$sensors): ?>
                    <div class="text-fg-dim text-center py-6 col-span-4 text-xs">Nenhum sensor reportado.</div>
                    <?php else: foreach ($sensors as $s): ?>
                    <div class="panel p-3 text-center font-mono">
                        <i class="fas fa-temperature-half text-fg-dim text-sm mb-1 block"></i>
                        <span class="text-[10px] text-fg-dim truncate block"><?= overview_esc($s['label']) ?></span>
                        <span class="text-sm font-semibold text-fg mt-1 block"><?= overview_esc(number_format((float) $s['value'], 1, ',', '.')) ?> <?= overview_esc($s['unit']) ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="card p-4">
                <h4 class="label !text-fg mb-3 flex items-center gap-2"><i class="fas fa-battery-half"></i> Baterias &amp; fontes AC</h4>
                <?php $batteries = $details['batteries'] ?? []; ?>
                <?php if (!$batteries): ?>
                <div class="text-fg-dim text-center py-4 col-span-2 text-xs">Alimentação via tomada AC (Desktop / Servidor).</div>
                <?php else: foreach ($batteries as $b): ?>
                <div class="panel p-3.5 space-y-2 font-mono text-xs">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold text-fg"><?= overview_esc($b['name']) ?> (<?= overview_esc($b['model']) ?>)</span>
                        <span class="text-rh-green font-semibold"><?= (int) $b['capacity'] ?>% (<?= overview_esc($b['status']) ?>)</span>
                    </div>
                    <div class="w-full bg-ink-600 h-1 rounded-full overflow-hidden">
                        <div class="bg-rh-green h-full rounded-full" style="width: <?= (int) $b['capacity'] ?>%"></div>
                    </div>
                    <div class="flex justify-between text-[11px] text-fg-dim pt-1">
                        <span>Saúde: <strong class="text-rh-blue font-medium"><?= sprintf('%.1f%%', $b['health_pct']) ?></strong></span>
                        <span>Ciclos: <strong class="text-rh-amber font-medium"><?= (int) $b['cycle_count'] ?></strong></span>
                        <span>Consumo: <strong class="text-rh-red font-medium"><?= sprintf('%.1f W', $b['power_now']) ?></strong></span>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php elseif ($tab === 'hardware'): ?>
        <div class="card p-4">
            <h4 class="label !text-fg mb-4 flex items-center gap-2"><i class="fas fa-shield-halved"></i> Detalhes de plataforma &amp; segurança</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-xs">
                <div class="panel p-3.5 space-y-2">
                    <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Sistema operacional</div>
                    <div class="flex justify-between"><span class="text-fg-dim">OS:</span><span class="text-fg"><?= overview_esc($machine['os'] ?? '--') ?></span></div>
                    <div class="flex justify-between"><span class="text-fg-dim">Kernel:</span><span class="text-rh-blue"><?= overview_esc($machine['kernel'] ?? '--') ?></span></div>
                    <div class="flex justify-between"><span class="text-fg-dim">Virtualização:</span><span class="text-rh-amber"><?= overview_esc($machine['virtualization'] ?? '--') ?></span></div>
                </div>
                <div class="panel p-3.5 space-y-2">
                    <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Processador &amp; arquitetura</div>
                    <div class="flex justify-between"><span class="text-fg-dim">Modelo:</span><span class="text-fg truncate max-w-[220px]"><?= overview_esc($machine['cpu_brand'] ?? '--') ?></span></div>
                    <div class="flex justify-between"><span class="text-fg-dim">Cores / Threads:</span><span class="text-rh-blue"><?= (int) ($machine['cpu_cores'] ?? 0) ?> núcleos</span></div>
                    <div class="flex justify-between"><span class="text-fg-dim">Memória física:</span><span class="text-rh-green"><?= sprintf('%.2f GB', ($machine['mem_total'] ?? 0) / 1073741824) ?></span></div>
                </div>
            </div>
            <div class="mt-4 text-xs text-fg-dim text-center py-3 border border-dashed border-ink-500 rounded-md">
                Firmware, slots de memória e diagnóstico do kernel: consulte a página <a href="/?view=network" hx-get="/?view=network" hx-target="#app" hx-push-url="true" class="text-rh-blue underline underline-offset-2">Rede</a> (snapshot técnico completo).
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
    <?php
    return;
}

// ---- pagina completa -----------------------------------------------------

$machine = overview_machine_id($db, $_GET['machine_id'] ?? null);
$machineId = $machine['machine_id'] ?? null;
$tab = in_array($_GET['tab'] ?? 'charts', ['charts', 'processes', 'disks', 'sensors', 'hardware'], true) ? ($_GET['tab'] ?? 'charts') : 'charts';
?>
<script>
    // Estado global de navegacao (sobrevive aos swaps HTMX)
    document.addEventListener('alpine:init', () => {
        Alpine.store('noc', { selected: '<?= overview_esc($machineId) ?>', tab: '<?= overview_esc($tab) ?>' });
    });
    // Guarda do estado selecionado quando o fragmento fleet perde a referencia
    document.addEventListener('htmx:afterSwap', (e) => {
        if (e.detail.target.id === 'detail-region' && window.Alpine?.$data) { /* no-op */ }
    });
</script>

    <div hx-get="/?view=overview&part=strip" hx-trigger="every <?= (int) max($interval, 1000) ?>s" hx-swap="innerHTML">
        <?php $_GET['part'] = 'strip'; require __FILE__; ?>
    </div>

    <?php if (!$machine): ?>
    <div class="card border-dashed p-16 text-center">
        <div class="w-14 h-14 rounded-full bg-ink-600 border border-ink-400 flex items-center justify-center mx-auto mb-4 text-fg-mid text-xl">
            <i class="fas fa-satellite-dish"></i>
        </div>
        <h3 class="font-disp text-lg font-bold text-fg mb-2">Nenhuma máquina selecionada</h3>
        <p class="text-fg-mid text-xs max-w-md mx-auto mb-6">Inicie o agente daemon em seus servidores para começar a receber telemetria contínua e alertas em tempo real.</p>
        <div class="inline-block bg-ink-900 px-4 py-2.5 rounded-md border border-ink-500 text-left font-mono text-xs text-fg-mid">
            <span class="text-rh-red"># Iniciar TeuPC Daemon:</span><br>
            ./target/release/teupc --daemon --config teupc_agent.json
        </div>
    </div>
    <?php else: ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <!-- Coluna esquerda: frota + eventos -->
        <div class="lg:col-span-4 xl:col-span-3 space-y-4" hx-get="/?view=overview&part=fleet&machine_id=<?= overview_esc($machineId) ?>" hx-trigger="every 5s" hx-swap="innerHTML">
            <?php $_GET['part'] = 'fleet'; require __FILE__; ?>
        </div>

        <!-- Coluna direita: detalhe da maquina -->
        <div class="lg:col-span-8 xl:col-span-9 space-y-4">
            <div id="detail-region" class="space-y-4">
                <?php require __DIR__ . '/inc-overview-detail.php'; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

