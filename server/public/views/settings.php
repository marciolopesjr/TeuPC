<?php
// View "Configuracoes": form HTMX que grava no SQLite via Settings.
// POST -> valida -> salva -> devolve o proprio card (outerHTML) + toast + atualiza window.TEUPC.

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Settings.php';

use TeuPC\Central\Database;
use TeuPC\Central\Settings;

$db = Database::getConnection();

// ---- helpers (protegidos contra redeclaracao, pois este arquivo pode reincluir-se) --

if (!function_exists('settings_esc')) {
    function settings_esc($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Card do formulario (raiz do swap outerHTML). $msg: toast opcional apos salvar.
if (!function_exists('settings_render_form')) {
    function settings_render_form(array $cfg, string $msg = ''): void
    {
        $intervals = [
            '1000' => '1s — NOC agressivo',
            '2000' => '2s — recomendado',
            '5000' => '5s — econômico',
            '0'    => 'Pausado — só manual',
        ];
        ?>
        <div id="settings-form-card" class="card p-5 space-y-5">
            <form hx-post="/?view=settings" hx-target="closest .card" hx-swap="outerHTML">
                <h4 class="label !text-fg flex items-center gap-2 mb-5">
                    <i class="fas fa-sliders text-rh-red"></i> Preferências do dashboard
                </h4>

                <div class="mb-5">
                    <span class="label block mb-2">Intervalo de atualização</span>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($intervals as $val => $lbl): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="interval_ms" value="<?= settings_esc($val) ?>" class="peer sr-only"<?= $cfg['interval_ms'] === (int) $val ? ' checked' : '' ?>>
                            <span class="block bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg-mid font-mono peer-checked:border-rh-red peer-checked:text-fg hover:border-ink-400 transition-colors"><?= settings_esc($lbl) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg mb-5">
                    <div>
                        <label for="input-cpu" class="label block mb-2">Limite de CPU (%)</label>
                        <input type="number" id="input-cpu" name="cpu" value="<?= (int) $cfg['cpu'] ?>" min="1" max="100" step="1"
                               class="w-full bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg font-mono focus:outline-none focus:border-rh-red/70 transition-colors">
                        <p class="text-[10px] text-fg-dim mt-1">Acima disso, barras de CPU ficam vermelhas no dashboard.</p>
                    </div>
                    <div>
                        <label for="input-ram" class="label block mb-2">Limite de RAM (GB)</label>
                        <input type="number" id="input-ram" name="ram_gb" value="<?= (int) $cfg['ram_gb'] ?>" min="0" step="1"
                               class="w-full bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg font-mono focus:outline-none focus:border-rh-red/70 transition-colors">
                        <p class="text-[10px] text-fg-dim mt-1">Reservado para uso futuro do dashboard.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="bg-rh-red hover:bg-rh-reddark text-white text-xs px-4 py-2 rounded-md font-semibold transition-colors">
                        <i class="fas fa-floppy-disk mr-1.5"></i>Salvar
                    </button>
                    <button type="button" hx-post="/?view=settings" hx-vals='{"reset":"1"}' hx-target="closest .card" hx-swap="outerHTML"
                            class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-4 py-2 rounded-md font-medium transition-colors">
                        <i class="fas fa-rotate-left mr-1.5 text-fg-mid"></i>Restaurar padrão
                    </button>
                </div>
            </form>
            <?php if ($msg !== ''): ?>
            <script>
                teupcToast('<?= settings_esc($msg) ?>', 'ok');
                window.TEUPC = Object.assign(window.TEUPC || {}, {
                    interval_ms: <?= (int) $cfg['interval_ms'] ?>,
                    thresholds: { cpu: <?= (int) $cfg['cpu'] ?>, ram_gb: <?= (int) $cfg['ram_gb'] ?> },
                });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
}

// ---- POST: trata salvar / restaurar --------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['reset'] ?? '') === '1') {
        foreach (Settings::DEFAULTS as $key => $value) {
            Settings::set($key, $value);
        }
        settings_render_form(Settings::all(), 'Configurações restauradas para o padrão.');
        return;
    }

    // Valor invalido = chave ignorada (mantem a atual).
    if (isset($_POST['interval_ms'])) {
        $iv = (int) $_POST['interval_ms'];
        if (in_array($iv, [1000, 2000, 5000, 0], true)) {
            Settings::set('interval_ms', $iv);
        }
    }
    if (isset($_POST['cpu'])) {
        $cpu = (int) $_POST['cpu'];
        if ($cpu >= 1 && $cpu <= 100) {
            Settings::set('cpu', $cpu);
        }
    }
    if (isset($_POST['ram_gb'])) {
        $ram = (int) $_POST['ram_gb'];
        if ($ram >= 0) {
            Settings::set('ram_gb', $ram);
        }
    }
    settings_render_form(Settings::all(), 'Configurações salvas.');
    return;
}

// ---- GET: pagina completa -------------------------------------------------

$cfg = Settings::all();
$config = require __DIR__ . '/../../config.php';

$counts = [
    'Máquinas registradas'  => (int) $db->query("SELECT COUNT(*) c FROM machines")->fetch()['c'],
    'Eventos registrados'   => (int) $db->query("SELECT COUNT(*) c FROM events_log")->fetch()['c'],
    'Snapshots registrados' => (int) $db->query("SELECT COUNT(*) c FROM snapshots")->fetch()['c'],
];
?>

<div class="flex flex-wrap items-center justify-between gap-3">
    <h2 class="font-disp text-lg font-bold text-fg">Configurações</h2>
</div>

<?php settings_render_form($cfg); ?>

<!-- Seção 2: Servidor (somente leitura, renderizado server-side) -->
<div class="card p-5 space-y-3">
    <h4 class="label !text-fg flex items-center gap-2">
        <i class="fas fa-server text-fg-mid"></i> Servidor
        <span class="text-[10px] text-fg-dim font-mono uppercase tracking-wider ml-1">somente leitura</span>
    </h4>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="panel p-3.5 space-y-2">
            <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Configuração</div>
            <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Threshold offline:</span><span class="text-fg"><?= (int) $config['offline_threshold_secs'] ?> s</span></div>
            <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Versão PHP:</span><span class="text-fg"><?= settings_esc(PHP_VERSION) ?></span></div>
            <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Server time:</span><span class="text-fg"><?= settings_esc(date('d/m/Y H:i:s')) ?></span></div>
        </div>
        <div class="panel p-3.5 space-y-2">
            <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Contadores</div>
            <?php foreach ($counts as $lbl => $n): ?>
            <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim"><?= settings_esc($lbl) ?>:</span><span class="text-fg"><?= $n ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
