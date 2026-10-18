# TeuPC Professional & TeuPC Central

O **TeuPC** é um ecossistema completo de monitoramento, telemetria de hardware e diagnóstico de infraestrutura Linux de alta performance (estilo HWiNFO64 / AIDA64 / Prometheus + Grafana ultraleve).

O projeto é dividido em dois pilares integrados:
1. **TeuPC Agent (Rust):** Binário nativo com TUI Cyberpunk e modo **Daemon em background**, coletando sinais vitais de hardware, detectando picos de processos e anomalias térmicas/kernel em tempo real.
2. **TeuPC Central (PHP + SQLite + Tailwind Web HUD):** Central de comando web para receber telemetria de dezenas de máquinas, alertas em tempo real e visualização de snapshots técnicos com gráficos dinâmicos.

---

## 🏛️ Arquitetura do Sistema

```
 ┌────────────────────────────────────────────────────────┐
 │           Nós de Monitoramento (TeuPC Agent)           │
 │                                                        │
 │   ┌───────────────────────┐  ┌───────────────────────┐ │
 │   │  TeuPC Daemon Node 1  │  │  TeuPC Daemon Node 2  │ │
 │   │ (systemd / background)│  │ (systemd / background)│ │
 │   └──────────┬────────────┘  └──────────┬────────────┘ │
 └──────────────┼──────────────────────────┼──────────────┘
                │ HTTP POST                │ HTTP POST
                │ (Telemetria/Eventos)     │ (Telemetria/Eventos)
                ▼                          ▼
 ┌────────────────────────────────────────────────────────┐
 │            TeuPC Central Hub (PHP Backend)             │
 │                                                        │
 │   • /api/telemetry.php   • /api/events.php             │
 │   • /api/snapshot.php    • /api/machines.php           │
 │                                                        │
 │   ┌────────────────────────────────────────────────┐   │
 │   │         Banco de Dados SQLite Embutido         │   │
 │   └────────────────────────────────────────────────┘   │
 └──────────────────────────┬─────────────────────────────┘
                            │ Web Dashboard (REST/Chart.js)
                            ▼
 ┌────────────────────────────────────────────────────────┐
 │        Cyberpunk HUD Web Dashboard (Porta 8000)        │
 │   Monitoramento multi-máquina, alertas e gráficos      │
 └────────────────────────────────────────────────────────┘
```

---

## 🚀 Funcionalidades do TeuPC Agent (Rust)

### 1. 🖥️ Modo TUI Interativo (Heads-Up Display)
Execução direta no terminal com alta densidade de informação:
```bash
./target/release/teupc
```
- **[1-7]**: Alternar entre telas:
  - `[1] GERAL` - HUD Principal (Cores, Histórico, TDP, Thermals, RAPL, **Log de Anomalias & Spikes**)
  - `[2] PROCESSOS` - Lista completa de tarefas com taxas de I/O de disco
  - `[3] HARDWARE` - Diagnóstico de CPU, DMI RAM, GPUs, Áudio, Scheduler e Filesystems
  - `[4] REDE` - Interfaces, Wi-Fi, Portas em Escuta, Tabela ARP e DNS
  - `[5] SENSORES` - Térmico, Voltagens, Coolers e Baterias
  - `[6] BARRAMENTO` - Slots PCI/PCIe, USBs, Dispositivos HID e Displays
  - `[7] BENCHMARK` - Teste de stress de CPU Single-Core e Multi-Core
  - `[?] AJUDA` - Atalhos e navegação
- **Atalhos rápidos:**
  - `s` - Exporta snapshot técnico JSON completo.
  - `b` - Dispara benchmark de CPU.
  - `c`, `m`, `p`, `r`, `w` - Ordenar processos por CPU, RAM, PID, Leitura ou Escrita.

### 2. ⚡ Modo Daemon (Background Service)
O daemon monitora o sistema silenciosamente e transmite métricas periódicas e eventos anômalos para o TeuPC Central:
```bash
# Execução direta como daemon
./target/release/teupc --daemon --config teupc_agent.json

# Testar conexão com a Central PHP
./target/release/teupc --test-connection

# Enviar snapshot técnico para a Central e sair
./target/release/teupc --push-snapshot

# Gerar arquivo de unidade systemd
./target/release/teupc --generate-service

# Instalar automaticamente como serviço de sistema (systemd)
sudo ./target/release/teupc --install-service
```

