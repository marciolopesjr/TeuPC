<?php
require_once __DIR__ . '/inc/chrome.php';
chrome_start(['title' => 'Rede da frota', 'active' => 'network']);
?>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-disp text-lg font-bold text-fg">Rede</h2>
            <div class="flex items-center gap-2">
                <select id="select-machine" onchange="onMachineChange(this.value)"
                        class="bg-ink-900 border border-ink-500 rounded-md px-2.5 py-1.5 text-xs text-fg focus:outline-none focus:border-rh-red/70 cursor-pointer font-medium">
                    <option value="" class="bg-ink-700">Carregando máquinas...</option>
                </select>
                <button onclick="reloadSnapshot()" title="Buscar snapshot novamente, ignorando o cache local"
                        class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-3.5 py-2 rounded-md font-medium transition-colors flex items-center gap-2">
                    <i class="fas fa-arrows-rotate text-fg-mid" id="reload-spinner"></i> Recarregar snapshot
                </button>
            </div>
        </div>

        <!-- Snapshot de origem -->
        <div id="snapshot-meta" class="text-fg-dim font-mono text-[10px]">Selecione uma máquina.</div>

        <!-- Chips: gateway / DNS / TCP -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="panel p-3"><span class="label block">Gateway</span><span id="net-gateway" class="text-sm font-mono text-fg mt-1 block">--</span></div>
            <div class="panel p-3"><span class="label block">DNS</span><span id="net-dns" class="text-sm font-mono text-fg mt-1 block truncate" title="">--</span></div>
            <div class="panel p-3"><span class="label block">TCP estabelecidas</span><span id="net-tcp-estab" class="text-sm font-mono text-fg mt-1 block">--</span></div>
            <div class="panel p-3"><span class="label block">TCP em escuta</span><span id="net-tcp-listen" class="text-sm font-mono text-fg mt-1 block">--</span></div>
        </div>

        <!-- Interfaces de rede -->
        <div class="card p-4">
            <h4 class="label !text-fg mb-3 flex items-center gap-2">
                <i class="fas fa-network-wired"></i> Interfaces de rede
            </h4>
            <div id="net-interfaces-container" class="text-fg-dim text-center py-4 text-xs">Disponível a partir do snapshot técnico do host.</div>
        </div>

        <!-- Portas em escuta -->
        <div class="card p-4">
            <h4 class="label !text-fg mb-3 flex items-center gap-2">
                <i class="fas fa-door-open"></i> Portas em escuta
            </h4>
            <div class="overflow-x-auto custom-scrollbar max-h-[360px] overflow-y-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-ink-900 text-fg-dim border-b border-ink-500 sticky top-0">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Protocolo</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Endereço local</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="net-ports-body" class="divide-y divide-ink-500/60">
                        <tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tabela ARP -->
        <div class="card p-4">
            <h4 class="label !text-fg mb-3 flex items-center gap-2">
                <i class="fas fa-table-cells-large"></i> Tabela ARP (vizinhos na rede)
            </h4>
            <div class="overflow-x-auto custom-scrollbar max-h-[300px] overflow-y-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-ink-900 text-fg-dim border-b border-ink-500 sticky top-0">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Interface</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Endereço IP</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">MAC</th>
                        </tr>
                    </thead>
                    <tbody id="net-arp-body" class="divide-y divide-ink-500/60">
                        <tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    <script>
        let machines = [];
        let selectedMachineId = null;
        // Cache por maquina: { machine_id: { taken_at, data } }
        const snapshotCache = {};

        function esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }

        function setLoading(loading) {
            document.getElementById('reload-spinner').classList.toggle('fa-spin', loading);
        }

        async function fetchMachines() {
            try {
                const res = await fetch('api/machines.php');
                const data = await res.json();
                machines = data.machines || [];

                const select = document.getElementById('select-machine');
                select.innerHTML = machines.length === 0
                    ? '<option value="" class="bg-ink-700">Nenhuma máquina conectada</option>'
                    : machines.map(m =>
                        `<option value="${esc(m.machine_id)}" class="bg-ink-700">${esc(m.hostname)}</option>`
                    ).join('');

                // Auto-seleciona a primeira online (ou a primeira da lista)
                if (machines.length > 0) {
                    const first = machines.find(m => m.is_online) || machines[0];
                    select.value = first.machine_id;
                    await loadSnapshot(first.machine_id, false);
                } else {
                    showAllEmpty();
                }
                document.getElementById('server-status').innerText = 'Conectado';
            } catch (err) {
                console.error('Erro ao buscar máquinas:', err);
                document.getElementById('select-machine').innerHTML =
                    '<option value="" class="bg-ink-700">Erro ao carregar</option>';
                document.getElementById('server-status').innerText = 'Erro';
            }
        }

        function showAllEmpty() {
            document.getElementById('snapshot-meta').innerText = 'Selecione uma máquina.';
            for (const id of ['net-gateway', 'net-dns', 'net-tcp-estab', 'net-tcp-listen']) {
                document.getElementById(id).innerText = '--';
            }
            for (const id of ['net-interfaces-container']) {
                document.getElementById(id).innerHTML = '<div class="text-fg-dim text-center py-4 text-xs">Disponível a partir do snapshot técnico do host.</div>';
            }
            document.getElementById('net-ports-body').innerHTML =
                '<tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>';
            document.getElementById('net-arp-body').innerHTML =
                '<tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>';
        }

        function onMachineChange(machineId) {
            selectedMachineId = machineId;
            loadSnapshot(machineId, false);
        }

        function reloadSnapshot() {
            if (!selectedMachineId) return;
            loadSnapshot(selectedMachineId, true);
        }

        async function loadSnapshot(machineId, force) {
            if (!machineId) return;
            selectedMachineId = machineId;
            setLoading(true);
            try {
                if (force || !snapshotCache[machineId]) {
                    const res = await fetch(`api/snapshot.php?machine_id=${encodeURIComponent(machineId)}`);
                    const data = await res.json();
                    if (!data.snapshots || data.snapshots.length === 0) {
                        snapshotCache[machineId] = null;
                    } else {
                        snapshotCache[machineId] = {
                            taken_at: data.snapshots[0].created_at || null,
                            data: JSON.parse(data.snapshots[0].snapshot_json)
                        };
                    }
                }
                const snap = snapshotCache[machineId];
                document.getElementById('snapshot-meta').innerText = snap
                    ? `Dados do snapshot de ${snap.taken_at || '--'}`
                    : 'Nenhum snapshot técnico registrado para esta máquina.';
                renderNetwork(snap);
            } catch (err) {
                console.error('Erro ao carregar snapshot:', err);
                document.getElementById('snapshot-meta').innerText = 'Erro ao carregar snapshot.';
            } finally {
                setLoading(false);
            }
        }

        function renderNetwork(snap) {
            const na = snap && snap.data ? snap.data.network_advanced : null;
            const nets = snap && snap.data ? snap.data.networks : null;

            const empty = '<div class="text-fg-dim text-center py-4 text-xs">Disponível a partir do snapshot técnico do host.</div>';

            if (!snap || !snap.data) {
                showAllEmpty();
                return;
            }

            document.getElementById('net-gateway').innerText = na && na.default_gateway ? na.default_gateway : '--';
            const dnsEl = document.getElementById('net-dns');
            dnsEl.innerText = na && na.dns_servers && na.dns_servers.length ? na.dns_servers.join(', ') : '--';
            dnsEl.title = dnsEl.innerText;
            document.getElementById('net-tcp-estab').innerText = na && na.tcp_established != null ? na.tcp_established : '--';
            document.getElementById('net-tcp-listen').innerText = na && na.tcp_listen != null ? na.tcp_listen : '--';

            // Interfaces
            const ifContainer = document.getElementById('net-interfaces-container');
            if (nets && nets.length > 0) {
                ifContainer.innerHTML = `
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-ink-900 text-fg-dim border-b border-ink-500">
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
                                ${nets.map(n => {
                                    const errTotal = (n.rx_errors || 0) + (n.tx_errors || 0);
                                    const dropTotal = (n.rx_dropped || 0) + (n.tx_dropped || 0);
                                    return `
                                    <tr class="hover:bg-ink-800/70 transition-colors">
                                        <td class="p-2.5 font-semibold text-fg">${esc(n.name)}</td>
                                        <td class="p-2.5 text-fg-dim">${esc(n.iface_type || '-')}</td>
                                        <td class="p-2.5 text-rh-blue">${esc(n.ipv4 || '-')}</td>
                                        <td class="p-2.5 text-rh-green">${(n.rx_per_sec || 0).toFixed(1)} KB/s</td>
                                        <td class="p-2.5 text-rh-amber">${(n.tx_per_sec || 0).toFixed(1)} KB/s</td>
                                        <td class="p-2.5 text-fg-mid">${(n.rx_total || 0).toFixed(1)} MB</td>
                                        <td class="p-2.5 text-fg-mid">${(n.tx_total || 0).toFixed(1)} MB</td>
                                        <td class="p-2.5 ${errTotal > 0 ? 'text-rh-red' : 'text-fg-dim'}">${errTotal} / ${dropTotal}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>`;
            } else {
                ifContainer.innerHTML = empty;
            }

            // Portas em escuta
            const portsBody = document.getElementById('net-ports-body');
            if (na && na.listening_ports && na.listening_ports.length > 0) {
                portsBody.innerHTML = na.listening_ports.map(p => `
                    <tr class="hover:bg-ink-800/70 transition-colors">
                        <td class="p-2.5 text-fg-dim">${esc(p.protocol || 'TCP')}</td>
                        <td class="p-2.5 font-semibold text-fg">${esc(p.local_address)}</td>
                        <td class="p-2.5 text-rh-blue">${esc(p.state || 'LISTEN')}</td>
                    </tr>
                `).join('');
            } else {
                portsBody.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>`;
            }

            // Tabela ARP
            const arpBody = document.getElementById('net-arp-body');
            if (na && na.arp_entries && na.arp_entries.length > 0) {
                arpBody.innerHTML = na.arp_entries.map(a => `
                    <tr class="hover:bg-ink-800/70 transition-colors">
                        <td class="p-2.5 text-fg-dim">${esc(a.interface)}</td>
                        <td class="p-2.5 text-fg">${esc(a.ip_address)}</td>
                        <td class="p-2.5 text-fg-mid">${esc(a.mac_address)}</td>
                    </tr>
                `).join('');
            } else {
                arpBody.innerHTML = `<tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico do host.</td></tr>`;
            }
        }

        fetchMachines();
    </script>
<?php chrome_end(); ?>
