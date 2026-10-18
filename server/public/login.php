<?php
require_once __DIR__ . '/../src/Auth.php';

use TeuPC\Central\Auth;

Auth::startSession();

if (!empty($_SESSION['teupc_dashboard'])) {
    header('Location: index.php');
    exit;
}

$error = null;
$configured = Auth::configuredKey() !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$configured) {
        $error = 'TEUPC_API_KEY não está definida no ambiente do servidor.';
    } elseif (Auth::keyMatches((string)($_POST['api_key'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['teupc_dashboard'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Chave de API inválida.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ TeuPC Central | Acesso restrito</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #06080d; color: #e2e8f0; font-family: ui-monospace, monospace; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <form method="post" class="w-full max-w-sm bg-[#0e1420] border border-[#1b263b] rounded-xl p-6 space-y-4">
        <h1 class="text-sm font-bold tracking-widest text-cyan-400">⚡ TEUPC CENTRAL</h1>
        <p class="text-xs text-gray-400">Informe a API Key do hub para abrir o dashboard.</p>

        <?php if ($error !== null): ?>
            <p class="text-xs text-red-400 border border-red-500/30 bg-red-500/10 rounded p-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <input type="password" name="api_key" autofocus autocomplete="current-password"
               class="w-full bg-[#070a10] border border-[#1b263b] rounded px-3 py-2 text-sm focus:outline-none focus:border-cyan-500"
               placeholder="API Key">
        <button type="submit"
                class="w-full bg-cyan-500/20 border border-cyan-500/40 text-cyan-300 rounded px-3 py-2 text-sm font-bold hover:bg-cyan-500/30 transition">
            Entrar
        </button>
    </form>
</body>
</html>
