<?php
// View "Central de Eventos": filtros + tabela renderizados server-side (SPA HTMX).
// Filtros via $_GET (machine, severity, q, page); a regiao da tabela se auto-atualiza a cada 15s.

require_once __DIR__ . '/../../src/Database.php';

use TeuPC\Central\Database;

$db = Database::getConnection();

if (!defined('EVENTS_PAGE_SIZE')) {
    define('EVENTS_PAGE_SIZE', 50);
}

// ---- helpers (protegidos contra redeclaracao, padrao do overview) ----

if (!function_exists('events_esc')) {
    function events_esc($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('events_sev_class')) {
    function events_sev_class(string $sev): string
    {
        if ($sev === 'Critical') return 'text-rh-red border-rh-red/40 bg-rh-red/10';
        if ($sev === 'Warning') return 'text-rh-amber border-rh-amber/40 bg-rh-amber/10';
        return 'text-rh-blue border-rh-blue/40 bg-rh-blue/10';
    }
}

if (!function_exists('events_read_filters')) {
    function events_read_filters(): array
    {
        return [
            'machine'  => trim((string) ($_GET['machine'] ?? '')),
            'severity' => in_array($_GET['severity'] ?? '', ['Critical', 'Warning', 'Info'], true) ? $_GET['severity'] : '',
            'q'        => trim((string) ($_GET['q'] ?? '')),
            'page'     => max(0, (int) ($_GET['page'] ?? 0)),
        ];
    }
}

if (!function_exists('events_where')) {
    // [sql, params] das condicoes dinamicas (todas em prepared statement)
    function events_where(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if ($f['machine'] !== '') {
            $where[] = 'e.machine_id = :machine';
            $params[':machine'] = $f['machine'];
        }
        if ($f['severity'] !== '') {
            $where[] = 'e.severity = :severity';
            $params[':severity'] = $f['severity'];
        }
        if ($f['q'] !== '') {
            // LIKE com % e _ escapados, via ESCAPE '\'
            $like = '%' . strtr($f['q'], ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
            $where[] = "(e.title LIKE :q ESCAPE '\\' OR e.category LIKE :q ESCAPE '\\' OR e.details LIKE :q ESCAPE '\\')";
            $params[':q'] = $like;
        }
        return [implode(' AND ', $where), $params];
    }
}

if (!function_exists('events_count')) {
    function events_count(PDO $db, array $f): int
    {
        [$where, $params] = events_where($f);
        $stmt = $db->prepare("SELECT COUNT(*) c FROM events_log e WHERE $where");
        $stmt->execute($params);
        return (int) $stmt->fetch()['c'];
    }
}

if (!function_exists('events_url')) {
    function events_url(array $f, int $page): string
    {
        $qs = http_build_query(array_filter([
            'view'     => 'events',
            'machine'  => $f['machine'],
            'severity' => $f['severity'],
            'q'        => $f['q'],
            'page'     => $page,
        ], fn($v) => $v !== '' && $v !== null));
        return '/?' . $qs;
    }
}

if (!function_exists('events_region')) {
    // Regiao da tabela: alvo do auto-refresh (every 15s) e da paginacao.
    // A URL do hx-get embute os filtros atuais, entao o refresh os preserva.
    function events_region(PDO $db, array $f): void
    {
        $total = events_count($db, $f);
        $pages = max(1, (int) ceil($total / EVENTS_PAGE_SIZE));
        $page = min($f['page'], $pages - 1);

        [$where, $params] = events_where($f);
        $stmt = $db->prepare("
            SELECT e.*, m.hostname FROM events_log e
            LEFT JOIN machines m ON e.machine_id = m.machine_id
            WHERE $where ORDER BY e.id DESC LIMIT " . EVENTS_PAGE_SIZE . " OFFSET " . ($page * EVENTS_PAGE_SIZE)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        ?>
        <div id="events-table-region"
             hx-get="<?= events_esc(events_url($f, $page)) ?>" hx-trigger="every 15s" hx-target="this" hx-swap="outerHTML">
            <div class="card p-4">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <h4 class="label !text-fg flex items-center gap-2"><i class="fas fa-bell"></i> Histórico de eventos da frota</h4>
                    <span class="text-[10px] text-fg-dim font-mono"><?= $total ?> eventos · auto-refresh 15s</span>
                </div>
                <div class="overflow-x-auto custom-scrollbar max-h-[620px] overflow-y-auto">
                    <table class="w-full text-left text-xs font-mono data-table">
                        <thead class="text-fg-dim border-b border-ink-500">
                            <tr>
                                <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Horário</th>
                                <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Máquina</th>
                                <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Severidade</th>
                                <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Categoria</th>
                                <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Evento</th>
                                <th class="p-2.5 font-medium uppercase text-[10px] tracking-wider">Detalhes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-500/60">
                            <?php if (!$rows): ?>
                            <tr><td colspan="6" class="p-8 text-center text-fg-dim">
                                <i class="fas fa-bell-slash text-lg block mb-2 text-fg-mid"></i>
                                Nenhum evento registrado para os filtros atuais.
                            </td></tr>
                            <?php else: foreach ($rows as $e):
                                [$date, $time] = array_pad(explode(' ', (string) $e['created_at']), 2, '');
                            ?>
                            <tr class="hover:bg-ink-900/60 transition-colors">
                                <td class="p-2.5 whitespace-nowrap"><span class="text-fg-dim"><?= events_esc($date) ?></span> <span class="text-fg-mid"><?= events_esc($time) ?></span></td>
                                <td class="p-2.5 text-fg font-medium"><?= events_esc($e['hostname'] ?: $e['machine_id'] ?: '-') ?></td>
                                <td class="p-2.5">
                                    <span class="text-[9px] px-1.5 py-0.5 rounded border font-mono font-medium <?= events_sev_class((string) $e['severity']) ?>">
                                        <i class="fas <?= $e['severity'] === 'Info' ? 'fa-info-circle' : 'fa-triangle-exclamation' ?> mr-1"></i><?= events_esc($e['severity'] ?: 'Info') ?>
                                    </span>
                                </td>
                                <td class="p-2.5 text-fg-mid"><?= events_esc($e['category']) ?></td>
                                <td class="p-2.5 text-fg"><?= events_esc($e['title']) ?></td>
                                <td class="p-2.5 text-fg-dim max-w-[340px] truncate" title="<?= events_esc($e['details']) ?>"><?= events_esc($e['details']) ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages > 1): ?>
                <div class="flex items-center justify-between gap-3 mt-3">
                    <?php if ($page > 0): ?>
                    <button type="button" hx-get="<?= events_esc(events_url($f, $page - 1)) ?>" hx-target="#events-table-region" hx-swap="outerHTML"
                        class="bg-ink-900 hover:bg-ink-700 text-fg-mid hover:text-fg border border-ink-500 text-xs px-3.5 py-2 rounded-md font-medium transition-colors">
                        <i class="fas fa-chevron-left mr-1"></i> Anterior
                    </button>
                    <?php else: ?><span></span><?php endif; ?>
                    <span class="text-[10px] text-fg-dim font-mono">Página <?= $page + 1 ?> de <?= $pages ?></span>
                    <?php if ($page < $pages - 1): ?>
                    <button type="button" hx-get="<?= events_esc(events_url($f, $page + 1)) ?>" hx-target="#events-table-region" hx-swap="outerHTML"
                        class="bg-ink-900 hover:bg-ink-700 text-fg-mid hover:text-fg border border-ink-500 text-xs px-3.5 py-2 rounded-md font-medium transition-colors">
                        Próxima <i class="fas fa-chevron-right ml-1"></i>
                    </button>
                    <?php else: ?><span></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

// ---- pagina ----

$f = events_read_filters();
$total = events_count($db, $f);
$machines = $db->query("SELECT machine_id, hostname FROM machines ORDER BY last_seen DESC")->fetchAll();
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h2 class="font-disp text-lg font-bold text-fg">Central de Eventos</h2>
    <span class="text-[10px] text-fg-dim font-mono"><?= $total ?> evento(s) com os filtros atuais</span>
</div>

<form method="get" action="/" class="card p-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="view" value="events">
    <div>
        <span class="label block mb-1.5">Máquina</span>
        <select name="machine" hx-get="/?view=events" hx-include="closest form" hx-target="#app" hx-push-url="true" hx-trigger="change"
            class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-xs text-fg focus:outline-none focus:border-rh-red/70 min-w-[180px] cursor-pointer">
            <option value="">Todas</option>
            <?php foreach ($machines as $m): ?>
            <option value="<?= events_esc($m['machine_id']) ?>" <?= $f['machine'] === $m['machine_id'] ? 'selected' : '' ?>><?= events_esc($m['hostname'] ?: $m['machine_id']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <span class="label block mb-1.5">Severidade</span>
        <select name="severity" hx-get="/?view=events" hx-include="closest form" hx-target="#app" hx-push-url="true" hx-trigger="change"
            class="bg-ink-900 border border-ink-500 rounded-md px-3 py-1.5 text-xs text-fg focus:outline-none focus:border-rh-red/70 cursor-pointer">
            <option value="">Todas</option>
            <option value="Critical" <?= $f['severity'] === 'Critical' ? 'selected' : '' ?>>Critical</option>
            <option value="Warning" <?= $f['severity'] === 'Warning' ? 'selected' : '' ?>>Warning</option>
            <option value="Info" <?= $f['severity'] === 'Info' ? 'selected' : '' ?>>Info</option>
        </select>
    </div>
    <div class="flex-1 min-w-[220px]">
        <span class="label block mb-1.5">Busca</span>
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-2.5 text-fg-dim text-xs"></i>
            <input type="text" name="q" value="<?= events_esc($f['q']) ?>"
                hx-get="/?view=events" hx-include="closest form" hx-target="#app" hx-push-url="true"
                hx-trigger="change, keyup changed delay:500ms"
                placeholder="Filtrar por título, categoria ou detalhe..."
                class="w-full bg-ink-900 border border-ink-500 rounded-md pl-8 pr-3 py-1.5 text-xs text-fg placeholder-fg-dim focus:outline-none focus:border-rh-red/70 transition-colors">
        </div>
    </div>
    <button type="button" hx-get="/?view=events" hx-include="closest form" hx-target="#app" hx-push-url="true" hx-trigger="click"
        class="bg-ink-600 hover:bg-ink-500 text-fg border border-ink-400 text-xs px-3.5 py-2 rounded-md font-medium transition-colors flex items-center gap-2">
        <i class="fas fa-arrows-rotate text-fg-mid"></i> Atualizar
    </button>
</form>

<?php events_region($db, $f); ?>
