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
    <title>TeuPC Central | Acesso restrito</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700&family=Red+Hat+Text:wght@400;500;600&family=Red+Hat+Mono:wght@400;500&display=swap');
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
            background: #151515; color: #f2f2f2;
            font-family: 'Red Hat Text', system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%; max-width: 400px; background: #1f1f1f;
            border: 1px solid #2f2f2f; border-radius: 8px; padding: 2.25rem;
        }
        .brand { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.75rem; }
        .mark {
            width: 34px; height: 34px; border-radius: 6px; background: #EE0000; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Red Hat Display', sans-serif; font-weight: 700; font-size: 15px; letter-spacing: -0.5px;
        }
        .brand-name { font-family: 'Red Hat Display', sans-serif; font-weight: 700; font-size: 1.05rem; letter-spacing: 0.02em; }
        .brand-name span { color: #8a8a8a; font-weight: 500; }
        .hint { color: #a1a1a1; font-size: 0.825rem; line-height: 1.5; margin: 0 0 1.25rem; }
        label { display: block; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #8a8a8a; margin-bottom: 0.4rem; }
        input {
            width: 100%; background: #151515; border: 1px solid #3f3f3f; border-radius: 4px;
            padding: 0.65rem 0.75rem; color: #f2f2f2; font-size: 0.875rem; font-family: 'Red Hat Mono', monospace;
            outline: none; transition: border-color .15s ease;
        }
        input:focus { border-color: #EE0000; }
        input::placeholder { color: #5c5c5c; }
        button {
            width: 100%; margin-top: 1.25rem; background: #EE0000; color: #fff; border: none; border-radius: 4px;
            padding: 0.65rem 1rem; font-size: 0.875rem; font-weight: 600; font-family: 'Red Hat Text', sans-serif;
            cursor: pointer; transition: background .15s ease;
        }
        button:hover { background: #c40000; }
        .error {
            color: #ff6b6b; background: rgba(238,0,0,.08); border: 1px solid rgba(238,0,0,.35);
            border-radius: 4px; padding: 0.6rem 0.75rem; font-size: 0.8rem; margin: 0 0 1rem;
        }
        .foot { margin-top: 1.75rem; padding-top: 1rem; border-top: 1px solid #2f2f2f; font-family: 'Red Hat Mono', monospace; font-size: 0.7rem; color: #6b6b6b; }
    </style>
</head>
<body>
    <form method="post" class="card">
        <div class="brand">
            <div class="mark">T</div>
            <div class="brand-name">TeuPC <span>Central</span></div>
        </div>
        <p class="hint">Informe a API Key do hub para abrir o dashboard.</p>

        <?php if ($error !== null): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <label for="api_key">API Key</label>
        <input type="password" id="api_key" name="api_key" autofocus autocomplete="current-password"
               placeholder="••••••••••••••••">
        <button type="submit">Entrar</button>

        <div class="foot">NOC &middot; Telemetria de hardware em tempo real</div>
    </form>
</body>
</html>
