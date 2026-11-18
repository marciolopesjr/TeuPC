<?php
// Chrome compartilhado do TeuPC Central: head, sidebar e header para todas as paginas.
// Uso:
//   require_once __DIR__ . '/inc/chrome.php';
//   chrome_start(['title' => 'Eventos', 'active' => 'events', 'chartjs' => false]);
//   ... conteudo da pagina ...
//   chrome_end();

require_once __DIR__ . '/../src/Auth.php';

use TeuPC\Central\Auth;

function chrome_start(array $opts = []): void
{
    Auth::requireDashboard();

    $title = $opts['title'] ?? 'NOC';
    $active = $opts['active'] ?? 'overview';
    $chartjs = !empty($opts['chartjs']);

    $PAGES = [
        'overview' => ['label' => 'Visão geral', 'icon' => 'fa-gauge-high', 'href' => 'index.php'],
        'events'   => ['label' => 'Eventos', 'icon' => 'fa-bell', 'href' => 'events.php'],
        'network'  => ['label' => 'Rede', 'icon' => 'fa-network-wired', 'href' => 'network.php'],
        'settings' => ['label' => 'Configurações', 'icon' => 'fa-sliders', 'href' => 'settings.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeuPC Central | <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
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
    <?php if ($chartjs): ?><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><?php endif; ?>
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
        .nav-active { background: #262626; color: #f2f2f2; border-left-color: #EE0000; }
    </style>
</head>
<body class="min-h-screen antialiased">

    <!-- Layout: sidebar fixa + coluna principal -->
    <div class="flex min-h-screen">

        <!-- Sidebar (desktop) -->
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
                <?php foreach ($PAGES as $key => $p): ?>
                <a href="<?= $p['href'] ?>"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-md text-[13px] font-medium border-l-2 transition-colors
                          <?= $key === $active ? 'nav-active' : 'border-l-transparent text-fg-mid hover:text-fg hover:bg-ink-700' ?>">
                    <i class="fas <?= $p['icon'] ?> w-4 text-center <?= $key === $active ? 'text-rh-red' : 'text-fg-dim' ?>"></i>
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

            <!-- Header (mobile brand + controles globais) -->
            <header class="bg-ink-800 border-b border-ink-500 sticky top-0 z-50 px-4 lg:px-8 py-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3 md:hidden">
                        <div class="w-8 h-8 rounded-md bg-rh-red flex items-center justify-center font-disp font-extrabold text-white text-sm">T</div>
                        <h1 class="font-disp text-sm font-bold text-fg">TeuPC Central</h1>
                    </div>
                    <nav class="flex md:hidden items-center gap-1 text-[11px] font-medium">
                        <?php foreach ($PAGES as $key => $p): ?>
                        <a href="<?= $p['href'] ?>" class="px-2 py-1 rounded <?= $key === $active ? 'bg-ink-600 text-fg' : 'text-fg-mid' ?>"><?= $p['label'] ?></a>
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

            <main class="flex-1 max-w-[1700px] w-full mx-auto p-4 lg:p-6 space-y-5">
<?php
}

function chrome_end(): void
{
    ?>
            </main>
        </div>
    </div>
</body>
</html>
<?php
}
