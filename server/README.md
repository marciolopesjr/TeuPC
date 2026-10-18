# TeuPC Central - Hub de Monitoramento em PHP

O **TeuPC Central** é o backend e dashboard web para coleta, armazenamento e visualização em tempo real da telemetria enviada pelos agentes **TeuPC Daemon**.

---

## 📋 Requisitos

- **PHP 8.1+** (com extensões `pdo_sqlite`, `json`, `mbstring`)
- Servidor Web (Apache, Nginx ou o servidor embutido `php -S`)
- Navegador moderno com JavaScript habilitado

---

## 🚀 Como Executar

### 1. Servidor de Desenvolvimento (Embutido do PHP)
```bash
cd server
php -S 0.0.0.0:8000 -t public
```
Acesse: [http://localhost:8000](http://localhost:8000)

### 2. Produção com Nginx & PHP-FPM
Exemplo de bloco de configuração no Nginx:
```nginx
server {
    listen 80;
    server_name teupc.suaempresa.com;
    root /var/www/teupc/server/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Proteger banco de dados SQLite
    location ~ /\.ht {
        deny all;
    }
}
```

---

## 🔒 Segurança e Autenticação

A autenticação das requisições de telemetria e da API é realizada por **API Key**.

A chave é **obrigatória** e vem da variável de ambiente `TEUPC_API_KEY` — não existe valor padrão no código, e sem ela o hub responde `503` em todos os endpoints:
```bash
export TEUPC_API_KEY=$(openssl rand -hex 32)
```

O **dashboard** exige login com essa mesma chave (`public/login.php`), que abre uma sessão com cookie `HttpOnly` + `SameSite=Strict`. Os endpoints de leitura (`machines.php`, `snapshot.php`) também exigem autenticação.

Ao enviar requisições HTTP, inclua a chave através de:
- Header `X-TeuPC-Key: sua_chave`
- Header `Authorization: Bearer sua_chave`
- Query param `?api_key=sua_chave`

> O `?api_key=` fica registrado em logs de acesso — prefira os headers.

---

## 📡 Referência dos Endpoints da API

### 1. Ingestão de Telemetria (`POST /api/telemetry.php`)
Recebe a telemetria periódica do daemon (CPU, memória, disco, rede, sensores, processos).
- **Headers:** `X-TeuPC-Key`, `Content-Type: application/json`
- **Body:** JSON com `machine_id`, `hostname`, `cpu_usage`, `mem_used`, `disk_read_kbs`, etc.

### 2. Ingestão de Eventos e Anomalias (`POST /api/events.php`)
Recebe alertas de anomalias térmicas, falhas no kernel e picos de processos em tempo real.
- **Body:**
  ```json
  {
    "machine_id": "srv-prod-01",
    "severity": "Warning",
    "category": "ProcessSpike",
    "title": "Pico de CPU: ffmpeg (PID 14210)",
    "details": "Processo consumindo 94.2% de CPU (Usuário: lopes)"
  }
  ```

### 3. Ingestão & Consulta de Snapshots (`POST` / `GET /api/snapshot.php`)
Armazena e recupera o relatório técnico estruturado completo de hardware da máquina.

### 4. Consulta do Dashboard (`GET /api/machines.php`)
Retorna a lista de máquinas conectadas, status (online/offline), telemetria em tempo real e séries temporais para gráficos do Chart.js.

---

## 💾 Banco de Dados SQLite

O banco de dados SQLite é criado e migrado automaticamente na primeira execução no diretório `server/database/teupc_central.sqlite`.

Tabelas gerenciadas:
- `machines`: Cadastro e último batimento cardíaco (*heartbeat*) das máquinas.
- `telemetry_logs`: Histórico temporal das métricas de desempenho.
- `events_log`: Histórico de alertas e anomalias de hardware.
- `snapshots`: Snapshots técnicos JSON completos salvos sob demanda.
