<?php
// Shell da aplicacao (SPA via HTMX). Todas as paginas vivem dentro de #app;
// a navegacao troca o conteudo sem recarregar a pagina.

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Settings.php';

use TeuPC\Central\Auth;
use TeuPC\Central\Settings;

// Logout em qualquer pagina (antes do requireDashboard).
if (isset($_GET['logout'])) {
    Auth::startSession();
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

function chrome_shell(string $view): void
{
    Auth::requireDashboard();
    $cfg = Settings::all();

    $PAGES = [
        'overview' => ['label' => 'Visão geral', 'icon' => 'fa-gauge-high'],
        'events'   => ['label' => 'Eventos', 'icon' => 'fa-bell'],
        'network'  => ['label' => 'Rede', 'icon' => 'fa-network-wired'],
        'settings' => ['label' => 'Configurações', 'icon' => 'fa-sliders'],
    ];
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeuPC Central | NOC &amp; Hardware Telemetry</title>
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
    <script src="https://unpkg.com/htmx.org@2.0.4"></script>
    <script src="https://unpkg.com/alpinejs@3.14.9" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800&family=Red+Hat+Text:wght@400;500;600;700&family=Red+Hat+Mono:wght@400;500;600&display=swap');
        body { font-family: 'Red Hat Text', system-ui, sans-serif; background: #0f0f0f; color: #f2f2f2; }
        ::selection { background: rgba(238,0,0,.25); color: #fff; }
        *:focus-visible { outline: 2px solid #EE0000; outline-offset: 2px; border-radius: 2px; }

        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #3f3f3f; border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #5c5c5c; }
        .card { background: #1f1f1f; border: 1px solid #2f2f2f; border-radius: 8px; }
        .panel { background: #151515; border: 1px solid #2f2f2f; border-radius: 6px; }
        .label { font-size: 10px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: #8a8a8a; }
        .pulse { animation: pulse 2.4s cubic-bezier(.4,0,.6,1) infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .45; } }

        /* Transicao de view (SPA) */
        #app > * { animation: view-in .18s ease-out; }
        @keyframes view-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
        htmx-indicator { display: none; }
        .htmx-request htmx-indicator, htmx-indicator.htmx-request { display: block; }

        /* Barra de progresso global (topo, estilo YouTube) */
        #page-progress {
            position: fixed; top: 0; left: 0; height: 2px; width: 0; z-index: 100;
            background: #EE0000; transition: width .25s ease, opacity .3s ease .25s;
            opacity: 0; pointer-events: none;
        }
        #page-progress.loading { width: 85%; opacity: 1; transition: width 2s ease; }

        /* Skeleton loading */
        .skeleton {
            background: linear-gradient(90deg, #1f1f1f 25%, #262626 50%, #1f1f1f 75%);
            background-size: 200% 100%;
            animation: shimmer 1.4s ease infinite;
            border-radius: 6px;
        }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* Nav lateral */
        .nav-active { background: #262626; color: #f2f2f2; border-left-color: #EE0000; }

        /* Toasts */
        .toast { animation: toast-in .2s ease-out; }
        @keyframes toast-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

        /* Tabela */
        .data-table th { position: sticky; top: 0; background: #151515; z-index: 1; }
        .data-table tbody tr { transition: background .12s ease; }
        .data-table tbody tr:hover { background: #1f1f1f; }
    </style>
    <script>
        window.TEUPC = {
            interval_ms: <?= (int) $cfg['interval_ms'] ?>,
            thresholds: { cpu: <?= (int) $cfg['cpu'] ?>, ram_gb: <?= (int) $cfg['ram_gb'] ?> },
        };
        // Toast helper global
        window.teupcToast = function (message, type = 'ok') {
            const colors = { ok: 'border-rh-green/50 text-rh-green', error: 'border-rh-red/50 text-rh-red', info: 'border-ink-400 text-fg-mid' };
            const el = document.createElement('div');
            el.className = 'toast ' + (colors[type] || colors.info) + ' bg-ink-700 border rounded-md px-4 py-2.5 text-xs font-medium shadow-xl max-w-xs';
            el.textContent = message;
            document.getElementById('toasts').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 350); }, 3500);
        };
        document.addEventListener('htmx:configRequest', () => document.getElementById('page-progress').classList.add('loading'));
        document.addEventListener('htmx:afterRequest', (e) => {
            if (e.detail.successful) document.getElementById('page-progress').classList.remove('loading');
        });
        document.addEventListener('htmx:responseError', () => {
            document.getElementById('page-progress').classList.remove('loading');
            teupcToast('Erro ao carregar dados do servidor.', 'error');
        });
        document.addEventListener('htmx:pushedIntoHistory', updateActiveNav);
        window.addEventListener('popstate', () => setTimeout(updateActiveNav, 50));
        function updateActiveNav() {
            const current = (new URLSearchParams(location.search).get('view')) || 'overview';
            document.querySelectorAll('[data-nav]').forEach(a => {
                const on = a.dataset.nav === current;
                a.classList.toggle('nav-active', on);
                a.classList.toggle('border-l-transparent', !on);
                a.querySelector('i').classList.toggle('text-rh-red', on);
                a.querySelector('i').classList.toggle('text-fg-dim', !on);
            });
        }
    </script>
</head>
<body class="min-h-screen antialiased" x-data>

    <div id="page-progress"></div>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="hidden md:flex flex-col w-60 shrink-0 bg-ink-800 border-r border-ink-500 sticky top-0 h-screen">
            <div class="flex items-center gap-3 px-5 py-4 border-b border-ink-500">
                <div class="w-9 h-9 rounded-md bg-rh-red flex items-center justify-center font-disp font-extrabold text-white text-base">T</div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="font-disp text-[15px] font-bold tracking-tight text-fg leading-none">TeuPC <span class="text-fg-dim font-medium">Central</span></h1>
                        <span class="text-[9px] px-1.5 py-px rounded bg-ink-600 border border-ink-400 text-fg-mid font-mono tracking-widest uppercase">NOC v2.0</span>
                    </div>
                    <p class="text-[11px] text-fg-dim leading-tight mt-0.5">Centro de comando &amp; telemetria</p>
                </div>
            </div>

            <nav class="flex-1 px-3 py-4 space-y-1">
                <div class="label px-3 pb-1.5">Monitoramento</div>
                <?php foreach (array_slice($PAGES, 0, 3) as $key => $p): ?>
                <a href="/?view=<?= $key ?>" data-nav="<?= $key ?>"
                   hx-get="/?view=<?= $key ?>" hx-target="#app" hx-swap="innerHTML transition:true" hx-push-url="true"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-md text-[13px] font-medium border-l-2 transition-colors cursor-pointer
                          <?= $key === $view ? 'nav-active' : 'border-l-transparent text-fg-mid hover:text-fg hover:bg-ink-700' ?>">
                    <i class="fas <?= $p['icon'] ?> w-4 text-center <?= $key === $view ? 'text-rh-red' : 'text-fg-dim' ?>"></i>
                    <?= $p['label'] ?>
                </a>
                <?php endforeach; ?>
                <div class="label px-3 pt-4 pb-1.5">Sistema</div>
                <?php foreach (array_slice($PAGES, 3) as $key => $p): ?>
                <a href="/?view=<?= $key ?>" data-nav="<?= $key ?>"
                   hx-get="/?view=<?= $key ?>" hx-target="#app" hx-swap="innerHTML transition:true" hx-push-url="true"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-md text-[13px] font-medium border-l-2 transition-colors cursor-pointer
                          <?= $key === $view ? 'nav-active' : 'border-l-transparent text-fg-mid hover:text-fg hover:bg-ink-700' ?>">
                    <i class="fas <?= $p['icon'] ?> w-4 text-center <?= $key === $view ? 'text-rh-red' : 'text-fg-dim' ?>"></i>
                    <?= $p['label'] ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <div class="px-5 py-4 border-t border-ink-500 text-[10px] text-fg-dim font-mono space-y-1">
                <div><i class="fas fa-circle text-[6px] align-middle text-rh-green mr-1"></i>daemon ativo</div>
                <div>TeuPC Central &middot; NOC</div>
            </div>
        </aside>

        <!-- Coluna principal -->
        <div class="flex-1 min-w-0 flex flex-col">
            <!-- Header -->
            <header class="bg-ink-800 border-b border-ink-500 sticky top-0 z-50 px-4 lg:px-8 py-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3 md:hidden">
                        <div class="w-8 h-8 rounded-md bg-rh-red flex items-center justify-center font-disp font-extrabold text-white text-sm">T</div>
                        <h1 class="font-disp text-sm font-bold text-fg">TeuPC Central</h1>
                    </div>
                    <nav class="flex md:hidden items-center gap-1 text-[11px] font-medium">
                        <?php foreach ($PAGES as $key => $p): ?>
                        <a href="/?view=<?= $key ?>" hx-get="/?view=<?= $key ?>" hx-target="#app" hx-push-url="true"
                           class="px-2 py-1 rounded <?= $key === $view ? 'bg-ink-600 text-fg' : 'text-fg-mid' ?>"><?= $p['label'] ?></a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="flex flex-wrap items-center gap-3 text-xs ml-auto">
                        <div class="hidden sm:flex items-center gap-2 bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5">
                            <span id="status-pulse-dot" class="w-2 h-2 rounded-full bg-rh-green pulse"></span>
                            <span id="server-status" class="text-fg font-medium text-[11px]">Conectado</span>
                        </div>
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

            <main id="app" class="flex-1 max-w-[1700px] w-full mx-auto p-4 lg:p-6 space-y-5">
<?php
}

function chrome_end(): void
{
    ?>
            </main>
        </div>
    </div>

    <!-- Toasts -->
    <div id="toasts" class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2"></div>
</body>
</html>
<?php
}
