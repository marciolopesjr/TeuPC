<?php
require_once __DIR__ . '/inc/chrome.php';
chrome_start(['title' => 'Configurações', 'active' => 'settings']);
?>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-disp text-lg font-bold text-fg">Configurações</h2>
        </div>

        <!-- Seção 1: Preferências do dashboard -->
        <div class="card p-5 space-y-5">
            <h4 class="label !text-fg flex items-center gap-2">
                <i class="fas fa-sliders text-rh-red"></i> Preferências do dashboard
            </h4>

            <!-- Intervalo de atualização -->
            <div>
                <span class="label block mb-2">Intervalo de atualização</span>
                <div id="interval-group" class="flex flex-wrap gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="interval" value="1000" class="peer sr-only">
                        <span class="block bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg-mid font-mono peer-checked:border-rh-red peer-checked:text-fg hover:border-ink-400 transition-colors">1s — NOC agressivo</span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="interval" value="2000" class="peer sr-only">
                        <span class="block bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg-mid font-mono peer-checked:border-rh-red peer-checked:text-fg hover:border-ink-400 transition-colors">2s — recomendado</span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="interval" value="5000" class="peer sr-only">
                        <span class="block bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg-mid font-mono peer-checked:border-rh-red peer-checked:text-fg hover:border-ink-400 transition-colors">5s — econômico</span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="interval" value="0" class="peer sr-only">
                        <span class="block bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg-mid font-mono peer-checked:border-rh-red peer-checked:text-fg hover:border-ink-400 transition-colors">Pausado — só manual</span>
                    </label>
                </div>
            </div>

            <!-- Limites -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
                <div>
                    <label for="input-cpu" class="label block mb-2">Limite de CPU (%)</label>
                    <input type="number" id="input-cpu" min="1" max="100" step="1" class="w-full bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg font-mono focus:outline-none focus:border-rh-red/70 transition-colors">
                    <p class="text-[10px] text-fg-dim mt-1">Acima disso, barras de CPU ficam vermelhas no dashboard.</p>
                </div>
                <div>
                    <label for="input-ram" class="label block mb-2">Limite de RAM (GB)</label>
                    <input type="number" id="input-ram" min="0" step="1" class="w-full bg-ink-900 border border-ink-500 rounded-md px-3 py-2 text-xs text-fg font-mono focus:outline-none focus:border-rh-red/70 transition-colors">
                    <p class="text-[10px] text-fg-dim mt-1">Reservado para uso futuro do dashboard.</p>
                </div>
            </div>

            <p id="settings-error" class="text-xs text-rh-red hidden"></p>

            <div class="flex items-center gap-3">
                <button onclick="saveSettings()" class="bg-rh-red hover:bg-rh-reddark text-white text-xs px-4 py-2 rounded-md font-semibold transition-colors">
                    <i class="fas fa-floppy-disk mr-1.5"></i>Salvar
                </button>
                <button onclick="resetSettings()" class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-4 py-2 rounded-md font-medium transition-colors">
                    <i class="fas fa-rotate-left mr-1.5 text-fg-mid"></i>Restaurar padrão
                </button>
            </div>

            <p id="settings-saved" class="text-xs text-rh-green hidden">
                <i class="fas fa-check mr-1"></i>Salvo — recarregue o dashboard para aplicar em aberto
            </p>
        </div>

        <!-- Seção 2: Servidor (somente leitura) -->
        <div class="card p-5 space-y-3">
            <h4 class="label !text-fg flex items-center gap-2">
                <i class="fas fa-server text-fg-mid"></i> Servidor
                <span class="text-[10px] text-fg-dim font-mono uppercase tracking-wider ml-1">somente leitura</span>
            </h4>
            <div id="server-info" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="panel p-3.5 space-y-2">
                    <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Configuração</div>
                    <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Threshold offline:</span><span id="info-threshold" class="text-fg">-- s</span></div>
                    <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Versão PHP:</span><span id="info-php" class="text-fg">--</span></div>
                    <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Server time:</span><span id="info-time" class="text-fg">--</span></div>
                </div>
                <div class="panel p-3.5 space-y-2">
                    <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Contadores</div>
                    <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Máquinas registradas:</span><span id="info-machines" class="text-fg">--</span></div>
                    <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Eventos registrados:</span><span id="info-events" class="text-fg">--</span></div>
                    <div class="flex justify-between font-mono text-xs"><span class="text-fg-dim">Snapshots registrados:</span><span id="info-snapshots" class="text-fg">--</span></div>
                </div>
            </div>
        </div>

    <script>
        const DEFAULTS = { interval: 2000, cpu: 85, ram_gb: 4 };

        function loadSettings() {
            let thresholds = {};
            try { thresholds = JSON.parse(localStorage.getItem('teupc.thresholds') || '{}') || {}; } catch (e) {}
            const interval = parseInt(localStorage.getItem('teupc.interval_ms'));
            const cpu = parseInt(thresholds.cpu);
            const ram = parseInt(thresholds.ram_gb);

            const checked = document.querySelector(`input[name="interval"][value="${isNaN(interval) ? DEFAULTS.interval : interval}"]`);
            if (checked) checked.checked = true;
            document.getElementById('input-cpu').value = isNaN(cpu) ? DEFAULTS.cpu : cpu;
            document.getElementById('input-ram').value = isNaN(ram) ? DEFAULTS.ram_gb : ram;
        }

        function showError(msg) {
            const el = document.getElementById('settings-error');
            if (!msg) { el.classList.add('hidden'); return; }
            el.innerText = msg;
            el.classList.remove('hidden');
        }

        function saveSettings() {
            showError('');
            const interval = document.querySelector('input[name="interval"]:checked');
            const cpu = parseInt(document.getElementById('input-cpu').value, 10);
            const ram = parseInt(document.getElementById('input-ram').value, 10);

            if (isNaN(cpu) || cpu < 1 || cpu > 100) {
                showError('Limite de CPU inválido: informe um valor entre 1 e 100.');
                return;
            }

            localStorage.setItem('teupc.interval_ms', String(interval ? parseInt(interval.value, 10) : DEFAULTS.interval));
            localStorage.setItem('teupc.thresholds', JSON.stringify({ cpu: cpu, ram_gb: isNaN(ram) ? DEFAULTS.ram_gb : ram }));

            const saved = document.getElementById('settings-saved');
            saved.classList.remove('hidden');
            clearTimeout(saveSettings._t);
            saveSettings._t = setTimeout(() => saved.classList.add('hidden'), 5000);
        }

        function resetSettings() {
            showError('');
            localStorage.removeItem('teupc.interval_ms');
            localStorage.removeItem('teupc.thresholds');
            loadSettings();
            const saved = document.getElementById('settings-saved');
            saved.classList.add('hidden');
        }

        async function loadServerInfo() {
            try {
                const res = await fetch('api/settings_info.php');
                const d = await res.json();
                document.getElementById('info-threshold').innerText = d.offline_threshold_secs + ' s';
                document.getElementById('info-php').innerText = d.php_version;
                document.getElementById('info-time').innerText = d.server_time;
                document.getElementById('info-machines').innerText = d.counts.machines;
                document.getElementById('info-events').innerText = d.counts.events;
                document.getElementById('info-snapshots').innerText = d.counts.snapshots;
            } catch (err) {
                document.getElementById('info-threshold').innerText = 'erro ao carregar';
            }
        }

        loadSettings();
        loadServerInfo();
    </script>
<?php chrome_end(); ?>
