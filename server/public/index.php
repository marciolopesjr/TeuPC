<?php
// Roteador da SPA: shell completo no primeiro load, fragmentos em requisicoes HTMX.

require_once __DIR__ . '/inc/chrome.php';

use TeuPC\Central\Auth;

$views = ['overview', 'events', 'network', 'settings'];
$view = $_GET['view'] ?? 'overview';
if (!in_array($view, $views, true)) {
    $view = 'overview';
}

$isHx = !empty($_SERVER['HTTP_HX_REQUEST']);

// Auth vale para shell e para fragmentos: nada de views sem sessao.
Auth::requireDashboard();

if ($isHx) {
    require __DIR__ . '/views/' . $view . '.php';
    exit;
}

chrome_shell($view);
require __DIR__ . '/views/' . $view . '.php';
chrome_end();
