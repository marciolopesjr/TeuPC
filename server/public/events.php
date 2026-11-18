<?php
require_once __DIR__ . '/inc/chrome.php';
chrome_start(['title' => 'Central de Eventos', 'active' => 'events']);
?>

        <!-- Cabecalho + filtros -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-disp text-lg font-bold text-fg">Central de Eventos</h2>
            <button onclick="refreshEvents()" class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-3.5 py-2 rounded-md font-medium transition-colors flex items-center gap-2">
                <i id="refresh-spinner" class="fas fa-arrows-rotate text-fg-mid"></i> Atualizar
            </button>
        </div>

        <div class="card p-4 flex flex-wrap items-end gap-3">
            <div>
                <span class="label block mb-1.5">M&aacute;quina</span>
                <select id="filter-machine" onchange="refreshEvents()" class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-xs text-fg focus:outline-none focus:border-rh-red/70 min-w-[180px] cursor-pointer">
                    <option value="">Todas</option>
                </select>
            </div>
            <div>
                <span class="label block mb-1.5">Severidade</span>
                <select id="filter-severity" onchange="refreshEvents()" class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-xs text-fg focus:outline-none focus:border-rh-red/70 cursor-pointer">
                    <option value="">Todas</option>
                    <option value="Critical">Critical</option>
                    <option value="Warning">Warning</option>
                    <option value="Info">Info</option>
                </select>
            </div>
            <div class="flex-1 min-w-[220px]">
                <span class="label block mb-1.5">Busca</span>
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-2 text-fg-dim text-xs"></i>
                    <input type="text" id="filter-search" oninput="applyClientFilter()" placeholder="Filtrar por t&iacute;tulo, categoria ou detalhe..." class="w-full bg-ink-900 border border-ink-500 rounded-md pl-8 pr-3 py-1.5 text-xs text-fg placeholder-fg-dim focus:outline-none focus:border-rh-red/70 transition-colors">
                </div>
            </div>
        </div>

        <!-- Tabela de eventos -->
        <div class="card p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h4 class="label !text-fg flex items-center gap-2">
                    <i class="fas fa-bell"></i> Hist&oacute;rico de eventos da frota
                </h4>
                <span id="events-count" class="text-[10px] text-fg-dim font-mono">-- eventos</span>
            </div>
            <div class="overflow-x-auto custom-scrollbar max-h-[620px] overflow-y-auto">
                <table class="w-full text-left text-xs font-mono">
                    <thead class="bg-ink-900 text-fg-dim border-b border-ink-500 sticky top-0">
                        <tr>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Hor&aacute;rio</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">M&aacute;quina</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Severidade</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Categoria</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Evento</th>
                            <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody id="events-table-body" class="divide-y divide-ink-500/60">
                        <tr><td colspan="6" class="p-4 text-center text-fg-dim">Carregando eventos...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-3">
                <button id="load-more-btn" onclick="loadMore()" class="hidden bg-ink-900 hover:bg-ink-700 text-fg-mid hover:text-fg border border-ink-500 text-xs px-4 py-2 rounded-md font-medium transition-colors">
                    Carregar mais
                </button>
            </div>
        </div>

    <script>
        const PAGE_SIZE = 50;
        let allEvents = [];
        let offset = 0;
        let loading = false;

        // Todo dado vindo do banco (hostname, titulo, detalhes...) passa por aqui
        // antes de entrar em innerHTML: titulo/detalhe do evento podem conter input de usuario.
        function esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }

        function severityBadge(sev) {
            if (sev === 'Critical') return { cls: 'text-rh-red border-rh-red/40 bg-rh-red/10', icon: 'fa-triangle-exclamation' };
            if (sev === 'Warning') return { cls: 'text-rh-amber border-rh-amber/40 bg-rh-amber/10', icon: 'fa-triangle-exclamation' };
            return { cls: 'text-rh-blue border-rh-blue/40 bg-rh-blue/10', icon: 'fa-info-circle' };
        }

        function currentParams() {
            const params = new URLSearchParams();
            const machine = document.getElementById('filter-machine').value;
            const severity = document.getElementById('filter-severity').value;
            if (machine) params.set('machine_id', machine);
            if (severity) params.set('severity', severity);
            params.set('limit', PAGE_SIZE);
            params.set('offset', offset);
            return params;
        }

        async function fetchEvents(reset) {
            if (loading) return;
            loading = true;
            document.getElementById('refresh-spinner').classList.add('fa-spin');
            try {
                const res = await fetch('api/events_list.php?' + currentParams());
                const data = await res.json();
                const events = data.events || [];

                if (reset) {
                    allEvents = [];
                    offset = 0;
                }
                allEvents = allEvents.concat(events);
                offset += events.length;

                renderTable();
                document.getElementById('events-count').innerText = `${allEvents.length} eventos`;
                document.getElementById('server-status').innerText = 'Conectado';
                document.getElementById('status-pulse-dot').className = 'w-2 h-2 rounded-full bg-rh-green pulse';
            } catch (err) {
                console.error('Erro ao buscar eventos:', err);
                document.getElementById('server-status').innerText = 'Erro';
                document.getElementById('status-pulse-dot').className = 'w-2 h-2 rounded-full bg-rh-red';
            } finally {
                loading = false;
                document.getElementById('refresh-spinner').classList.remove('fa-spin');
            }
        }

        function renderTable() {
            const tbody = document.getElementById('events-table-body');
            const filtered = clientFilter(allEvents);

            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr><td colspan="6" class="p-8 text-center text-fg-dim">
                        <i class="fas fa-bell-slash text-lg block mb-2 text-fg-mid"></i>
                        Nenhum evento registrado com os filtros atuais.
                    </td></tr>`;
                document.getElementById('load-more-btn').classList.add('hidden');
                return;
            }

            tbody.innerHTML = filtered.map(e => {
                const badge = severityBadge(e.severity);
                const time = e.created_at ? e.created_at.split(' ')[1] : '--:--:--';
                return `
                    <tr class="hover:bg-ink-900/60 transition-colors">
                        <td class="p-2.5 text-fg-dim whitespace-nowrap">${esc(time)}</td>
                        <td class="p-2.5 text-fg font-medium">${esc(e.hostname || e.machine_id || '-')}</td>
                        <td class="p-2.5">
                            <span class="text-[9px] px-1.5 py-0.5 rounded border font-mono font-medium ${badge.cls}">
                                <i class="fas ${badge.icon} mr-1"></i>${esc(e.severity || 'Info')}
                            </span>
                        </td>
                        <td class="p-2.5 text-fg-mid">${esc(e.category)}</td>
                        <td class="p-2.5 text-fg">${esc(e.title)}</td>
                        <td class="p-2.5 text-fg-dim max-w-[340px] truncate" title="${esc(e.details)}">${esc(e.details)}</td>
                    </tr>
                `;
            }).join('');

            // "Carregar mais" some quando a ultima pagina veio incompleta
            const btn = document.getElementById('load-more-btn');
            btn.classList.toggle('hidden', allEvents.length < offset);
        }

        // Filtro textual client-side sobre o que ja foi carregado
        function clientFilter(events) {
            const q = document.getElementById('filter-search').value.toLowerCase().trim();
            if (!q) return events;
            return events.filter(e =>
                (e.title || '').toLowerCase().includes(q) ||
                (e.category || '').toLowerCase().includes(q) ||
                (e.details || '').toLowerCase().includes(q)
            );
        }

        function applyClientFilter() {
            renderTable();
        }

        function loadMore() {
            fetchEvents(false);
        }

        function refreshEvents() {
            fetchEvents(true);
        }

        async function loadMachineFilter() {
            try {
                const res = await fetch('api/machines.php');
                const data = await res.json();
                const select = document.getElementById('filter-machine');
                (data.machines || []).forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.machine_id;
                    opt.textContent = m.hostname || m.machine_id;
                    select.appendChild(opt);
                });
                document.getElementById('server-time').innerText = (data.server_time || '').split(' ')[1] || '--:--:--';
            } catch (err) {
                console.error('Erro ao carregar máquinas:', err);
            }
        }

        loadMachineFilter();
        fetchEvents(true);
    </script>
<?php chrome_end(); ?>