### 3. 🚨 Anomalias de Hardware & Picos de Processos
- **Thermal Throttling:** Detecção de aumento do contador de throttling térmico dos núcleos.
- **OOM Killer:** Identificação de processos sacrificados pelo Kernel Linux.
- **GPU Driver Hang/Reset:** Detecção de falhas de aceleração gráfica (*amdgpu*, *i915*, *NVIDIA*).
- **Storage Buffer Errors:** Registro de falhas de leitura/escrita em discos.
- **Process Spikes:** Picos de CPU (> 85%), RAM (> 4 GB) ou I/O (> 50 MB/s) com captura de PID, usuário e executável.
- **Persistência local:** Gravação contínua em `teupc_events.log` (no diretório de trabalho; sob systemd, em `/var/lib/teupc/`).

> **Root para anomalias de kernel:** OOM Killer, GPU hang, erros de I/O e MCE são lidos do
> `dmesg`, que exige root ou `CAP_SYSLOG` (`kernel.dmesg_restrict=1` na maioria das distros).
> Rodando a TUI como usuário comum esse conjunto de detecções fica desativado — o agente
> registra um evento informando isso no `teupc_events.log`. O daemon systemd roda como root.

---

## 🌐 TeuPC Central (PHP Hub & Dashboard)

A Central é uma aplicação PHP autônoma que não requer frameworks pesados nem dependências externas complexas.

### Início Rápido com Servidor Embutido do PHP:
```bash
cd server

# 1. Gere a chave do hub (obrigatória: sem ela o hub recusa toda requisição)
export TEUPC_API_KEY=$(openssl rand -hex 32)

# 2. Suba o servidor
php -S 0.0.0.0:8000 -t public
```
Acesse o Dashboard Web em `http://localhost:8000` e entre com a chave gerada.
O dashboard exige login: a mesma chave é usada pelo agente e pelo operador.

> Em produção, defina `TEUPC_API_KEY` no ambiente do PHP-FPM (não no código) e mantenha
> o dashboard atrás de HTTPS. O `server/database/*.sqlite` nunca deve ir para o git.

### Estrutura do Servidor (`server/`):
- `config.php`: Configuração de API Key, caminho do SQLite e retenção de histórico.
- `src/Database.php`: Conexão SQLite com migrações de tabelas automáticas.
- `src/Auth.php`: Autenticação via header `X-TeuPC-Key` ou `Authorization: Bearer <key>`.
- `public/api/telemetry.php`: Endpoint para ingestão periódica de métricas de telemetria.
- `public/api/events.php`: Endpoint para ingestão de alertas e eventos críticos em tempo real.
- `public/api/snapshot.php`: Ingestão e recuperação de snapshots detalhados de hardware.
- `public/api/machines.php`: Endpoint do Dashboard fornecendo status de máquinas e séries temporais.
- `public/index.php`: Interface Web HUD Dark Cyberpunk responsiva (Chart.js + TailwindCSS).

---

## ⚙️ Configuração do Agente (`teupc_agent.json`)

```json
{
  "server_url": "http://127.0.0.1:8000",
  "api_key": "a-mesma-chave-de-TEUPC_API_KEY-do-hub",
  "interval_seconds": 5,
  "machine_name": "Servidor-Producao-01",
  "machine_id": null,
  "push_snapshot_on_start": true,
  "verbose": true
}
```
> *Nota: Se `machine_id` for omitido (`null`), o agente detectará automaticamente o UUID do produto via DMI/SMBIOS ou `/etc/machine-id`.*
>
> *A `api_key` é obrigatória e precisa ser a mesma `TEUPC_API_KEY` do hub. Ela também pode vir da variável de ambiente `TEUPC_API_KEY` (útil sob systemd, via `Environment=`), evitando deixá-la em disco. Sem chave o agente recusa iniciar.*
>
> *`teupc_agent.json` está no `.gitignore` por conter a chave — nunca o commite.*

---

## 🛠️ Compilação do Agente Rust

```bash
cargo build --release
```
O binário final otimizado estará disponível em `target/release/teupc`.

---

## 📄 Licença
Distribuído sob a Licença MIT. Veja o arquivo `LICENSE` para mais informações.
