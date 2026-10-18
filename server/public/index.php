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
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ TeuPC Central | NOC Dashboard & Hardware Telemetry</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            dark: '#070a10',
                            card: '#0e1420',
                            cardHover: '#141d2e',
                            border: '#1b263b',
                            cyan: '#00f0ff',
                            green: '#00ff66',
                            yellow: '#ffb700',
                            red: '#ff0055',
                            purple: '#a855f7',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700;800&display=swap');
        body {
            font-family: 'JetBrains Mono', monospace;
            background-color: #06080d;
            color: #e2e8f0;
        }
        .glow-cyan {
            box-shadow: 0 0 15px rgba(0, 240, 255, 0.25);
        }
        .glow-green {
            box-shadow: 0 0 15px rgba(0, 255, 102, 0.25);
        }
        .glow-red {
            box-shadow: 0 0 15px rgba(255, 0, 85, 0.35);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #090d15;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #1e293b;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #334155;
        }
        .scanline {
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.03), rgba(0, 255, 0, 0.01), rgba(0, 0, 255, 0.03));
            background-size: 100% 2px, 3px 100%;
            pointer-events: none;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-cyan-500/30 selection:text-cyan-200">

    <!-- Top Navigation Header -->
    <header class="bg-brand-card/95 border-b border-brand-border/80 backdrop-blur sticky top-0 z-50 px-4 lg:px-8 py-3.5">
        <div class="max-w-[1700px] mx-auto flex flex-wrap items-center justify-between gap-4">
            
            <!-- Logo & Brand Title -->
            <div class="flex items-center gap-3.5">
                <div class="w-10 h-10 rounded-xl bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-400 font-black text-xl glow-cyan">
                    <i class="fas fa-bolt"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-extrabold tracking-wider text-white">
                            TeuPC <span class="text-cyan-400">CENTRAL</span>
                        </h1>
                        <span class="text-[10px] px-2 py-0.5 rounded bg-cyan-500/10 border border-cyan-500/30 text-cyan-300 font-bold tracking-widest uppercase">
                            NOC v2.0
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-400 tracking-tight">Centro de Comando & Telemetria em Tempo Real</p>
                </div>
            </div>

            <!-- Global Controls & Status -->
            <div class="flex flex-wrap items-center gap-4 text-xs">
                
                <!-- Refresh Interval Selector -->
                <div class="flex items-center bg-brand-dark border border-brand-border rounded-lg px-2.5 py-1.5 gap-2">
                    <i class="fas fa-arrows-rotate text-cyan-400" id="refresh-spinner"></i>
                    <span class="text-gray-400 text-[11px]">Intervalo:</span>
                    <select id="select-interval" onchange="changeInterval(this.value)" class="bg-transparent text-gray-200 font-semibold focus:outline-none cursor-pointer text-xs">
                        <option value="1000" class="bg-brand-card">1s (Rápido)</option>
                        <option value="2000" class="bg-brand-card" selected>2s (Normal)</option>
                        <option value="5000" class="bg-brand-card">5s (Econômico)</option>
                        <option value="0" class="bg-brand-card">Pausado</option>
                    </select>
                </div>

                <!-- Connection Pulse -->
                <div class="flex items-center gap-2 bg-brand-dark border border-brand-border rounded-lg px-3 py-1.5">
                    <span id="status-pulse-dot" class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span id="server-status" class="text-gray-200 font-medium text-[11px]">Conectado</span>
                </div>

                <!-- Server Time Clock -->
                <div class="hidden sm:flex items-center gap-1.5 bg-brand-dark border border-brand-border rounded-lg px-3 py-1.5 text-gray-300 font-mono text-[11px]">
                    <i class="far fa-clock text-cyan-400"></i>
                    <span id="server-time">--:--:--</span>
                </div>

                <a href="?logout=1" class="bg-brand-dark border border-brand-border rounded-lg px-3 py-1.5 text-gray-400 hover:text-red-400 font-mono text-[11px]">
                    <i class="fas fa-right-from-bracket mr-1"></i>Sair
                </a>

            </div>
        </div>
    </header>

    <!-- Main Workspace Container -->
    <main class="flex-1 max-w-[1700px] w-full mx-auto p-4 lg:p-6 space-y-5">

        <!-- Global Metric Stat Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <div class="bg-brand-card border border-brand-border rounded-xl p-4 flex items-center justify-between hover:border-cyan-500/40 transition">
                <div>
                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block mb-1">MÁQUINAS TOTAIS</span>
                    <span id="metric-total-machines" class="text-2xl lg:text-3xl font-extrabold text-white font-mono">0</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400 text-lg">
                    <i class="fas fa-server"></i>
                </div>
            </div>

            <div class="bg-brand-card border border-brand-border rounded-xl p-4 flex items-center justify-between hover:border-emerald-500/40 transition">
                <div>
                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block mb-1">HOSTS ONLINE</span>
                    <span id="metric-online-machines" class="text-2xl lg:text-3xl font-extrabold text-emerald-400 font-mono">0</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 text-lg">
                    <i class="fas fa-signal"></i>
                </div>
            </div>

            <div class="bg-brand-card border border-brand-border rounded-xl p-4 flex items-center justify-between hover:border-amber-500/40 transition">
                <div>
                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block mb-1">ANOMALIAS / ALERTAS</span>
                    <span id="metric-total-alerts" class="text-2xl lg:text-3xl font-extrabold text-amber-400 font-mono">0</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-lg">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
            </div>

            <div class="bg-brand-card border border-brand-border rounded-xl p-4 flex items-center justify-between hover:border-purple-500/40 transition">
                <div>
                    <span class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider block mb-1">CARGA MÉDIA CPU</span>
                    <span id="metric-avg-cpu" class="text-2xl lg:text-3xl font-extrabold text-cyan-400 font-mono">0.0%</span>
                </div>
                <div class="w-11 h-11 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 text-lg">
                    <i class="fas fa-microchip"></i>
                </div>
            </div>
        </div>

        <!-- Master / Detail Main Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

            <!-- LEFT SIDEBAR: Hosts & Live Feed (Col 4) -->
            <div class="lg:col-span-4 xl:col-span-3 space-y-4">
                
                <!-- Machines Fleet Box -->
                <div class="bg-brand-card border border-brand-border rounded-xl p-4 flex flex-col">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-network-wired text-cyan-400"></i> Frota de Hosts
                        </h2>
                        <span id="badge-online-count" class="text-[10px] bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 px-2 py-0.5 rounded-full font-bold">
                            0 hosts
                        </span>
                    </div>

                    <!-- Search Hosts Input -->
                    <div class="relative mb-3">
                        <i class="fas fa-search absolute left-3 top-2.5 text-gray-500 text-xs"></i>
                        <input type="text" id="filter-host-input" oninput="filterMachines(this.value)" placeholder="Filtrar por nome, IP, CPU..." class="w-full bg-brand-dark border border-brand-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-gray-200 placeholder-gray-500 focus:outline-none focus:border-cyan-500/60 transition">
                    </div>

                    <!-- Machine List Container -->
                    <div id="machines-container" class="space-y-2.5 max-h-[480px] overflow-y-auto custom-scrollbar pr-1">
                        <div class="text-center py-8 text-gray-500 text-xs">
                            <i class="fas fa-spinner fa-spin text-lg block mb-2 text-cyan-500"></i>
                            Aguardando daemons TeuPC...
                        </div>
                    </div>
                </div>

                <!-- Recent Anomaly Events Feed -->
                <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-bell text-amber-400"></i> Anomalias & Spikes
                        </h2>
                        <span class="text-[10px] text-gray-500">Tempo Real</span>
                    </div>
                    <div id="recent-events-container" class="space-y-2 max-h-[320px] overflow-y-auto custom-scrollbar text-xs">
                        <div class="text-gray-500 text-center py-4">Nenhuma anomalia registrada.</div>
                    </div>
                </div>

            </div>

            <!-- RIGHT MAIN PANEL: Machine Details & Deep Inspection (Col 8/9) -->
            <div class="lg:col-span-8 xl:col-span-9 space-y-4">

                <!-- Machine Hero Header -->
                <div id="machine-hero" class="bg-brand-card border border-brand-border rounded-xl p-5 hidden">
                    <div class="flex flex-wrap items-start justify-between gap-4 pb-4 border-b border-brand-border">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h3 id="hero-hostname" class="text-xl font-black text-white tracking-wide">Carregando...</h3>
                                <span id="hero-status-badge" class="text-[11px] px-2.5 py-0.5 rounded-full font-bold">ONLINE</span>
                                <span id="hero-virt-badge" class="text-[11px] px-2 py-0.5 rounded bg-gray-800/80 border border-gray-700 text-gray-300 font-mono">Bare Metal</span>
                                <span id="hero-kernel-badge" class="text-[11px] px-2 py-0.5 rounded bg-blue-950/40 border border-blue-800/40 text-blue-300 font-mono">Kernel</span>
                            </div>
                            <p id="hero-cpu-info" class="text-xs text-gray-400 font-mono">Processador: --</p>
                            <div class="flex flex-wrap items-center gap-4 text-[11px] text-gray-400 pt-1 font-mono">
                                <span><i class="fas fa-network-wired text-cyan-400 mr-1"></i> IP Local: <strong id="hero-ip-local" class="text-gray-200">--</strong></span>
                                <span><i class="fas fa-globe text-purple-400 mr-1"></i> IP Público: <strong id="hero-ip-public" class="text-gray-200">--</strong></span>
                                <span><i class="far fa-clock text-yellow-400 mr-1"></i> Último Contato: <strong id="hero-last-seen" class="text-gray-200">--</strong></span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2">
                            <button onclick="viewLatestSnapshot()" class="bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 text-xs px-3.5 py-2 rounded-lg font-semibold transition flex items-center gap-2 glow-cyan">
                                <i class="fas fa-file-code"></i> Snapshot JSON
                            </button>
                        </div>
                    </div>

                    <!-- Quick Vitals Gauges Row -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 mt-4">
                        
                        <!-- CPU Usage -->
                        <div class="bg-brand-dark/90 border border-brand-border rounded-xl p-3 text-center relative overflow-hidden">
                            <span class="text-[10px] text-gray-400 uppercase font-semibold block">USO DE CPU</span>
                            <span id="quick-cpu" class="text-xl lg:text-2xl font-black text-cyan-400 mt-1 block font-mono">0.0%</span>
                            <div class="w-full bg-gray-800 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div id="quick-cpu-bar" class="bg-cyan-400 h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- RAM Usage -->
                        <div class="bg-brand-dark/90 border border-brand-border rounded-xl p-3 text-center relative overflow-hidden">
                            <span class="text-[10px] text-gray-400 uppercase font-semibold block">MEMÓRIA RAM</span>
                            <span id="quick-ram" class="text-xl lg:text-2xl font-black text-yellow-400 mt-1 block font-mono">0 / 0 GB</span>
                            <div class="w-full bg-gray-800 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div id="quick-ram-bar" class="bg-yellow-400 h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- CPU Package Temperature -->
                        <div class="bg-brand-dark/90 border border-brand-border rounded-xl p-3 text-center relative overflow-hidden">
                            <span class="text-[10px] text-gray-400 uppercase font-semibold block">TEMP. PACOTE CPU</span>
                            <span id="quick-temp" class="text-xl lg:text-2xl font-black text-red-400 mt-1 block font-mono">-- °C</span>
                            <div class="w-full bg-gray-800 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div id="quick-temp-bar" class="bg-red-400 h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>

                        <!-- RAPL Watts -->
                        <div class="bg-brand-dark/90 border border-brand-border rounded-xl p-3 text-center relative overflow-hidden">
                            <span class="text-[10px] text-gray-400 uppercase font-semibold block">CONSUMO RAPL</span>
                            <span id="quick-rapl" class="text-xl lg:text-2xl font-black text-emerald-400 mt-1 block font-mono">-- W</span>
                            <span id="quick-rapl-sub" class="text-[10px] text-gray-500 block mt-1">Package Power</span>
                        </div>

                        <!-- Load 1m -->
                        <div class="bg-brand-dark/90 border border-brand-border rounded-xl p-3 text-center relative overflow-hidden">
                            <span class="text-[10px] text-gray-400 uppercase font-semibold block">LOAD AVERAGE</span>
                            <span id="quick-load" class="text-xl lg:text-2xl font-black text-purple-400 mt-1 block font-mono">0.00</span>
                            <span id="quick-cores-sub" class="text-[10px] text-gray-500 block mt-1">1m Load</span>
                        </div>

                    </div>
                </div>

                <!-- Navigation Tabs Row -->
                <div id="tabs-container" class="flex flex-wrap items-center gap-2 border-b border-brand-border pb-1 hidden">
                    <button onclick="switchTab('charts')" id="tab-btn-charts" class="tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-cyan-400 text-cyan-300 bg-brand-card flex items-center gap-2">
                        <i class="fas fa-chart-line"></i> Gráficos em Tempo Real
                    </button>
                    <button onclick="switchTab('processes')" id="tab-btn-processes" class="tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-transparent text-gray-400 hover:text-gray-200 hover:bg-brand-card/50 flex items-center gap-2">
                        <i class="fas fa-tasks"></i> Top Processos & I/O
                    </button>
                    <button onclick="switchTab('disks')" id="tab-btn-disks" class="tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-transparent text-gray-400 hover:text-gray-200 hover:bg-brand-card/50 flex items-center gap-2">
                        <i class="fas fa-hard-drive"></i> Armazenamento
                    </button>
                    <button onclick="switchTab('sensors')" id="tab-btn-sensors" class="tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-transparent text-gray-400 hover:text-gray-200 hover:bg-brand-card/50 flex items-center gap-2">
                        <i class="fas fa-thermometer-half"></i> Sensores & Baterias
                    </button>
                    <button onclick="switchTab('hardware')" id="tab-btn-hardware" class="tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-transparent text-gray-400 hover:text-gray-200 hover:bg-brand-card/50 flex items-center gap-2">
                        <i class="fas fa-shield-halved"></i> Hardware & Firmware
                    </button>
                </div>

                <!-- TAB 1: Real-time Charts View -->
                <div id="tab-pane-charts" class="grid grid-cols-1 md:grid-cols-2 gap-4 hidden">
                    
                    <!-- CPU Chart -->
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-bold text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-microchip text-cyan-400"></i> Histórico Carga de CPU (%)
                            </h4>
                            <span id="chart-cpu-val" class="text-xs font-mono font-bold text-cyan-400">0.0%</span>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-cpu"></canvas>
                        </div>
                    </div>

                    <!-- RAM Chart -->
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-bold text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-memory text-yellow-400"></i> Histórico Consumo de RAM (GB)
                            </h4>
                            <span id="chart-ram-val" class="text-xs font-mono font-bold text-yellow-400">0.0 GB</span>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-ram"></canvas>
                        </div>
                    </div>

                    <!-- Disk I/O Chart -->
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-bold text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-hard-drive text-emerald-400"></i> I/O de Disco (Read / Write KB/s)
                            </h4>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-disk"></canvas>
                        </div>
                    </div>

                    <!-- Network Traffic Chart -->
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-bold text-gray-200 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-network-wired text-blue-400"></i> Tráfego de Rede (RX / TX KB/s)
                            </h4>
                        </div>
                        <div class="h-56">
                            <canvas id="chart-net"></canvas>
                        </div>
                    </div>

                </div>

                <!-- TAB 2: Processes Table View -->
                <div id="tab-pane-processes" class="bg-brand-card border border-brand-border rounded-xl p-4 space-y-3 hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h4 class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fas fa-tasks text-cyan-400"></i> Top Processos Ativos no Host
                        </h4>
                        <input type="text" id="filter-proc-input" oninput="filterProcesses(this.value)" placeholder="Filtrar por PID, Nome ou Usuário..." class="bg-brand-dark border border-brand-border rounded-lg px-3 py-1.5 text-xs text-gray-200 placeholder-gray-500 focus:outline-none focus:border-cyan-500/60 w-64">
                    </div>

                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="bg-brand-dark text-gray-400 border-b border-brand-border">
                                <tr>
                                    <th class="p-2.5">PID</th>
                                    <th class="p-2.5">Nome</th>
                                    <th class="p-2.5">Usuário</th>
                                    <th class="p-2.5">CPU %</th>
                                    <th class="p-2.5">Memória</th>
                                    <th class="p-2.5">Disk Read</th>
                                    <th class="p-2.5">Disk Write</th>
                                    <th class="p-2.5">Threads</th>
                                </tr>
                            </thead>
                            <tbody id="processes-table-body" class="divide-y divide-brand-border/40">
                                <tr>
                                    <td colspan="8" class="p-4 text-center text-gray-500">Carregando processos...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 3: Disks & Storage View -->
                <div id="tab-pane-disks" class="space-y-4 hidden">
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <h4 class="text-xs font-bold text-white uppercase tracking-wider mb-4 flex items-center gap-2">
                            <i class="fas fa-hard-drive text-emerald-400"></i> Unidades de Disco & Agendadores I/O
                        </h4>
                        <div id="disks-cards-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="text-gray-500 text-center py-6 col-span-2">Nenhum disco reportado na telemetria recente.</div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: Sensors & Power View -->
                <div id="tab-pane-sensors" class="space-y-4 hidden">
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <h4 class="text-xs font-bold text-white uppercase tracking-wider mb-3 flex items-center gap-2">
                            <i class="fas fa-thermometer-half text-red-400"></i> Sensores Térmicos, Voltagens & Coolers
                        </h4>
                        <div id="sensors-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                            <div class="text-gray-500 text-center py-6 col-span-4">Nenhum sensor reportado.</div>
                        </div>
                    </div>

                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <h4 class="text-xs font-bold text-white uppercase tracking-wider mb-3 flex items-center gap-2">
                            <i class="fas fa-battery-half text-yellow-400"></i> Baterias & Fontes AC
                        </h4>
                        <div id="batteries-grid" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="text-gray-500 text-center py-4 col-span-2">Nenhuma bateria conectada.</div>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: Hardware & Firmware View -->
                <div id="tab-pane-hardware" class="space-y-4 hidden">
                    <div class="bg-brand-card border border-brand-border rounded-xl p-4">
                        <h4 class="text-xs font-bold text-white uppercase tracking-wider mb-4 flex items-center gap-2">
                            <i class="fas fa-shield-halved text-cyan-400"></i> Detalhes de Plataforma & Segurança
                        </h4>
                        <div id="hardware-meta-grid" class="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-xs">
                            <div class="bg-brand-dark p-3.5 rounded-lg border border-brand-border space-y-2">
                                <div class="text-gray-400 font-semibold border-b border-brand-border pb-1">SISTEMA OPERACIONAL</div>
                                <div class="flex justify-between"><span class="text-gray-400">OS:</span><span id="hw-os" class="text-white">--</span></div>
                                <div class="flex justify-between"><span class="text-gray-400">Kernel:</span><span id="hw-kernel" class="text-cyan-400">--</span></div>
                                <div class="flex justify-between"><span class="text-gray-400">Virtualização:</span><span id="hw-virt" class="text-yellow-400">--</span></div>
                            </div>
                            <div class="bg-brand-dark p-3.5 rounded-lg border border-brand-border space-y-2">
                                <div class="text-gray-400 font-semibold border-b border-brand-border pb-1">PROCESSADOR & ARQUITETURA</div>
                                <div class="flex justify-between"><span class="text-gray-400">Modelo:</span><span id="hw-cpu-brand" class="text-white truncate max-w-[220px]">--</span></div>
                                <div class="flex justify-between"><span class="text-gray-400">Cores / Threads:</span><span id="hw-cpu-cores" class="text-cyan-400">--</span></div>
                                <div class="flex justify-between"><span class="text-gray-400">Memória Física:</span><span id="hw-mem-total" class="text-emerald-400">--</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State View -->
                <div id="empty-state" class="bg-brand-card border border-dashed border-brand-border rounded-xl p-16 text-center">
                    <div class="w-16 h-16 rounded-full bg-brand-border/50 flex items-center justify-center mx-auto mb-4 text-cyan-400 text-2xl glow-cyan">
                        <i class="fas fa-satellite-dish"></i>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Nenhuma Máquina Selecionada</h3>
                    <p class="text-gray-400 text-xs max-w-md mx-auto mb-6">
                        Inicie o agente daemon em seus servidores para começar a receber telemetria contínua e alertas em tempo real.
                    </p>
                    <div class="inline-block bg-brand-dark px-4 py-2.5 rounded-lg border border-brand-border text-left font-mono text-xs text-gray-300">
                        <span class="text-cyan-400"># Iniciar TeuPC Daemon:</span><br>
                        ./target/release/teupc --daemon --config teupc_agent.json
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Snapshot JSON Viewer Modal -->
    <div id="snapshot-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-brand-card border border-brand-border rounded-2xl max-w-5xl w-full max-h-[85vh] flex flex-col shadow-2xl overflow-hidden">
            <div class="p-4 bg-brand-dark border-b border-brand-border flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-code text-cyan-400 text-sm"></i>
                    <h3 class="font-bold text-white text-xs uppercase tracking-wider">Snapshot Técnico JSON Completo</h3>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="downloadSnapshotJson()" class="bg-brand-border hover:bg-gray-700 text-gray-200 text-xs px-3 py-1.5 rounded-lg transition flex items-center gap-1.5 font-semibold">
                        <i class="fas fa-download text-cyan-400"></i> Baixar .JSON
                    </button>
                    <button onclick="closeSnapshotModal()" class="text-gray-400 hover:text-white p-1 text-lg">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="p-4 overflow-y-auto custom-scrollbar flex-1 bg-brand-dark/95 font-mono text-xs text-emerald-400 selection:bg-emerald-950">
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
            const baseConfig = (label, color, fill = true) => ({
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: label,
                        data: [],
                        borderColor: color,
                        backgroundColor: fill ? color + '18' : 'transparent',
                        fill: fill,
                        tension: 0.35,
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0e1420',
                            borderColor: '#1b263b',
                            borderWidth: 1,
                            titleColor: '#e2e8f0',
                            bodyColor: color,
                            titleFont: { family: 'JetBrains Mono', size: 11 },
                            bodyFont: { family: 'JetBrains Mono', size: 11 }
                        }
                    },
                    scales: {
                        x: { display: false },
                        y: {
                            grid: { color: '#161f30' },
                            ticks: { color: '#64748b', font: { family: 'JetBrains Mono', size: 10 } }
                        }
                    }
                }
            });

            const ctxCpu = document.getElementById('chart-cpu').getContext('2d');
            chartCpu = new Chart(ctxCpu, baseConfig('CPU %', '#00f0ff'));

            const ctxRam = document.getElementById('chart-ram').getContext('2d');
            chartRam = new Chart(ctxRam, baseConfig('RAM (GB)', '#ffb700'));

            const ctxDisk = document.getElementById('chart-disk').getContext('2d');
            chartDisk = new Chart(ctxDisk, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        { label: 'Read KB/s', data: [], borderColor: '#00ff66', backgroundColor: '#00ff6615', fill: true, borderWidth: 2, tension: 0.35, pointRadius: 0 },
                        { label: 'Write KB/s', data: [], borderColor: '#ffb700', backgroundColor: '#ffb70015', fill: true, borderWidth: 2, tension: 0.35, pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: true, labels: { color: '#94a3b8', font: { family: 'JetBrains Mono', size: 10 } } },
                        tooltip: { backgroundColor: '#0e1420', borderColor: '#1b263b', borderWidth: 1 }
                    },
                    scales: {
                        x: { display: false },
                        y: { grid: { color: '#161f30' }, ticks: { color: '#64748b', font: { family: 'JetBrains Mono', size: 10 } } }
                    }
                }
            });

            const ctxNet = document.getElementById('chart-net').getContext('2d');
            chartNet = new Chart(ctxNet, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        { label: 'RX (Download) KB/s', data: [], borderColor: '#3b82f6', backgroundColor: '#3b82f615', fill: true, borderWidth: 2, tension: 0.35, pointRadius: 0 },
                        { label: 'TX (Upload) KB/s', data: [], borderColor: '#a855f7', backgroundColor: '#a855f715', fill: true, borderWidth: 2, tension: 0.35, pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: true, labels: { color: '#94a3b8', font: { family: 'JetBrains Mono', size: 10 } } },
                        tooltip: { backgroundColor: '#0e1420', borderColor: '#1b263b', borderWidth: 1 }
                    },
                    scales: {
                        x: { display: false },
                        y: { grid: { color: '#161f30' }, ticks: { color: '#64748b', font: { family: 'JetBrains Mono', size: 10 } } }
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
                document.getElementById('status-pulse-dot').className = 'w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse';
            } catch (err) {
                console.error("Erro ao buscar dados do servidor:", err);
                document.getElementById('server-status').innerText = 'Erro';
                document.getElementById('status-pulse-dot').className = 'w-2.5 h-2.5 rounded-full bg-red-500';
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
                    <div class="text-center py-8 text-gray-500 text-xs">
                        <i class="fas fa-satellite-dish text-lg block mb-2 text-cyan-500"></i>
                        Nenhuma máquina conectada.<br>Inicie o daemon: <code class="text-cyan-400 text-[11px]">./teupc --daemon</code>
                    </div>`;
                return;
            }

            container.innerHTML = machines.map(m => {
                const isSelected = m.machine_id === selectedMachineId;
                const statusColor = m.is_online ? 'bg-emerald-400 shadow-[0_0_8px_rgba(0,255,102,0.6)]' : 'bg-gray-600';
                const statusBorder = m.is_online ? 'border-emerald-500/30 text-emerald-300' : 'border-gray-700 text-gray-400';
                const statusText = m.is_online ? 'ONLINE' : 'OFFLINE';
                const activeClass = isSelected ? 'border-cyan-500 bg-cyan-950/20 glow-cyan ring-1 ring-cyan-500/50' : 'border-brand-border bg-brand-dark/70 hover:border-gray-700 hover:bg-brand-card';

                const cpu = m.telemetry ? `${m.telemetry.cpu_usage.toFixed(1)}%` : '--';
                const ram = m.telemetry ? `${(m.telemetry.mem_used / 1073741824).toFixed(1)} GB` : '--';
                const cpuVal = m.telemetry ? m.telemetry.cpu_usage : 0;

                return `
                    <div data-machine-id="${esc(m.machine_id)}" class="machine-card cursor-pointer border rounded-xl p-3 transition ${activeClass}">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full ${statusColor}"></span>
                                <span class="font-bold text-white text-xs truncate max-w-[140px]">${esc(m.hostname)}</span>
                            </div>
                            <span class="text-[9px] px-1.5 py-0.5 rounded border ${statusBorder} font-bold">${statusText}</span>
                        </div>
                        <div class="text-[10px] text-gray-400 truncate mb-2 font-mono">${esc(m.cpu_brand || m.os)}</div>
                        
                        <!-- CPU Usage mini bar -->
                        <div class="w-full bg-gray-800/80 h-1 rounded-full mb-2 overflow-hidden">
                            <div class="bg-cyan-400 h-full rounded-full" style="width: ${Math.min(cpuVal, 100)}%"></div>
                        </div>

                        <div class="flex items-center justify-between text-[10px] text-gray-400 border-t border-brand-border/60 pt-1.5 font-mono">
                            <span>CPU: <strong class="text-cyan-400">${cpu}</strong></span>
                            <span>RAM: <strong class="text-yellow-400">${ram}</strong></span>
                            <span>IP: <strong class="text-gray-300">${esc(m.ip_local || m.ip_public || '-')}</strong></span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderRecentEvents(events) {
            const container = document.getElementById('recent-events-container');
            if (!events || events.length === 0) {
                container.innerHTML = '<div class="text-gray-500 text-center py-4">Nenhuma anomalia registrada.</div>';
                return;
            }

            document.getElementById('metric-total-alerts').innerText = events.length;

            container.innerHTML = events.map(e => {
                let badgeClass = 'text-blue-400 bg-blue-500/10 border-blue-500/20';
                let icon = 'fa-info-circle';
                if (e.severity === 'Critical') {
                    badgeClass = 'text-red-400 bg-red-500/10 border-red-500/20';
                    icon = 'fa-triangle-exclamation';
                } else if (e.severity === 'Warning') {
                    badgeClass = 'text-amber-400 bg-amber-500/10 border-amber-500/20';
                    icon = 'fa-triangle-exclamation';
                }

                const time = e.created_at.split(' ')[1];
                return `
                    <div class="border border-brand-border rounded-lg p-2.5 bg-brand-dark/60 hover:border-gray-700 transition">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[9px] px-1.5 py-0.5 rounded border font-bold ${badgeClass}">
                                <i class="fas ${icon} mr-1"></i>${esc(e.category)}
                            </span>
                            <span class="text-[10px] text-gray-500 font-mono">${time}</span>
                        </div>
                        <div class="font-bold text-gray-200 text-[11px] truncate">${esc(e.title)}</div>
                        <div class="text-[10px] text-gray-400 truncate mt-0.5 font-mono">${esc(e.details)}</div>
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
            renderMachineList(allMachines);
        }

        function switchTab(tabId) {
            currentTab = tabId;
            const tabs = ['charts', 'processes', 'disks', 'sensors', 'hardware'];
            
            tabs.forEach(t => {
                const btn = document.getElementById(`tab-btn-${t}`);
                const pane = document.getElementById(`tab-pane-${t}`);
                if (t === tabId) {
                    btn.className = 'tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-cyan-400 text-cyan-300 bg-brand-card flex items-center gap-2';
                    pane.classList.remove('hidden');
                } else {
                    btn.className = 'tab-btn px-4 py-2 rounded-t-lg font-bold text-xs transition border-b-2 border-transparent text-gray-400 hover:text-gray-200 hover:bg-brand-card/50 flex items-center gap-2';
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
                    badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30';
                } else {
                    badge.innerText = 'OFFLINE';
                    badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-bold bg-gray-700 text-gray-400 border border-gray-600';
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
                            document.getElementById('quick-temp').className = 'text-xl lg:text-2xl font-black text-red-500 mt-1 block font-mono';
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
                tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">Nenhum processo reportado.</td></tr>';
                return;
            }

            tbody.innerHTML = procs.map(p => {
                const memMb = (p.mem_bytes / (1024 * 1024)).toFixed(1);
                const cpuClass = p.cpu_usage > 50 ? 'text-red-400 font-bold' : (p.cpu_usage > 10 ? 'text-yellow-400' : 'text-gray-300');
                return `
                    <tr class="hover:bg-brand-dark/70 transition">
                        <td class="p-2.5 text-gray-400">${p.pid}</td>
                        <td class="p-2.5 font-bold text-white">${esc(p.name)}</td>
                        <td class="p-2.5 text-gray-400">${esc(p.user)}</td>
                        <td class="p-2.5 ${cpuClass}">${p.cpu_usage.toFixed(1)}%</td>
                        <td class="p-2.5 text-yellow-300">${memMb} MB</td>
                        <td class="p-2.5 text-emerald-400">${p.read_kb_s ? p.read_kb_s.toFixed(1) : '0.0'} KB/s</td>
                        <td class="p-2.5 text-amber-400">${p.write_kb_s ? p.write_kb_s.toFixed(1) : '0.0'} KB/s</td>
                        <td class="p-2.5 text-purple-300">${p.threads || 1}</td>
                    </tr>
                `;
            }).join('');
        }

        function renderDisksCards(disks) {
            const container = document.getElementById('disks-cards-container');
            if (!disks || disks.length === 0) {
                container.innerHTML = '<div class="text-gray-500 text-center py-6 col-span-2">Nenhum disco detectado.</div>';
                return;
            }

            container.innerHTML = disks.map(d => `
                <div class="bg-brand-dark p-4 rounded-xl border border-brand-border space-y-3 font-mono">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-hdd text-emerald-400 text-base"></i>
                            <span class="font-bold text-white text-xs">${esc(d.name)}</span>
                        </div>
                        <span class="text-[10px] px-2 py-0.5 rounded bg-gray-800 text-gray-300 border border-gray-700">${esc(d.scheduler || 'none')}</span>
                    </div>
                    <div class="text-[11px] text-gray-400 truncate">${esc(d.model || 'Dispositivo de Bloco')}</div>
                    <div class="grid grid-cols-2 gap-2 text-xs border-t border-brand-border/60 pt-2">
                        <div><span class="text-gray-500">Read:</span> <strong class="text-emerald-400">${(d.read_kb_s || 0).toFixed(1)} KB/s</strong></div>
                        <div><span class="text-gray-500">Write:</span> <strong class="text-amber-400">${(d.write_kb_s || 0).toFixed(1)} KB/s</strong></div>
                        <div><span class="text-gray-500">Total Read:</span> <strong class="text-gray-300">${(d.total_read_gb || 0).toFixed(2)} GB</strong></div>
                        <div><span class="text-gray-500">Total Write:</span> <strong class="text-gray-300">${(d.total_write_gb || 0).toFixed(2)} GB</strong></div>
                    </div>
                </div>
            `).join('');
        }

        function renderSensors(sensors, batteries) {
            const sGrid = document.getElementById('sensors-grid');
            if (!sensors || sensors.length === 0) {
                sGrid.innerHTML = '<div class="text-gray-500 text-center py-6 col-span-4">Nenhum sensor de hardware reportado.</div>';
            } else {
                sGrid.innerHTML = sensors.map(s => {
                    let icon = 'fa-temperature-half text-red-400';
                    let valColor = 'text-red-300';
                    if (s.category === 'Voltage') { icon = 'fa-bolt text-yellow-400'; valColor = 'text-yellow-300'; }
                    else if (s.category === 'Fan') { icon = 'fa-fan text-cyan-400'; valColor = 'text-cyan-300'; }
                    else if (s.category === 'Power') { icon = 'fa-plug text-emerald-400'; valColor = 'text-emerald-300'; }

                    return `
                        <div class="bg-brand-dark p-3 rounded-lg border border-brand-border text-center font-mono">
                            <i class="fas ${icon} text-sm mb-1 block"></i>
                            <span class="text-[10px] text-gray-400 truncate block">${esc(s.label)}</span>
                            <span class="text-sm font-bold ${valColor} mt-1 block">${esc(s.value.toFixed(1))} ${esc(s.unit)}</span>
                        </div>
                    `;
                }).join('');
            }

            const bGrid = document.getElementById('batteries-grid');
            if (!batteries || batteries.length === 0) {
                bGrid.innerHTML = '<div class="text-gray-500 text-center py-4 col-span-2">Alimentação via tomada AC (Desktop / Servidor).</div>';
            } else {
                bGrid.innerHTML = batteries.map(b => `
                    <div class="bg-brand-dark p-3.5 rounded-xl border border-brand-border space-y-2 font-mono text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-white">${esc(b.name)} (${esc(b.model)})</span>
                            <span class="text-emerald-400 font-bold">${b.capacity}% (${esc(b.status)})</span>
                        </div>
                        <div class="w-full bg-gray-800 h-1.5 rounded-full overflow-hidden">
                            <div class="bg-emerald-400 h-full rounded-full" style="width: ${b.capacity}%"></div>
                        </div>
                        <div class="flex justify-between text-[11px] text-gray-400 pt-1">
                            <span>Saúde: <strong class="text-cyan-300">${b.health_pct.toFixed(1)}%</strong></span>
                            <span>Ciclos: <strong class="text-yellow-300">${b.cycle_count}</strong></span>
                            <span>Consumo: <strong class="text-red-300">${b.power_now.toFixed(1)} W</strong></span>
                        </div>
                    </div>
                `).join('');
            }
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
