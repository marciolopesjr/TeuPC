<?php
require_once __DIR__ . '/../src/Auth.php';

use TeuPC\Central\Auth;

if (isset($_GET['logout'])) {
    Auth::startSession();
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

Auth::requireDashboard();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeuPC Central | NOC Dashboard &amp; Hardware Telemetry</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink:    { 900: '#0f0f0f', 800: '#151515', 700: '#1f1f1f', 600: '#262626', 500: '#2f2f2f', 400: '#3f3f3f' },
                        fg:     { DEFAULT: '#f2f2f2', mid: '#a1a1a1', dim: '#6b6b6b' },
                        rh:     { red: '#EE0000', reddark: '#c40000', blue: '#0088CE', teal: '#00B8AF', green: '#4CA64C', amber: '#F0AB00' },
                    },
                    fontFamily: {
                        ui:   ['"Red Hat Text"', 'system-ui', 'sans-serif'],
                        disp: ['"Red Hat Display"', 'system-ui', 'sans-serif'],
                        mono: ['"Red Hat Mono"', 'ui-monospace', 'monospace'],
                    },
                }
            }
        }
    </script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800&family=Red+Hat+Text:wght@400;500;600;700&family=Red+Hat+Mono:wght@400;500;600&display=swap');
        body { font-family: 'Red Hat Text', system-ui, sans-serif; background: #0f0f0f; color: #f2f2f2; }
        ::selection { background: rgba(238,0,0,.25); color: #fff; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #3f3f3f; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #5c5c5c; }
        .card { background: #1f1f1f; border: 1px solid #2f2f2f; border-radius: 8px; }
        .panel { background: #151515; border: 1px solid #2f2f2f; border-radius: 6px; }
        .label { font-size: 10px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #8a8a8a; }
        .pulse { animation: pulse 2.4s cubic-bezier(.4,0,.6,1) infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .45; } }
        .tab-active { border-color: #EE0000; color: #f2f2f2; background: #262626; }
        .tab-idle   { border-color: transparent; color: #a1a1a1; background: transparent; }
        .tab-idle:hover { color: #f2f2f2; background: #1f1f1f; }
    </style>
</head>
<body class="min-h-screen flex flex-col antialiased">

    <!-- Top Navigation Header -->
    <header class="bg-ink-800 border-b border-ink-500 sticky top-0 z-50 px-4 lg:px-8 py-3">
        <div class="max-w-[1700px] mx-auto flex flex-wrap items-center justify-between gap-4">

            <!-- Logo & Brand Title -->
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-md bg-rh-red flex items-center justify-center font-disp font-extrabold text-white text-base">T</div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="font-disp text-[15px] font-bold tracking-tight text-fg leading-none">TeuPC <span class="text-fg-dim font-medium">Central</span></h1>
                        <span class="text-[9px] px-1.5 py-px rounded bg-ink-600 border border-ink-400 text-fg-mid font-mono tracking-widest uppercase">NOC v2.0</span>
                    </div>
                    <p class="text-[11px] text-fg-dim leading-tight mt-0.5">Centro de comando &amp; telemetria em tempo real</p>
                </div>
            </div>

            <!-- Global Controls & Status -->
            <div class="flex flex-wrap items-center gap-3 text-xs">

                <!-- Refresh Interval Selector -->
                <div class="flex items-center bg-ink-900 border border-ink-500 rounded-md px-2.5 py-1.5 gap-2">
                    <i class="fas fa-arrows-rotate text-fg-dim" id="refresh-spinner"></i>
                    <span class="text-fg-dim text-[11px]">Intervalo:</span>
                    <select id="select-interval" onchange="changeInterval(this.value)" class="bg-transparent text-fg font-medium focus:outline-none cursor-pointer text-xs">
                        <option value="1000" class="bg-ink-700">1s (Rápido)</option>
                        <option value="2000" class="bg-ink-700" selected>2s (Normal)</option>
                        <option value="5000" class="bg-ink-700">5s (Econômico)</option>
                        <option value="0" class="bg-ink-700">Pausado</option>
                    </select>
                </div>

                <!-- Connection Pulse -->
                <div class="flex items-center gap-2 bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5">
                    <span id="status-pulse-dot" class="w-2 h-2 rounded-full bg-rh-green pulse"></span>
                    <span id="server-status" class="text-fg font-medium text-[11px]">Conectado</span>
                </div>

                <!-- Server Time Clock -->
                <div class="hidden sm:flex items-center gap-1.5 bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-fg-mid font-mono text-[11px]">
                    <i class="far fa-clock text-fg-dim"></i>
                    <span id="server-time">--:--:--</span>
                </div>

                <a href="?logout=1" class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-fg-mid hover:text-fg hover:border-ink-400 text-[11px] transition-colors">
                    <i class="fas fa-right-from-bracket mr-1"></i>Sair
                </a>

            </div>
        </div>
    </header>

    <!-- Main Workspace Container -->
    <main class="flex-1 max-w-[1700px] w-full mx-auto p-4 lg:p-6 space-y-5">

        <!-- Global Metric Stat Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <div class="card p-4 flex items-center justify-between hover:border-ink-400 transition-colors">
                <div>
                    <span class="label block mb-1.5">Máquinas totais</span>
                    <span id="metric-total-machines" class="text-2xl lg:text-3xl font-disp font-bold text-fg font-mono">0</span>
                </div>
                <i class="fas fa-server text-fg-dim text-lg"></i>
            </div>

            <div class="card p-4 flex items-center justify-between hover:border-ink-400 transition-colors">
                <div>
                    <span class="label block mb-1.5">Hosts online</span>
                    <span id="metric-online-machines" class="text-2xl lg:text-3xl font-disp font-bold text-rh-green font-mono">0</span>
                </div>
                <i class="fas fa-signal text-fg-dim text-lg"></i>
            </div>

            <div class="card p-4 flex items-center justify-between hover:border-ink-400 transition-colors">
                <div>
                    <span class="label block mb-1.5">Anomalias / Alertas</span>
                    <span id="metric-total-alerts" class="text-2xl lg:text-3xl font-disp font-bold text-rh-amber font-mono">0</span>
                </div>
                <i class="fas fa-triangle-exclamation text-fg-dim text-lg"></i>
            </div>

            <div class="card p-4 flex items-center justify-between hover:border-ink-400 transition-colors">
                <div>
                    <span class="label block mb-1.5">Carga média CPU</span>
                    <span id="metric-avg-cpu" class="text-2xl lg:text-3xl font-disp font-bold text-fg font-mono">0.0%</span>
                </div>
                <i class="fas fa-microchip text-fg-dim text-lg"></i>
            </div>
        </div>

        <!-- Master / Detail Main Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

            <!-- LEFT SIDEBAR: Hosts & Live Feed (Col 4) -->
            <div class="lg:col-span-4 xl:col-span-3 space-y-4">

                <!-- Machines Fleet Box -->
                <div class="card p-4 flex flex-col">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="label !text-fg flex items-center gap-2">
                            <i class="fas fa-network-wired text-fg-mid"></i> Frota de hosts
                        </h2>
                        <span id="badge-online-count" class="text-[10px] bg-ink-600 border border-ink-400 text-fg-mid px-2 py-0.5 rounded-full font-mono font-medium">
                            0 hosts
                        </span>
                    </div>

                    <!-- Search Hosts Input -->
                    <div class="relative mb-3">
                        <i class="fas fa-search absolute left-3 top-2.5 text-fg-dim text-xs"></i>
                        <input type="text" id="filter-host-input" oninput="filterMachines(this.value)" placeholder="Filtrar por nome, IP, CPU..." class="w-full bg-ink-900 border border-ink-500 rounded-md pl-8 pr-3 py-1.5 text-xs text-fg placeholder-fg-dim focus:outline-none focus:border-rh-red/70 transition-colors">
                    </div>

                    <!-- Machine List Container -->
                    <div id="machines-container" class="space-y-2 max-h-[480px] overflow-y-auto custom-scrollbar pr-1">
                        <div class="text-center py-8 text-fg-dim text-xs">
                            <i class="fas fa-spinner fa-spin text-lg block mb-2 text-fg-mid"></i>
                            Aguardando daemons TeuPC...
                        </div>
                    </div>
                </div>

                <!-- Recent Anomaly Events Feed -->
                <div class="card p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="label !text-fg flex items-center gap-2">
                            <i class="fas fa-bell text-rh-amber"></i> Anomalias &amp; spikes
                        </h2>
                        <span class="text-[10px] text-fg-dim font-mono">tempo real</span>
                    </div>
                    <div id="recent-events-container" class="space-y-2 max-h-[320px] overflow-y-auto custom-scrollbar text-xs">
                        <div class="text-fg-dim text-center py-4">Nenhuma anomalia registrada.</div>
                    </div>
                </div>

            </div>

            <!-- RIGHT MAIN PANEL: Machine Details & Deep Inspection (Col 8/9) -->
            <div class="lg:col-span-8 xl:col-span-9 space-y-4">

                <!-- Machine Hero Header -->
                <div id="machine-hero" class="card p-5 hidden">
                    <div class="flex flex-wrap items-start justify-between gap-4 pb-4 border-b border-ink-500">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h3 id="hero-hostname" class="font-disp text-xl font-bold text-fg tracking-tight">Carregando...</h3>
                                <span id="hero-status-badge" class="text-[11px] px-2.5 py-0.5 rounded-full font-semibold">ONLINE</span>
                                <span id="hero-virt-badge" class="text-[11px] px-2 py-0.5 rounded bg-ink-600 border border-ink-400 text-fg-mid font-mono">Bare Metal</span>
                                <span id="hero-kernel-badge" class="text-[11px] px-2 py-0.5 rounded bg-ink-600 border border-ink-400 text-fg-mid font-mono">Kernel</span>
                            </div>
                            <p id="hero-cpu-info" class="text-xs text-fg-mid font-mono">Processador: --</p>
                            <div class="flex flex-wrap items-center gap-4 text-[11px] text-fg-mid pt-1 font-mono">
                                <span><i class="fas fa-network-wired text-fg-dim mr-1"></i> IP local: <strong id="hero-ip-local" class="text-fg font-medium">--</strong></span>
                                <span><i class="fas fa-globe text-fg-dim mr-1"></i> IP público: <strong id="hero-ip-public" class="text-fg font-medium">--</strong></span>
                                <span><i class="far fa-clock text-fg-dim mr-1"></i> Último contato: <strong id="hero-last-seen" class="text-fg font-medium">--</strong></span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2">
                            <button onclick="viewLatestSnapshot()" class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-3.5 py-2 rounded-md font-medium transition-colors flex items-center gap-2">
                                <i class="fas fa-file-code text-fg-mid"></i> Snapshot JSON
                            </button>
                        </div>
                    </div>

                    <!-- Quick Vitals Gauges Row -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 mt-4">

                        <!-- CPU Usage -->
                        <div class="panel p-3 text-center">
                            <span class="label block">Uso de CPU</span>
                            <span id="quick-cpu" class="text-xl lg:text-2xl font-disp font-bold text-fg mt-1 block font-mono">0.0%</span>
                            <div class="w-full bg-ink-600 h-1 rounded-full mt-2 overflow-hidden">
                                <div id="quick-cpu-bar" class="bg-rh-blue h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- RAM Usage -->
                        <div class="panel p-3 text-center">
                            <span class="label block">Memória RAM</span>
                            <span id="quick-ram" class="text-xl lg:text-2xl font-disp font-bold text-fg mt-1 block font-mono">0 / 0 GB</span>
                            <div class="w-full bg-ink-600 h-1 rounded-full mt-2 overflow-hidden">
                                <div id="quick-ram-bar" class="bg-rh-teal h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- CPU Package Temperature -->
                        <div class="panel p-3 text-center">
                            <span class="label block">Temp. pacote CPU</span>
                            <span id="quick-temp" class="text-xl lg:text-2xl font-disp font-bold text-fg mt-1 block font-mono">-- °C</span>
                            <div class="w-full bg-ink-600 h-1 rounded-full mt-2 overflow-hidden">
                                <div id="quick-temp-bar" class="bg-rh-amber h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- RAPL Watts -->
                        <div class="panel p-3 text-center">
                            <span class="label block">Consumo RAPL</span>
                            <span id="quick-rapl" class="text-xl lg:text-2xl font-disp font-bold text-fg mt-1 block font-mono">-- W</span>
                            <span id="quick-rapl-sub" class="text-[10px] text-fg-dim block mt-1">Package Power</span>
                        </div>

                        <!-- Load 1m -->
                        <div class="panel p-3 text-center">
                            <span class="label block">Load average</span>
                            <span id="quick-load" class="text-xl lg:text-2xl font-disp font-bold text-fg mt-1 block font-mono">0.00</span>
                            <span id="quick-cores-sub" class="text-[10px] text-fg-dim block mt-1">1m Load</span>
                        </div>

                    </div>
                </div>

                <!-- Navigation Tabs Row -->
                <div id="tabs-container" class="flex flex-wrap items-center gap-1.5 border-b border-ink-500 pb-px hidden">
                    <button onclick="switchTab('charts')" id="tab-btn-charts" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-active flex items-center gap-2">
                        <i class="fas fa-chart-line"></i> Gráficos em tempo real
                    </button>
                    <button onclick="switchTab('processes')" id="tab-btn-processes" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2">
                        <i class="fas fa-tasks"></i> Top processos &amp; I/O
                    </button>
                    <button onclick="switchTab('disks')" id="tab-btn-disks" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2">
                        <i class="fas fa-hard-drive"></i> Armazenamento
                    </button>
                    <button onclick="switchTab('sensors')" id="tab-btn-sensors" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2">
                        <i class="fas fa-thermometer-half"></i> Sensores &amp; baterias
                    </button>
                    <button onclick="switchTab('hardware')" id="tab-btn-hardware" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2">
                        <i class="fas fa-shield-halved"></i> Hardware &amp; firmware
                    </button>
                    <button onclick="switchTab('network')" id="tab-btn-network" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2">
                        <i class="fas fa-network-wired"></i> Rede
                    </button>
                    <button onclick="switchTab('events')" id="tab-btn-events" class="tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2">
                        <i class="fas fa-bell"></i> Eventos
                    </button>
                </div>

                <!-- TAB 1: Real-time Charts View -->
                <div id="tab-pane-charts" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">

                    <!-- CPU Chart -->
                    <div class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="label !text-fg-mid flex items-center gap-2">
                                <i class="fas fa-microchip"></i> Histórico carga de CPU (%)
                            </h4>
                            <span id="chart-cpu-val" class="text-xs font-mono font-medium text-fg">0.0%</span>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-cpu"></canvas>
                        </div>
                    </div>

                    <!-- RAM Chart -->
                    <div class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="label !text-fg-mid flex items-center gap-2">
                                <i class="fas fa-memory"></i> Histórico consumo de RAM (GB)
                            </h4>
                            <span id="chart-ram-val" class="text-xs font-mono font-medium text-fg">0.0 GB</span>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-ram"></canvas>
                        </div>
                    </div>

                    <!-- Disk I/O Chart -->
                    <div class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="label !text-fg-mid flex items-center gap-2">
                                <i class="fas fa-hard-drive"></i> I/O de disco (Read / Write KB/s)
                            </h4>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-disk"></canvas>
                        </div>
                    </div>

                    <!-- Network Traffic Chart -->
                    <div class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="label !text-fg-mid flex items-center gap-2">
                                <i class="fas fa-network-wired"></i> Tráfego de rede (RX / TX KB/s)
                            </h4>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-net"></canvas>
                        </div>
                    </div>

                </div>

                <!-- TAB 2: Processes Table View -->
                <div id="tab-pane-processes" class="card p-4 space-y-3 hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h4 class="label !text-fg flex items-center gap-2">
                            <i class="fas fa-tasks"></i> Top processos ativos no host
                        </h4>
                        <input type="text" id="filter-proc-input" oninput="filterProcesses(this.value)" placeholder="Filtrar por PID, nome ou usuário..." class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-xs text-fg placeholder-fg-dim focus:outline-none focus:border-rh-red/70 w-64 transition-colors">
                    </div>

                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-ink-900 text-fg-dim border-b border-ink-500">
                                <tr>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">PID</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Nome</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Usuário</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">CPU %</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Memória</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Disk Read</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Disk Write</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Threads</th>
                                </tr>
                            </thead>
                            <tbody id="processes-table-body" class="divide-y divide-ink-500/60">
                                <tr>
                                    <td colspan="8" class="p-4 text-center text-fg-dim">Carregando processos...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 3: Disks & Storage View -->
                <div id="tab-pane-disks" class="space-y-4 hidden">
                    <div class="card p-4">
                        <h4 class="label !text-fg mb-4 flex items-center gap-2">
                            <i class="fas fa-hard-drive"></i> Unidades de disco &amp; agendadores I/O
                        </h4>
                        <div id="disks-cards-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="text-fg-dim text-center py-6 col-span-2 text-xs">Nenhum disco reportado na telemetria recente.</div>
                        </div>
                    </div>
                    <div class="card p-4">
                        <h4 class="label !text-fg mb-4 flex items-center gap-2">
                            <i class="fas fa-folder-tree"></i> Filesystems montados
                        </h4>
                        <div id="filesystems-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="text-fg-dim text-center py-6 col-span-2 text-xs">Disponível a partir do snapshot técnico do host.</div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: Sensors & Power View -->
                <div id="tab-pane-sensors" class="space-y-4 hidden">
                    <div class="card p-4">
                        <h4 class="label !text-fg mb-3 flex items-center gap-2">
                            <i class="fas fa-thermometer-half"></i> Sensores térmicos, voltagens &amp; coolers
                        </h4>
                        <div id="sensors-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                            <div class="text-fg-dim text-center py-6 col-span-4 text-xs">Nenhum sensor reportado.</div>
                        </div>
                    </div>

                    <div class="card p-4">
                        <h4 class="label !text-fg mb-3 flex items-center gap-2">
                            <i class="fas fa-battery-half"></i> Baterias &amp; fontes AC
                        </h4>
                        <div id="batteries-grid" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="text-fg-dim text-center py-4 col-span-2 text-xs">Nenhuma bateria conectada.</div>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: Hardware & Firmware View -->
                <div id="tab-pane-hardware" class="space-y-4 hidden">
                    <div class="card p-4">
                        <h4 class="label !text-fg mb-4 flex items-center gap-2">
                            <i class="fas fa-shield-halved"></i> Detalhes de plataforma &amp; segurança
                        </h4>
                        <div id="hardware-meta-grid" class="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-xs">
                            <div class="panel p-3.5 space-y-2">
                                <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Sistema operacional</div>
                                <div class="flex justify-between"><span class="text-fg-dim">OS:</span><span id="hw-os" class="text-fg">--</span></div>
                                <div class="flex justify-between"><span class="text-fg-dim">Kernel:</span><span id="hw-kernel" class="text-rh-blue">--</span></div>
                                <div class="flex justify-between"><span class="text-fg-dim">Virtualização:</span><span id="hw-virt" class="text-rh-amber">--</span></div>
                            </div>
                            <div class="panel p-3.5 space-y-2">
                                <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Processador &amp; arquitetura</div>
                                <div class="flex justify-between"><span class="text-fg-dim">Modelo:</span><span id="hw-cpu-brand" class="text-fg truncate max-w-[220px]">--</span></div>
                                <div class="flex justify-between"><span class="text-fg-dim">Cores / Threads:</span><span id="hw-cpu-cores" class="text-rh-blue">--</span></div>
                                <div class="flex justify-between"><span class="text-fg-dim">Memória física:</span><span id="hw-mem-total" class="text-rh-green">--</span></div>
                            </div>
                        </div>
                        <div id="platform-extra" class="mt-4 space-y-4">
                            <div class="text-fg-dim text-center py-4 text-xs">Firmware, slots de memória e diagnóstico do kernel aparecem aqui quando o snapshot técnico chegar.</div>
                        </div>
                    </div>
                </div>

                <!-- TAB 6: Network View -->
                <div id="tab-pane-network" class="space-y-4 hidden">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="panel p-3"><span class="label block">Gateway</span><span id="net-gateway" class="text-sm font-mono text-fg mt-1 block">--</span></div>
                        <div class="panel p-3"><span class="label block">DNS</span><span id="net-dns" class="text-sm font-mono text-fg mt-1 block">--</span></div>
                        <div class="panel p-3"><span class="label block">TCP estabelecidas</span><span id="net-tcp-estab" class="text-sm font-mono text-fg mt-1 block">--</span></div>
                        <div class="panel p-3"><span class="label block">TCP em escuta</span><span id="net-tcp-listen" class="text-sm font-mono text-fg mt-1 block">--</span></div>
                    </div>

                    <div class="card p-4">
                        <h4 class="label !text-fg mb-3 flex items-center gap-2">
                            <i class="fas fa-network-wired"></i> Interfaces de rede
                        </h4>
                        <div id="net-interfaces-container" class="text-fg-dim text-center py-4 text-xs">Disponível a partir do snapshot técnico do host.</div>
                    </div>

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
                                    <tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

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
                                    <tr><td colspan="3" class="p-4 text-center text-fg-dim">Disponível a partir do snapshot técnico.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- TAB 7: Events History View -->
                <div id="tab-pane-events" class="card p-4 hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <h4 class="label !text-fg flex items-center gap-2">
                            <i class="fas fa-bell"></i> Histórico de eventos e anomalias do host
                        </h4>
                        <input type="text" id="filter-events-input" oninput="filterEvents(this.value)" placeholder="Filtrar por categoria, título ou detalhe..." class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-xs text-fg placeholder-fg-dim focus:outline-none focus:border-rh-red/70 w-64 transition-colors">
                    </div>
                    <div class="overflow-x-auto custom-scrollbar max-h-[560px] overflow-y-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-ink-900 text-fg-dim border-b border-ink-500 sticky top-0">
                                <tr>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Horário</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Severidade</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Categoria</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Evento</th>
                                    <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Detalhes</th>
                                </tr>
                            </thead>
                            <tbody id="events-table-body" class="divide-y divide-ink-500/60">
                                <tr><td colspan="5" class="p-4 text-center text-fg-dim">Carregando eventos...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Empty State View -->
                <div id="empty-state" class="card border-dashed p-16 text-center">
                    <div class="w-14 h-14 rounded-full bg-ink-600 border border-ink-400 flex items-center justify-center mx-auto mb-4 text-fg-mid text-xl">
                        <i class="fas fa-satellite-dish"></i>
                    </div>
                    <h3 class="font-disp text-lg font-bold text-fg mb-2">Nenhuma máquina selecionada</h3>
                    <p class="text-fg-mid text-xs max-w-md mx-auto mb-6">
                        Inicie o agente daemon em seus servidores para começar a receber telemetria contínua e alertas em tempo real.
                    </p>
                    <div class="inline-block bg-ink-900 px-4 py-2.5 rounded-md border border-ink-500 text-left font-mono text-xs text-fg-mid">
                        <span class="text-rh-red"># Iniciar TeuPC Daemon:</span><br>
                        ./target/release/teupc --daemon --config teupc_agent.json
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Snapshot JSON Viewer Modal -->
    <div id="snapshot-modal" class="fixed inset-0 bg-black/75 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-ink-700 border border-ink-400 rounded-lg max-w-5xl w-full max-h-[85vh] flex flex-col shadow-2xl overflow-hidden">
            <div class="p-4 bg-ink-800 border-b border-ink-500 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-code text-rh-red text-sm"></i>
                    <h3 class="font-semibold text-fg text-xs uppercase tracking-wider">Snapshot técnico JSON completo</h3>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="downloadSnapshotJson()" class="bg-ink-600 hover:bg-ink-500 border border-ink-400 text-fg-mid hover:text-fg text-xs px-3 py-1.5 rounded-md transition-colors flex items-center gap-1.5 font-medium">
                        <i class="fas fa-download"></i> Baixar .JSON
                    </button>
                    <button onclick="closeSnapshotModal()" class="text-fg-dim hover:text-fg p-1 text-lg">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="p-4 overflow-y-auto custom-scrollbar flex-1 bg-ink-900 font-mono text-xs text-rh-teal selection:bg-ink-600">
                <pre id="snapshot-json-content">Carregando snapshot...</pre>
            </div>
        </div>
    </div>

    <!-- JavaScript Logic -->
    <script>
        let selectedMachineId = null;
        let allMachines = [];
        let currentMachineDetails = null;
        let rawSnapshotObject = null;
        let refreshTimer = null;
        let currentIntervalMs = 2000;
        let currentTab = 'charts';
        let snapshotCache = {};   // machine_id -> snapshot tecnico (muda pouco; busca 1x por selecao)
        let machineEvents = [];

        // Todo dado vindo do banco (hostname, nome de processo, modelo de disco...) passa por aqui
        // antes de entrar em innerHTML: nome de processo é escolhido por qualquer usuário da máquina.
        function esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }

        // Charts references
        let chartCpu = null, chartRam = null, chartDisk = null, chartNet = null;

        function initCharts() {
            const GRID = '#262626';
            const TICK = '#8a8a8a';
            const FONT = { family: '"Red Hat Mono", monospace', size: 10 };
            const TOOLTIP = {
                backgroundColor: '#262626',
                borderColor: '#3f3f3f',
                borderWidth: 1,
                titleColor: '#f2f2f2',
                bodyColor: '#a1a1a1',
                titleFont: { family: '"Red Hat Mono", monospace', size: 11 },
                bodyFont: { family: '"Red Hat Mono", monospace', size: 11 },
                padding: 8,
                cornerRadius: 4,
            };

            const baseConfig = (label, color, fill = true) => ({
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: label,
                        data: [],
                        borderColor: color,
                        backgroundColor: fill ? color + '14' : 'transparent',
                        fill: fill,
                        tension: 0.3,
                        borderWidth: 1.5,
                        pointRadius: 0,
                        pointHoverRadius: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: TOOLTIP
                    },
                    scales: {
                        x: { display: false },
                        y: {
                            grid: { color: GRID },
                            border: { display: false },
                            ticks: { color: TICK, font: FONT }
                        }
                    }
                }
            });

            const ctxCpu = document.getElementById('chart-cpu').getContext('2d');
            chartCpu = new Chart(ctxCpu, baseConfig('CPU %', '#0088CE'));

            const ctxRam = document.getElementById('chart-ram').getContext('2d');
            chartRam = new Chart(ctxRam, baseConfig('RAM (GB)', '#00B8AF'));

            const ctxDisk = document.getElementById('chart-disk').getContext('2d');
            chartDisk = new Chart(ctxDisk, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        { label: 'Read KB/s', data: [], borderColor: '#4CA64C', backgroundColor: '#4CA64C14', fill: true, borderWidth: 1.5, tension: 0.3, pointRadius: 0 },
                        { label: 'Write KB/s', data: [], borderColor: '#F0AB00', backgroundColor: '#F0AB0014', fill: true, borderWidth: 1.5, tension: 0.3, pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: true, labels: { color: '#a1a1a1', boxWidth: 12, boxHeight: 2, font: FONT } },
                        tooltip: TOOLTIP
                    },
                    scales: {
                        x: { display: false },
                        y: { grid: { color: GRID }, border: { display: false }, ticks: { color: TICK, font: FONT } }
                    }
                }
            });

            const ctxNet = document.getElementById('chart-net').getContext('2d');
            chartNet = new Chart(ctxNet, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        { label: 'RX (Download) KB/s', data: [], borderColor: '#0088CE', backgroundColor: '#0088CE14', fill: true, borderWidth: 1.5, tension: 0.3, pointRadius: 0 },
                        { label: 'TX (Upload) KB/s', data: [], borderColor: '#EE0000', backgroundColor: '#EE000012', fill: true, borderWidth: 1.5, tension: 0.3, pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: true, labels: { color: '#a1a1a1', boxWidth: 12, boxHeight: 2, font: FONT } },
                        tooltip: TOOLTIP
                    },
                    scales: {
                        x: { display: false },
                        y: { grid: { color: GRID }, border: { display: false }, ticks: { color: TICK, font: FONT } }
                    }
                }
            });
        }

        async function fetchMachines() {
            try {
                const res = await fetch('api/machines.php');
                const data = await res.json();

                allMachines = data.machines || [];

                document.getElementById('server-time').innerText = data.server_time.split(' ')[1];
                document.getElementById('metric-total-machines').innerText = allMachines.length;

                const onlineCount = allMachines.filter(m => m.is_online).length;
                document.getElementById('metric-online-machines').innerText = onlineCount;
                document.getElementById('badge-online-count').innerText = `${onlineCount} / ${allMachines.length} online`;

                // Calculate global average CPU load
                const cpuLoads = allMachines.filter(m => m.telemetry).map(m => m.telemetry.cpu_usage);
                if (cpuLoads.length > 0) {
                    const avg = (cpuLoads.reduce((a, b) => a + b, 0) / cpuLoads.length).toFixed(1);
                    document.getElementById('metric-avg-cpu').innerText = `${avg}%`;
                }

                renderMachineList(allMachines);
                renderRecentEvents(data.recent_events);

                // Auto-select first machine if none selected
                if (!selectedMachineId && allMachines.length > 0) {
                    const firstOnline = allMachines.find(m => m.is_online) || allMachines[0];
                    selectMachine(firstOnline.machine_id);
                } else if (selectedMachineId) {
                    fetchMachineDetails(selectedMachineId);
                }

                document.getElementById('server-status').innerText = 'Conectado';
                document.getElementById('status-pulse-dot').className = 'w-2 h-2 rounded-full bg-rh-green pulse';
            } catch (err) {
                console.error("Erro ao buscar dados do servidor:", err);
                document.getElementById('server-status').innerText = 'Erro';
                document.getElementById('status-pulse-dot').className = 'w-2 h-2 rounded-full bg-rh-red';
            }
        }

        function filterMachines(query) {
            const q = query.toLowerCase().trim();
            if (!q) {
                renderMachineList(allMachines);
                return;
            }
            const filtered = allMachines.filter(m =>
                (m.hostname && m.hostname.toLowerCase().includes(q)) ||
                (m.cpu_brand && m.cpu_brand.toLowerCase().includes(q)) ||
                (m.ip_local && m.ip_local.includes(q)) ||
                (m.ip_public && m.ip_public.includes(q))
            );
            renderMachineList(filtered);
        }

        function renderMachineList(machines) {
            const container = document.getElementById('machines-container');
            if (machines.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8 text-fg-dim text-xs">
                        <i class="fas fa-satellite-dish text-lg block mb-2 text-fg-mid"></i>
                        Nenhuma máquina conectada.<br>Inicie o daemon: <code class="text-rh-red text-[11px]">./teupc --daemon</code>
                    </div>`;
                return;
            }

            container.innerHTML = machines.map(m => {
                const isSelected = m.machine_id === selectedMachineId;
                const statusDot = m.is_online ? 'bg-rh-green' : 'bg-ink-400';
                const statusBorder = m.is_online ? 'border-rh-green/40 text-rh-green' : 'border-ink-400 text-fg-dim';
                const statusText = m.is_online ? 'ONLINE' : 'OFFLINE';
                const activeClass = isSelected ? 'border-rh-red bg-[#262626] ring-1 ring-rh-red/60' : 'border-ink-500 bg-ink-900/70 hover:border-ink-400 hover:bg-ink-700';

                const cpu = m.telemetry ? `${m.telemetry.cpu_usage.toFixed(1)}%` : '--';
                const ram = m.telemetry ? `${(m.telemetry.mem_used / 1073741824).toFixed(1)} GB` : '--';
                const cpuVal = m.telemetry ? m.telemetry.cpu_usage : 0;

                return `
                    <div data-machine-id="${esc(m.machine_id)}" class="machine-card cursor-pointer border rounded-lg p-3 transition-colors ${activeClass}">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full ${statusDot}"></span>
                                <span class="font-semibold text-fg text-xs truncate max-w-[140px]">${esc(m.hostname)}</span>
                            </div>
                            <span class="text-[9px] px-1.5 py-0.5 rounded border ${statusBorder} font-mono font-medium">${statusText}</span>
                        </div>
                        <div class="text-[10px] text-fg-dim truncate mb-2 font-mono">${esc(m.cpu_brand || m.os)}</div>

                        <!-- CPU Usage mini bar -->
                        <div class="w-full bg-ink-600 h-1 rounded-full mb-2 overflow-hidden">
                            <div class="bg-rh-blue h-full rounded-full" style="width: ${Math.min(cpuVal, 100)}%"></div>
                        </div>

                        <div class="flex items-center justify-between text-[10px] text-fg-dim border-t border-ink-500/70 pt-1.5 font-mono">
                            <span>CPU: <strong class="text-fg font-medium">${cpu}</strong></span>
                            <span>RAM: <strong class="text-fg font-medium">${ram}</strong></span>
                            <span>IP: <strong class="text-fg-mid">${esc(m.ip_local || m.ip_public || '-')}</strong></span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderRecentEvents(events) {
            const container = document.getElementById('recent-events-container');
            if (!events || events.length === 0) {
                container.innerHTML = '<div class="text-fg-dim text-center py-4">Nenhuma anomalia registrada.</div>';
                return;
            }

            document.getElementById('metric-total-alerts').innerText = events.length;

            container.innerHTML = events.map(e => {
                let badgeClass = 'text-rh-blue border-rh-blue/40 bg-rh-blue/10';
                let icon = 'fa-info-circle';
                if (e.severity === 'Critical') {
                    badgeClass = 'text-rh-red border-rh-red/40 bg-rh-red/10';
                    icon = 'fa-triangle-exclamation';
                } else if (e.severity === 'Warning') {
                    badgeClass = 'text-rh-amber border-rh-amber/40 bg-rh-amber/10';
                    icon = 'fa-triangle-exclamation';
                }

                const time = e.created_at.split(' ')[1];
                return `
                    <div class="border border-ink-500 rounded-md p-2.5 bg-ink-900/60 hover:border-ink-400 transition-colors">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[9px] px-1.5 py-0.5 rounded border font-mono font-medium ${badgeClass}">
                                <i class="fas ${icon} mr-1"></i>${esc(e.category)}
                            </span>
                            <span class="text-[10px] text-fg-dim font-mono">${time}</span>
                        </div>
                        <div class="font-semibold text-fg text-[11px] truncate">${esc(e.title)}</div>
                        <div class="text-[10px] text-fg-dim truncate mt-0.5 font-mono">${esc(e.details)}</div>
                    </div>
                `;
            }).join('');
        }

        function selectMachine(machineId) {
            selectedMachineId = machineId;
            document.getElementById('empty-state').classList.add('hidden');
            document.getElementById('machine-hero').classList.remove('hidden');
            document.getElementById('tabs-container').classList.remove('hidden');

            // Switch to current active tab
            switchTab(currentTab);

            fetchMachineDetails(machineId);
            loadSnapshot(machineId);
            renderMachineList(allMachines);
        }

        async function loadSnapshot(machineId) {
            try {
                if (!snapshotCache[machineId]) {
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
                renderNetwork(snap);
                renderFilesystems(snap);
                renderPlatform(snap);
            } catch (err) {
                console.error("Erro ao carregar snapshot:", err);
            }
        }

        function switchTab(tabId) {
            currentTab = tabId;
            const tabs = ['charts', 'processes', 'disks', 'sensors', 'hardware', 'network', 'events'];

            tabs.forEach(t => {
                const btn = document.getElementById(`tab-btn-${t}`);
                const pane = document.getElementById(`tab-pane-${t}`);
                if (t === tabId) {
                    btn.className = 'tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-active flex items-center gap-2';
                    pane.classList.remove('hidden');
                } else {
                    btn.className = 'tab-btn px-4 py-2 rounded-none font-semibold text-xs transition-colors border-b-2 tab-idle flex items-center gap-2';
                    pane.classList.add('hidden');
                }
            });
        }

        async function fetchMachineDetails(machineId) {
            try {
                const res = await fetch(`api/machines.php?id=${encodeURIComponent(machineId)}`);
                const data = await res.json();

                const m = data.machine;
                currentMachineDetails = data.details;

                document.getElementById('hero-hostname').innerText = m.hostname;
                document.getElementById('hero-cpu-info').innerText = `${m.os} | CPU: ${m.cpu_brand} (${m.cpu_cores} Cores)`;
                document.getElementById('hero-virt-badge').innerText = m.virtualization || 'Bare Metal';
                document.getElementById('hero-kernel-badge').innerText = `Kernel ${m.kernel}`;
                document.getElementById('hero-ip-local').innerText = m.ip_local || '--';
                document.getElementById('hero-ip-public').innerText = m.ip_public || '--';
                document.getElementById('hero-last-seen').innerText = m.last_seen || '--';

                const badge = document.getElementById('hero-status-badge');
                if (m.is_online) {
                    badge.innerText = 'ONLINE';
                    badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-semibold bg-rh-green/15 text-rh-green border border-rh-green/40';
                } else {
                    badge.innerText = 'OFFLINE';
                    badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-semibold bg-ink-600 text-fg-dim border border-ink-400';
                }

                // Update Quick Gauges
                if (data.latest) {
                    const l = data.latest;
                    const cpu = l.cpu_usage;
                    const ramUsed = (l.mem_used / 1073741824).toFixed(1);
                    const ramTotal = (l.mem_total / 1073741824).toFixed(1);
                    const ramPct = l.mem_total > 0 ? ((l.mem_used / l.mem_total) * 100).toFixed(1) : 0;

                    document.getElementById('quick-cpu').innerText = `${cpu.toFixed(1)}%`;
                    document.getElementById('quick-cpu-bar').style.width = `${Math.min(cpu, 100)}%`;

                    document.getElementById('quick-ram').innerText = `${ramUsed} / ${ramTotal} GB`;
                    document.getElementById('quick-ram-bar').style.width = `${Math.min(ramPct, 100)}%`;

                    document.getElementById('quick-temp').innerText = l.cpu_temp ? `${l.cpu_temp.toFixed(0)} °C` : '-- °C';
                    if (l.cpu_temp) {
                        const tempPct = Math.min((l.cpu_temp / 100) * 100, 100);
                        document.getElementById('quick-temp-bar').style.width = `${tempPct}%`;
                        if (l.cpu_temp > 80) {
                            document.getElementById('quick-temp').className = 'text-xl lg:text-2xl font-disp font-bold text-rh-red mt-1 block font-mono';
                        }
                    }

                    document.getElementById('quick-rapl').innerText = l.rapl_watts ? `${l.rapl_watts.toFixed(1)} W` : '-- W';
                    document.getElementById('quick-load').innerText = l.load_1m ? l.load_1m.toFixed(2) : '0.00';
                }

                // Update Charts
                if (data.history && data.history.length > 0) {
                    const labels = data.history.map(h => h.created_at.split(' ')[1]);

                    chartCpu.data.labels = labels;
                    chartCpu.data.datasets[0].data = data.history.map(h => h.cpu_usage);
                    chartCpu.update('none');
                    document.getElementById('chart-cpu-val').innerText = `${data.history[data.history.length - 1].cpu_usage.toFixed(1)}%`;

                    chartRam.data.labels = labels;
                    chartRam.data.datasets[0].data = data.history.map(h => (h.mem_used / 1073741824).toFixed(2));
                    chartRam.update('none');
                    document.getElementById('chart-ram-val').innerText = `${(data.history[data.history.length - 1].mem_used / 1073741824).toFixed(2)} GB`;

                    chartDisk.data.labels = labels;
                    chartDisk.data.datasets[0].data = data.history.map(h => h.disk_read_kbs);
                    chartDisk.data.datasets[1].data = data.history.map(h => h.disk_write_kbs);
                    chartDisk.update('none');

                    chartNet.data.labels = labels;
                    chartNet.data.datasets[0].data = data.history.map(h => h.net_rx_kbs);
                    chartNet.data.datasets[1].data = data.history.map(h => h.net_tx_kbs);
                    chartNet.update('none');
                }

                // Update Processes Tab
                if (currentMachineDetails && currentMachineDetails.top_processes) {
                    renderProcessesTable(currentMachineDetails.top_processes);
                }

                // Update Disks Tab
                if (currentMachineDetails && currentMachineDetails.disks) {
                    renderDisksCards(currentMachineDetails.disks);
                }

                // Update Sensors Tab
                if (currentMachineDetails) {
                    renderSensors(currentMachineDetails.sensors || [], currentMachineDetails.batteries || []);
                }

                // Update Events Tab
                machineEvents = data.events || [];
                renderEvents(machineEvents);

                // Update Hardware Metadata Tab
                document.getElementById('hw-os').innerText = m.os || '--';
                document.getElementById('hw-kernel').innerText = m.kernel || '--';
                document.getElementById('hw-virt').innerText = m.virtualization || '--';
                document.getElementById('hw-cpu-brand').innerText = m.cpu_brand || '--';
                document.getElementById('hw-cpu-cores').innerText = `${m.cpu_cores} núcleos físicos / lógicos`;
                document.getElementById('hw-mem-total').innerText = `${(m.mem_total / 1073741824).toFixed(2)} GB`;

            } catch (err) {
                console.error("Erro ao carregar detalhes da máquina:", err);
            }
        }

        function filterProcesses(query) {
            if (!currentMachineDetails || !currentMachineDetails.top_processes) return;
            const q = query.toLowerCase().trim();
            if (!q) {
                renderProcessesTable(currentMachineDetails.top_processes);
                return;
            }
            const filtered = currentMachineDetails.top_processes.filter(p =>
                p.pid.toString().includes(q) ||
                p.name.toLowerCase().includes(q) ||
                p.user.toLowerCase().includes(q)
            );
            renderProcessesTable(filtered);
        }

        function renderProcessesTable(procs) {
            const tbody = document.getElementById('processes-table-body');
            if (!procs || procs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-fg-dim">Nenhum processo reportado.</td></tr>';
                return;
            }

            tbody.innerHTML = procs.map(p => {
                const memMb = (p.mem_bytes / (1024 * 1024)).toFixed(1);
                const cpuClass = p.cpu_usage > 50 ? 'text-rh-red font-semibold' : (p.cpu_usage > 10 ? 'text-rh-amber' : 'text-fg-mid');
                return `
                    <tr class="hover:bg-ink-800/70 transition-colors">
                        <td class="p-2.5 text-fg-dim">${p.pid}</td>
                        <td class="p-2.5 font-semibold text-fg">${esc(p.name)}</td>
                        <td class="p-2.5 text-fg-dim">${esc(p.user)}</td>
                        <td class="p-2.5 ${cpuClass}">${p.cpu_usage.toFixed(1)}%</td>
                        <td class="p-2.5 text-fg">${memMb} MB</td>
                        <td class="p-2.5 text-rh-green">${p.read_kb_s ? p.read_kb_s.toFixed(1) : '0.0'} KB/s</td>
                        <td class="p-2.5 text-rh-amber">${p.write_kb_s ? p.write_kb_s.toFixed(1) : '0.0'} KB/s</td>
                        <td class="p-2.5 text-fg-mid">${p.threads || 1}</td>
                    </tr>
                `;
            }).join('');
        }

        function renderDisksCards(disks) {
            const container = document.getElementById('disks-cards-container');
            if (!disks || disks.length === 0) {
                container.innerHTML = '<div class="text-fg-dim text-center py-6 col-span-2 text-xs">Nenhum disco detectado.</div>';
                return;
            }

            container.innerHTML = disks.map(d => `
                <div class="panel p-4 space-y-3 font-mono">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-hdd text-fg-mid text-base"></i>
                            <span class="font-semibold text-fg text-xs">${esc(d.name)}</span>
                        </div>
                        <span class="text-[10px] px-2 py-0.5 rounded bg-ink-600 text-fg-mid border border-ink-400">${esc(d.scheduler || 'none')}</span>
                    </div>
                    <div class="text-[11px] text-fg-dim truncate">${esc(d.model || 'Dispositivo de Bloco')}</div>
                    <div class="grid grid-cols-2 gap-2 text-xs border-t border-ink-500/70 pt-2">
                        <div><span class="text-fg-dim">Read:</span> <strong class="text-rh-green font-medium">${(d.read_kb_s || 0).toFixed(1)} KB/s</strong></div>
                        <div><span class="text-fg-dim">Write:</span> <strong class="text-rh-amber font-medium">${(d.write_kb_s || 0).toFixed(1)} KB/s</strong></div>
                        <div><span class="text-fg-dim">Total Read:</span> <strong class="text-fg-mid">${(d.total_read_gb || 0).toFixed(2)} GB</strong></div>
                        <div><span class="text-fg-dim">Total Write:</span> <strong class="text-fg-mid">${(d.total_write_gb || 0).toFixed(2)} GB</strong></div>
                    </div>
                </div>
            `).join('');
        }

        function renderSensors(sensors, batteries) {
            const sGrid = document.getElementById('sensors-grid');
            if (!sensors || sensors.length === 0) {
                sGrid.innerHTML = '<div class="text-fg-dim text-center py-6 col-span-4 text-xs">Nenhum sensor de hardware reportado.</div>';
            } else {
                sGrid.innerHTML = sensors.map(s => {
                    let icon = 'fa-temperature-half';
                    if (s.category === 'Voltage') { icon = 'fa-bolt'; }
                    else if (s.category === 'Fan') { icon = 'fa-fan'; }
                    else if (s.category === 'Power') { icon = 'fa-plug'; }

                    return `
                        <div class="panel p-3 text-center font-mono">
                            <i class="fas ${icon} text-fg-dim text-sm mb-1 block"></i>
                            <span class="text-[10px] text-fg-dim truncate block">${esc(s.label)}</span>
                            <span class="text-sm font-semibold text-fg mt-1 block">${esc(s.value.toFixed(1))} ${esc(s.unit)}</span>
                        </div>
                    `;
                }).join('');
            }

            const bGrid = document.getElementById('batteries-grid');
            if (!batteries || batteries.length === 0) {
                bGrid.innerHTML = '<div class="text-fg-dim text-center py-4 col-span-2 text-xs">Alimentação via tomada AC (Desktop / Servidor).</div>';
            } else {
                bGrid.innerHTML = batteries.map(b => `
                    <div class="panel p-3.5 space-y-2 font-mono text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-fg">${esc(b.name)} (${esc(b.model)})</span>
                            <span class="text-rh-green font-semibold">${b.capacity}% (${esc(b.status)})</span>
                        </div>
                        <div class="w-full bg-ink-600 h-1 rounded-full overflow-hidden">
                            <div class="bg-rh-green h-full rounded-full" style="width: ${b.capacity}%"></div>
                        </div>
                        <div class="flex justify-between text-[11px] text-fg-dim pt-1">
                            <span>Saúde: <strong class="text-rh-blue font-medium">${b.health_pct.toFixed(1)}%</strong></span>
                            <span>Ciclos: <strong class="text-rh-amber font-medium">${b.cycle_count}</strong></span>
                            <span>Consumo: <strong class="text-rh-red font-medium">${b.power_now.toFixed(1)} W</strong></span>
                        </div>
                    </div>
                `).join('');
            }
        }

        function fmtBytesGb(bytes) {
            return (bytes / 1073741824).toFixed(1) + ' GB';
        }

        function renderNetwork(snap) {
            const na = snap && snap.data ? snap.data.network_advanced : null;
            const nets = snap && snap.data ? snap.data.networks : null;

            document.getElementById('net-gateway').innerText = na && na.default_gateway ? na.default_gateway : '--';
            document.getElementById('net-dns').innerText = na && na.dns_servers && na.dns_servers.length ? na.dns_servers.join(', ') : '--';
            document.getElementById('net-tcp-estab').innerText = na && na.tcp_established != null ? na.tcp_established : '--';
            document.getElementById('net-tcp-listen').innerText = na && na.tcp_listen != null ? na.tcp_listen : '--';

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
                                ${nets.map(n => `
                                    <tr class="hover:bg-ink-800/70 transition-colors">
                                        <td class="p-2.5 font-semibold text-fg">${esc(n.name)}</td>
                                        <td class="p-2.5 text-fg-dim">${esc(n.iface_type || '-')}</td>
                                        <td class="p-2.5 text-rh-blue">${esc(n.ipv4 || '-')}</td>
                                        <td class="p-2.5 text-rh-green">${(n.rx_per_sec || 0).toFixed(1)} KB/s</td>
                                        <td class="p-2.5 text-rh-amber">${(n.tx_per_sec || 0).toFixed(1)} KB/s</td>
                                        <td class="p-2.5 text-fg-mid">${(n.rx_total || 0).toFixed(1)} MB</td>
                                        <td class="p-2.5 text-fg-mid">${(n.tx_total || 0).toFixed(1)} MB</td>
                                        <td class="p-2.5 ${((n.rx_errors||0)+(n.tx_errors||0)) > 0 ? 'text-rh-red' : 'text-fg-dim'}">${(n.rx_errors||0)+(n.tx_errors||0)} / ${(n.rx_dropped||0)+(n.tx_dropped||0)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>`;
            } else {
                ifContainer.innerHTML = '<div class="text-fg-dim text-center py-4 text-xs">Nenhuma interface reportada no snapshot.</div>';
            }

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
                portsBody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-fg-dim">Nenhuma porta em escuta reportada.</td></tr>';
            }

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
                arpBody.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-fg-dim">Nenhum vizinho ARP reportado.</td></tr>';
            }
        }

        function renderFilesystems(snap) {
            const container = document.getElementById('filesystems-container');
            const fss = snap && snap.data ? snap.data.filesystems : null;
            if (!fss || fss.length === 0) {
                container.innerHTML = '<div class="text-fg-dim text-center py-4 col-span-2 text-xs">Nenhum filesystem reportado no snapshot.</div>';
                return;
            }

            container.innerHTML = fss.map(f => {
                const total = f.total_bytes || 0;
                const avail = f.available_bytes || 0;
                const usedPct = total > 0 ? Math.min(((total - avail) / total) * 100, 100) : 0;
                const barColor = usedPct > 90 ? 'bg-rh-red' : (usedPct > 75 ? 'bg-rh-amber' : 'bg-rh-green');
                return `
                    <div class="panel p-3.5 space-y-2 font-mono text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-fg">${esc(f.mount_point)}</span>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-ink-600 text-fg-mid border border-ink-400">${esc(f.fs_type || '-')}</span>
                        </div>
                        <div class="text-[11px] text-fg-dim truncate">${esc(f.device)}</div>
                        <div class="w-full bg-ink-600 h-1 rounded-full overflow-hidden">
                            <div class="${barColor} h-full rounded-full" style="width: ${usedPct.toFixed(1)}%"></div>
                        </div>
                        <div class="flex justify-between text-[11px] text-fg-dim pt-0.5">
                            <span>Uso: <strong class="${usedPct > 90 ? 'text-rh-red' : 'text-fg'} font-medium">${usedPct.toFixed(1)}%</strong></span>
                            <span>Livre: <strong class="text-fg font-medium">${fmtBytesGb(avail)}</strong></span>
                            <span>Total: <strong class="text-fg-mid">${fmtBytesGb(total)}</strong></span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderPlatform(snap) {
            const container = document.getElementById('platform-extra');
            const s = snap && snap.data ? snap.data : null;
            if (!s) {
                container.innerHTML = '<div class="text-fg-dim text-center py-4 text-xs">Firmware, slots de memória e diagnóstico do kernel aparecem aqui quando o snapshot técnico chegar.</div>';
                return;
            }

            const fw = s.firmware || {};
            const kd = s.kernel_diagnostics || {};
            const pl = s.power_limits || {};
            const slots = (s.mem_advanced || {}).slots || [];
            const takenAt = snap.taken_at ? ` <span class="text-fg-dim font-mono font-normal normal-case">(snapshot de ${esc(snap.taken_at)})</span>` : '';

            const kv = (k, v, cls = 'text-fg') => `
                <div class="flex justify-between gap-3"><span class="text-fg-dim shrink-0">${esc(k)}:</span><span class="${cls} text-right truncate">${v == null || v === '' ? '--' : esc(v)}</span></div>`;

            container.innerHTML = `
                <h4 class="label !text-fg-mid flex items-center gap-2 mb-2"><i class="fas fa-microchip"></i> Firmware &amp; plataforma${takenAt}</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="panel p-3.5 space-y-2">
                        <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">BIOS / UEFI</div>
                        ${kv('Boot mode', fw.boot_mode)}
                        ${kv('Secure Boot', fw.secure_boot, /^(enabled|habilitado|ativo)/i.test(fw.secure_boot || '') && !/^des/i.test(fw.secure_boot || '') ? 'text-rh-green' : 'text-rh-amber')}
                        ${kv('TPM', fw.tpm_description, 'text-rh-blue')}
                        ${kv('Chassi', [fw.chassis_vendor, fw.chassis_type].filter(Boolean).join(' / '))}
                        ${kv('Serial', fw.product_serial)}
                    </div>
                    <div class="panel p-3.5 space-y-2">
                        <div class="label !text-fg-mid border-b border-ink-500 pb-1.5">Kernel &amp; energia</div>
                        ${kv('Kernel tainted', kd.is_tainted ? 'sim (' + (kd.tainted_flags || '') + ')' : 'não', kd.is_tainted ? 'text-rh-red' : 'text-rh-green')}
                        ${kv('Módulos carregados', kd.loaded_modules_count)}
                        ${kv('Governor', pl.current_governor)}
                        ${kv('PL1 / PL2', pl.pl1_w != null ? pl.pl1_w + ' W / ' + (pl.pl2_w != null ? pl.pl2_w + ' W' : '--') : '--')}
                        ${kv('Boot cmdline', kd.boot_cmdline, 'text-fg-mid')}
                    </div>
                </div>
                ${slots.length ? `
                <h4 class="label !text-fg-mid flex items-center gap-2 mt-4 mb-2"><i class="fas fa-memory"></i> Slots de memória (DMI)</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    ${slots.map(b => `
                        <div class="panel p-3 space-y-1 font-mono text-xs">
                            <span class="label block">${esc(b.locator || b.bank_locator || 'slot')}</span>
                            ${kv('Tipo', b.mem_type || b.memory_type)}
                            ${kv('Capacidade', b.capacity_gb ? b.capacity_gb + ' GB' : '--')}
                            ${kv('Velocidade', b.speed_mts ? b.speed_mts + ' MT/s' : '--')}
                            ${kv('Fabricante', b.manufacturer)}
                        </div>
                    `).join('')}
                </div>` : ''}
            `;
        }

        function renderEvents(events) {
            renderEventsList(events);
        }

        function filterEvents(query) {
            const q = query.toLowerCase().trim();
            if (!q) {
                renderEventsList(machineEvents);
                return;
            }
            renderEventsList(machineEvents.filter(e =>
                (e.category && e.category.toLowerCase().includes(q)) ||
                (e.title && e.title.toLowerCase().includes(q)) ||
                (e.details && e.details.toLowerCase().includes(q))
            ));
        }

        function renderEventsList(events) {
            const tbody = document.getElementById('events-table-body');
            if (!events || events.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-fg-dim">Nenhum evento registrado para este host.</td></tr>';
                return;
            }

            tbody.innerHTML = events.map(e => {
                let sevBadge = 'text-rh-blue border-rh-blue/40 bg-rh-blue/10';
                if (e.severity === 'Critical') sevBadge = 'text-rh-red border-rh-red/40 bg-rh-red/10';
                else if (e.severity === 'Warning') sevBadge = 'text-rh-amber border-rh-amber/40 bg-rh-amber/10';
                return `
                    <tr class="hover:bg-ink-800/70 transition-colors">
                        <td class="p-2.5 text-fg-dim">${esc(e.created_at || '--')}</td>
                        <td class="p-2.5"><span class="text-[9px] px-1.5 py-0.5 rounded border font-mono font-medium ${sevBadge}">${esc(e.severity || 'Info')}</span></td>
                        <td class="p-2.5 text-fg-mid">${esc(e.category || '-')}</td>
                        <td class="p-2.5 font-semibold text-fg">${esc(e.title)}</td>
                        <td class="p-2.5 text-fg-dim max-w-[420px] truncate">${esc(e.details || '-')}</td>
                    </tr>
                `;
            }).join('');
        }

        async function viewLatestSnapshot() {
            if (!selectedMachineId) return;
            document.getElementById('snapshot-modal').classList.remove('hidden');
            document.getElementById('snapshot-json-content').innerText = 'Buscando snapshot no servidor...';

            try {
                const res = await fetch(`api/snapshot.php?machine_id=${encodeURIComponent(selectedMachineId)}`);
                const data = await res.json();
                if (data.snapshots && data.snapshots.length > 0) {
                    rawSnapshotObject = JSON.parse(data.snapshots[0].snapshot_json);
                    document.getElementById('snapshot-json-content').innerText = JSON.stringify(rawSnapshotObject, null, 2);
                } else {
                    document.getElementById('snapshot-json-content').innerText = 'Nenhum snapshot armazenado para esta máquina ainda.';
                }
            } catch (err) {
                document.getElementById('snapshot-json-content').innerText = 'Erro ao carregar snapshot: ' + err.message;
            }
        }

        function downloadSnapshotJson() {
            if (!rawSnapshotObject) return;
            const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(rawSnapshotObject, null, 2));
            const downloadAnchor = document.createElement('a');
            downloadAnchor.setAttribute("href", dataStr);
            downloadAnchor.setAttribute("download", `teupc_snapshot_${selectedMachineId}_${new Date().toISOString().slice(0,10)}.json`);
            document.body.appendChild(downloadAnchor);
            downloadAnchor.click();
            downloadAnchor.remove();
        }

        function closeSnapshotModal() {
            document.getElementById('snapshot-modal').classList.add('hidden');
        }

        function changeInterval(val) {
            const ms = parseInt(val);
            currentIntervalMs = ms;
            if (refreshTimer) clearInterval(refreshTimer);
            if (ms > 0) {
                refreshTimer = setInterval(fetchMachines, ms);
            }
        }

        // Initialize on Page Load
        window.addEventListener('DOMContentLoaded', () => {
            // Handler delegado: o machine_id vem do banco e não pode virar código dentro de onclick
            document.getElementById('machines-container').addEventListener('click', (e) => {
                const card = e.target.closest('[data-machine-id]');
                if (card) selectMachine(card.dataset.machineId);
            });
            initCharts();
            fetchMachines();
            refreshTimer = setInterval(fetchMachines, currentIntervalMs);
        });
    </script>
</body>
</html>
