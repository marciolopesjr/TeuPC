<?php
// View "Rede": snapshot tecnico do host (interfaces, portas, ARP) renderizado server-side.
// Partes (?part=): data (chips + cards, para auto-refresh). Sem part = pagina completa.

require_once __DIR__ . '/../../src/Database.php';

use TeuPC\Central\Database;

$db = Database::getConnection();

// ---- helpers (protegidos contra redeclaracao, pois este arquivo se inclui recursivamente) --

if (!function_exists('network_esc')) {
    function network_esc($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('network_machine_id')) {
    function network_machine_id(PDO $db, ?string $requested): ?array
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

if (!function_exists('network_snapshot')) {
    function network_snapshot(PDO $db, string $machineId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM snapshots WHERE machine_id = :id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':id' => $machineId]);
        return $stmt->fetch() ?: null;
    }
}

// Chips + cards (regiao re-renderizavel pelo select e pelo auto-refresh).
if (!function_exists('network_render_data')) {
    function network_render_data(PDO $db, ?array $machine): void
    {
        $snap = $machine ? network_snapshot($db, $machine['machine_id']) : null;
        $data = $snap && !empty($snap['snapshot_json']) ? (json_decode($snap['snapshot_json'], true) ?: []) : [];
        $na = $data['network_advanced'] ?? null;
        $nets = $data['networks'] ?? [];
        $ports = $na['listening_ports'] ?? [];
        $arp = $na['arp_entries'] ?? [];
        $empty = '<div class="text-fg-dim text-center py-4 text-xs">Disponível a partir do snapshot técnico do host.</div>';
        ?>
        <div class="text-fg-dim font-mono text-[10px] mb-1">
            <?= $snap ? 'Dados do snapshot de ' . network_esc($snap['created_at']) : 'Nenhum snapshot técnico registrado para esta máquina.' ?>
        </div>

        <!-- Chips: gateway / DNS / TCP -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="panel p-3"><span class="label block">Gateway</span><span class="text-sm font-mono text-fg mt-1 block truncate"><?= network_esc($na['default_gateway'] ?? '--') ?></span></div>
            <div class="panel p-3"><span class="label block">DNS</span><span class="text-sm font-mono text-fg mt-1 block truncate" title="<?= network_esc(implode(', ', $na['dns_servers'] ?? [])) ?>"><?= network_esc(implode(', ', $na['dns_servers'] ?? []) ?: '--') ?></span></div>
            <div class="panel p-3"><span class="label block">TCP estabelecidas</span><span class="text-sm font-mono text-fg mt-1 block"><?= network_esc($na['tcp_established'] ?? '--') ?></span></div>
            <div class="panel p-3"><span class="label block">TCP em escuta</span><span class="text-sm font-mono text-fg mt-1 block"><?= network_esc($na['tcp_listen'] ?? '--') ?></span></div>
        </div>

        <!-- Interfaces de rede -->
        <div class="card p-4">
            <h4 class="label !text-fg mb-3 flex items-center gap-2"><i class="fas fa-network-wired text-fg-mid"></i> Interfaces de rede</h4>
            <?php if (!$nets): ?>
            <?= $empty ?>
            <?php else: ?>
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs font-mono data-table">
                    <thead class="text-fg-dim border-b border-ink-500">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Interface</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Tipo</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">IPv4</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">RX/s</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">TX/s</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Total RX</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Total TX</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Erros / Drops</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-500/60">
                        <?php foreach ($nets as $n):
                            $err = (int) ($n['rx_errors'] ?? 0) + (int) ($n['tx_errors'] ?? 0);
                            $drop = (int) ($n['rx_dropped'] ?? 0) + (int) ($n['tx_dropped'] ?? 0);
                        ?>
                        <tr>
                            <td class="p-2.5 font-semibold text-fg"><?= network_esc($n['name'] ?? '-') ?></td>
                            <td class="p-2.5 text-fg-dim"><?= network_esc($n['iface_type'] ?? '-') ?></td>
                            <td class="p-2.5 text-rh-blue"><?= network_esc($n['ipv4'] ?? '-') ?></td>
                            <td class="p-2.5 text-rh-green"><?= sprintf('%.1f KB/s', (float) ($n['rx_per_sec'] ?? 0)) ?></td>
                            <td class="p-2.5 text-rh-amber"><?= sprintf('%.1f KB/s', (float) ($n['tx_per_sec'] ?? 0)) ?></td>
                            <td class="p-2.5 text-fg-mid"><?= sprintf('%.1f MB', (float) ($n['rx_total'] ?? 0)) ?></td>
                            <td class="p-2.5 text-fg-mid"><?= sprintf('%.1f MB', (float) ($n['tx_total'] ?? 0)) ?></td>
                            <td class="p-2.5 <?= $err > 0 ? 'text-rh-red' : 'text-fg-dim' ?>"><?= $err ?> / <?= $drop ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Portas em escuta -->
        <div class="card p-4">
            <h4 class="label !text-fg mb-3 flex items-center gap-2"><i class="fas fa-door-open text-fg-mid"></i> Portas em escuta</h4>
            <div class="overflow-x-auto custom-scrollbar max-h-[360px] overflow-y-auto">
                <table class="w-full text-left text-xs font-mono data-table">
                    <thead class="text-fg-dim border-b border-ink-500">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Protocolo</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Endereço local</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-500/60">
                        <?php if (!$ports): ?>
                        <tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>
                        <?php else: foreach ($ports as $p): ?>
                        <tr>
                            <td class="p-2.5 text-fg-dim"><?= network_esc($p['protocol'] ?? 'TCP') ?></td>
                            <td class="p-2.5 font-semibold text-fg"><?= network_esc($p['local_address'] ?? '-') ?></td>
                            <td class="p-2.5 text-rh-blue"><?= network_esc($p['state'] ?? 'LISTEN') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tabela ARP -->
        <div class="card p-4">
            <h4 class="label !text-fg mb-3 flex items-center gap-2"><i class="fas fa-table-cells-large text-fg-mid"></i> Tabela ARP (vizinhos na rede)</h4>
            <div class="overflow-x-auto custom-scrollbar max-h-[300px] overflow-y-auto">
                <table class="w-full text-left text-xs font-mono data-table">
                    <thead class="text-fg-dim border-b border-ink-500">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Interface</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Endereço IP</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">MAC</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-500/60">
                        <?php if (!$arp): ?>
                        <tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>
                        <?php else: foreach ($arp as $a): ?>
                        <tr>
                            <td class="p-2.5 text-fg-dim"><?= network_esc($a['interface'] ?? '-') ?></td>
                            <td class="p-2.5 text-fg"><?= network_esc($a['ip_address'] ?? '-') ?></td>
                            <td class="p-2.5 text-fg-mid"><?= network_esc($a['mac_address'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}

$machine = network_machine_id($db, $_GET['machine_id'] ?? null);
$machineId = $machine['machine_id'] ?? null;

// part=data: regiao de dados (auto-refresh / select)
if (($_GET['part'] ?? '') === 'data') {
    network_render_data($db, $machine);
    return;
}

$machines = $db->query("SELECT * FROM machines ORDER BY last_seen DESC")->fetchAll();
$pageUrl = '/?view=network' . ($machineId ? '&machine_id=' . urlencode($machineId) : '');
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h2 class="font-disp text-lg font-bold text-fg">Rede</h2>
    <div class="flex items-center gap-2">
        <select name="machine_id"
                hx-get="/?view=network" hx-include="this" hx-target="#app" hx-push-url="true" hx-trigger="change"
                class="bg-ink-900 border border-ink-500 rounded-md px-2.5 py-1.5 text-xs text-fg focus:outline-none focus:border-rh-red/70 cursor-pointer font-medium">
            <?php if (!$machines): ?>
            <option value="" class="bg-ink-700">Nenhuma máquina conectada</option>
            <?php else: foreach ($machines as $m): ?>
            <option value="<?= network_esc($m['machine_id']) ?>" class="bg-ink-700"<?= $m['machine_id'] === $machineId ? ' selected' : '' ?>><?= network_esc($m['hostname']) ?></option>
            <?php endforeach; endif; ?>
        </select>
        <button hx-get="<?= network_esc($pageUrl) ?>" hx-target="#app" hx-push-url="true"
                title="Buscar snapshot novamente, ignorando o cache local"
                class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-3.5 py-2 rounded-md font-medium transition-colors flex items-center gap-2">
            <i class="fas fa-arrows-rotate text-fg-mid"></i> Recarregar
        </button>
    </div>
</div>

<?php if (!$machine): ?>
<div class="card border-dashed p-16 text-center mt-3">
    <div class="w-14 h-14 rounded-full bg-ink-600 border border-ink-400 flex items-center justify-center mx-auto mb-4 text-fg-mid text-xl">
        <i class="fas fa-satellite-dish"></i>
    </div>
    <h3 class="font-disp text-lg font-bold text-fg mb-2">Nenhuma máquina conectada</h3>
    <p class="text-fg-mid text-xs max-w-md mx-auto">Inicie o agente daemon para receber o snapshot técnico de rede dos hosts.</p>
</div>
<?php else: ?>
<div hx-get="/?view=network&part=data&machine_id=<?= network_esc(urlencode($machineId)) ?>" hx-trigger="every 30s" hx-swap="innerHTML" class="space-y-3">
    <?php network_render_data($db, $machine); ?>
</div>
<?php endif; ?>
