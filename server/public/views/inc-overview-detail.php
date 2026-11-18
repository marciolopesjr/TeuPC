<?php
// Fragmento de detalhe da maquina: hero + vitals (poll) + tabs + conteudo da tab.
// Espera: $db, $machine (row), $machineId, $tab, $interval, $thresholds, definidos pelo caller.

$online = $machine && strtotime($machine['last_seen']) >= time() - 30;
?>
<!-- Hero -->
<div class="card p-5">
    <div class="flex flex-wrap items-start justify-between gap-4 pb-4 border-b border-ink-500">
        <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <h3 class="font-disp text-xl font-bold text-fg tracking-tight"><?= overview_esc($machine['hostname']) ?></h3>
                <span class="text-[11px] px-2.5 py-0.5 rounded-full font-semibold <?= $online ? 'bg-rh-green/15 text-rh-green border border-rh-green/40' : 'bg-ink-600 text-fg-dim border border-ink-400' ?>">
                    <?= $online ? 'ONLINE' : 'OFFLINE' ?>
                </span>
                <span class="text-[11px] px-2 py-0.5 rounded bg-ink-600 border border-ink-400 text-fg-mid font-mono"><?= overview_esc($machine['virtualization'] ?: 'Bare Metal') ?></span>
                <span class="text-[11px] px-2 py-0.5 rounded bg-ink-600 border border-ink-400 text-fg-mid font-mono">Kernel <?= overview_esc($machine['kernel']) ?></span>
            </div>
            <p class="text-xs text-fg-mid font-mono"><?= overview_esc($machine['os']) ?> | CPU: <?= overview_esc($machine['cpu_brand']) ?> (<?= (int) $machine['cpu_cores'] ?> Cores)</p>
            <div class="flex flex-wrap items-center gap-4 text-[11px] text-fg-mid pt-1 font-mono">
                <span><i class="fas fa-network-wired text-fg-dim mr-1"></i> IP local: <strong class="text-fg font-medium"><?= overview_esc($machine['ip_local'] ?: '--') ?></strong></span>
                <span><i class="fas fa-globe text-fg-dim mr-1"></i> IP público: <strong class="text-fg font-medium"><?= overview_esc($machine['ip_public'] ?: '--') ?></strong></span>
                <span><i class="far fa-clock text-fg-dim mr-1"></i> Último contato: <strong class="text-fg font-medium"><?= overview_esc($machine['last_seen']) ?></strong></span>
            </div>
        </div>
    </div>
    <div class="mt-4" hx-get="/?view=overview&part=vitals&machine_id=<?= overview_esc($machineId) ?>" hx-trigger="every <?= (int) max($interval, 1000) ?>s" hx-swap="innerHTML">
        <?php $_GET['part'] = 'vitals'; require __DIR__ . '/overview.php'; ?>
    </div>
</div>

<!-- Tabs -->
<div class="flex flex-wrap items-center gap-1.5 border-b border-ink-500 pb-px">
    <?php
    $tabs = [
        'charts' => ['Gráficos em tempo real', 'fa-chart-line'],
        'processes' => ['Top processos & I/O', 'fa-tasks'],
        'disks' => ['Armazenamento', 'fa-hard-drive'],
        'sensors' => ['Sensores & baterias', 'fa-thermometer-half'],
        'hardware' => ['Hardware & firmware', 'fa-shield-halved'],
    ];
    foreach ($tabs as $key => [$label, $icon]): ?>
    <button hx-get="/?view=overview&part=tab&machine_id=<?= overview_esc($machineId) ?>&tab=<?= $key ?>"
            hx-target="#tab-content" hx-swap="innerHTML transition:true"
            @click="$store.noc.tab = '<?= $key ?>'"
            class="px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 flex items-center gap-2"
            :class="$store.noc.tab === '<?= $key ?>' ? 'border-rh-red text-fg bg-[#262626]' : 'border-transparent text-fg-mid hover:text-fg hover:bg-ink-700'">
        <i class="fas <?= $icon ?>"></i> <?= $label ?>
    </button>
    <?php endforeach; ?>
</div>
<div id="tab-content">
    <?php $_GET['part'] = 'tab'; require __DIR__ . '/overview.php'; ?>
</div>
