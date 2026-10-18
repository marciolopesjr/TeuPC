use anyhow::{Context, Result};
use chrono::Local;
use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;
use std::time::Duration;
use tokio::time::interval;

use crate::app::{App, AppSnapshot};
use crate::monitor::{MonitorEvent, SensorCategory};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DaemonConfig {
    pub server_url: String,
    pub api_key: String,
    #[serde(default = "default_interval")]
    pub interval_seconds: u64,
    #[serde(default)]
    pub machine_name: Option<String>,
    #[serde(default)]
    pub machine_id: Option<String>,
    #[serde(default = "default_true")]
    pub push_snapshot_on_start: bool,
    #[serde(default = "default_true")]
    pub verbose: bool,
}

fn default_interval() -> u64 {
    5
}

fn default_true() -> bool {
    true
}

impl Default for DaemonConfig {
    fn default() -> Self {
        Self {
            server_url: "http://127.0.0.1:8000".to_string(),
            // Sem chave embutida: precisa vir do arquivo de config ou de TEUPC_API_KEY.
            api_key: String::new(),
            interval_seconds: 5,
            machine_name: None,
            machine_id: None,
            push_snapshot_on_start: true,
            verbose: true,
        }
    }
}

pub fn resolve_machine_id(configured_id: &Option<String>) -> String {
    if let Some(id) = configured_id {
        if !id.trim().is_empty() {
            return id.trim().to_string();
        }
    }

    // Try /sys/class/dmi/id/product_uuid
    if let Ok(uuid) = fs::read_to_string("/sys/class/dmi/id/product_uuid") {
        let trimmed = uuid.trim();
        if !trimmed.is_empty() && trimmed != "None" && trimmed != "Default string" {
            return trimmed.to_string();
        }
    }

    // Try /etc/machine-id
    if let Ok(id) = fs::read_to_string("/etc/machine-id") {
        let trimmed = id.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }

    // Try /var/lib/dbus/machine-id
    if let Ok(id) = fs::read_to_string("/var/lib/dbus/machine-id") {
        let trimmed = id.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }

    // Fallback: hostname
    resolve_hostname(&None)
}

pub fn resolve_hostname(configured_name: &Option<String>) -> String {
    if let Some(name) = configured_name {
        if !name.trim().is_empty() {
            return name.trim().to_string();
        }
    }

    if let Ok(h) = fs::read_to_string("/proc/sys/kernel/hostname") {
        let trimmed = h.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }

    if let Ok(h) = fs::read_to_string("/etc/hostname") {
        let trimmed = h.trim();
        if !trimmed.is_empty() {
            return trimmed.to_string();
        }
    }

    std::env::var("HOSTNAME").unwrap_or_else(|_| "teupc-node".to_string())
}

pub fn load_config(custom_path: Option<&str>) -> (DaemonConfig, PathBuf) {
    let candidate_paths = if let Some(p) = custom_path {
        vec![PathBuf::from(p)]
    } else {
        vec![
            PathBuf::from("teupc_agent.json"),
            PathBuf::from("/etc/teupc/agent.json"),
            PathBuf::from("/etc/teupc_agent.json"),
        ]
    };

    for path in &candidate_paths {
        if path.exists() {
            if let Ok(content) = fs::read_to_string(path) {
                if let Ok(config) = serde_json::from_str::<DaemonConfig>(&content) {
                    return (config, path.clone());
                }
            }
        }
    }

    // Fallback: environment variables or default
    let mut config = DaemonConfig::default();
    if let Ok(url) = std::env::var("TEUPC_SERVER_URL") {
        config.server_url = url;
    }
    if let Ok(key) = std::env::var("TEUPC_API_KEY") {
        config.api_key = key;
    }
    if let Ok(sec) = std::env::var("TEUPC_INTERVAL_SECS") {
        if let Ok(val) = sec.parse::<u64>() {
            config.interval_seconds = val;
        }
    }

    let config_path = custom_path
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from("teupc_agent.json"));

    (config, config_path)
}

// Sem chave não há telemetria autenticada: falhar aqui, não no primeiro POST.
fn require_api_key(config: &DaemonConfig) -> Result<()> {
    if config.api_key.trim().is_empty() {
        anyhow::bail!(
            "api_key não configurada. Defina \"api_key\" no arquivo de config do agente \
             (teupc_agent.json ou /etc/teupc/agent.json) ou exporte TEUPC_API_KEY.\n\
             Gere uma chave com: openssl rand -hex 32"
        );
    }
    Ok(())
}

// Cliente único: reaproveita conexão/TLS entre os envios periódicos.
fn http_client() -> &'static reqwest::Client {
    static CLIENT: std::sync::OnceLock<reqwest::Client> = std::sync::OnceLock::new();
    CLIENT.get_or_init(|| {
        reqwest::Client::builder()
            .timeout(Duration::from_secs(10))
            .build()
            .unwrap_or_default()
    })
}

pub async fn send_http_post(url: &str, api_key: &str, payload_json: &str) -> Result<String> {
    let response = http_client()
        .post(url)
        .header("Content-Type", "application/json")
        .header("X-TeuPC-Key", api_key)
        .header("Authorization", format!("Bearer {}", api_key))
        .body(payload_json.to_string())
        .send()
        .await
        .context("Falha ao enviar requisição HTTP para a Central")?;

    let status = response.status();
    let body = response.text().await.unwrap_or_default();
    if !status.is_success() {
        anyhow::bail!("HTTP {} da Central: {}", status, body.trim());
    }

    Ok(body)
}

pub async fn run_test_connection(config: &DaemonConfig) -> Result<()> {
    require_api_key(config)?;
    let endpoint = format!("{}/api/telemetry.php", config.server_url.trim_end_matches('/'));
    println!("[*] Testando conexão com a Central TeuPC em: {}", endpoint);
    println!("[*] Chave de API: {}...", &config.api_key.chars().take(8).collect::<String>());

    let machine_id = resolve_machine_id(&config.machine_id);
    let hostname = resolve_hostname(&config.machine_name);

    let test_payload = serde_json::json!({
        "machine_id": machine_id,
        "hostname": hostname,
        "os": "Linux (TeuPC Connection Test)",
        "kernel": "test",
        "cpu_brand": "TeuPC Node Probe",
        "cpu_cores": 1,
        "mem_total": 1024 * 1024 * 1024,
        "cpu_usage": 0.0,
        "mem_used": 0,
        "swap_used": 0,
        "swap_total": 0,
        "disk_read_kbs": 0.0,
        "disk_write_kbs": 0.0,
        "net_rx_kbs": 0.0,
        "net_tx_kbs": 0.0,
        "load_1m": 0.0
    });

    let res = send_http_post(&endpoint, &config.api_key, &test_payload.to_string()).await?;
    println!("[+] Resposta do servidor recebida com sucesso:\n{}", res);
    Ok(())
}

pub async fn run_push_snapshot(config: &DaemonConfig) -> Result<()> {
    require_api_key(config)?;
    println!("[*] Coletando snapshot completo da máquina para envio...");
    let mut app = App::new();
    let (tx, mut rx) = tokio::sync::mpsc::channel(100);
    app.monitor_tx = Some(tx.clone());
    crate::monitor::spawn_monitors(tx).await;

    // Aguardar 3 segundos para povoar dados dos monitores
    let start = std::time::Instant::now();
    while start.elapsed() < Duration::from_secs(3) {
        if let Ok(Some(ev)) = tokio::time::timeout(Duration::from_millis(200), rx.recv()).await {
            app.on_monitor_event(ev);
        }
    }

    let snapshot = AppSnapshot {
        metadata: app.metadata.clone(),
        motherboard: app.motherboard.clone(),
        firmware: app.firmware.clone(),
        cpu_brand: app.cpu_brand.clone(),
        cpu_flags: app.cpu_flags.clone(),
        cpu_cache: app.cpu_cache.clone(),
        cpu_vulnerabilities: app.cpu_vulnerabilities.clone(),
        cpu_tuning: app.cpu_tuning.clone(),
        load_stats: app.load_stats.clone(),
        mem_total: app.mem_total,
        mem_advanced: app.advanced_mem.clone(),
        gpus: app.gpus.clone(),
        disks: app.disk_stats.clone(),
        software_raids: app.software_raids.clone(),
        filesystems: app.filesystems.clone(),
        nvme_health: app.nvme_health.clone(),
        audio: app.audio.clone(),
        kernel_diagnostics: app.kernel_diag.clone(),
        input_devices: app.input_devices.clone(),
        acpi_sleep: app.sleep_details.clone(),
        networks: app.networks.clone(),
        network_advanced: app.network_advanced.clone(),
        pci: app.pci_devices.clone(),
        usb: app.usb_devices.clone(),
        batteries: app.batteries.clone(),
        displays: app.displays.clone(),
        public_ip: app.public_ip.clone(),
        power_limits: app.power.clone(),
        peripherals: app.peripherals.clone(),
        logged_events: app.system_events.iter().cloned().collect(),
    };

    let machine_id = resolve_machine_id(&config.machine_id);
    let payload = serde_json::json!({
        "machine_id": machine_id,
        "snapshot": snapshot
    });

    let endpoint = format!("{}/api/snapshot.php", config.server_url.trim_end_matches('/'));
    let res = send_http_post(&endpoint, &config.api_key, &payload.to_string()).await?;
    println!("[+] Snapshot enviado com sucesso:\n{}", res);
    Ok(())
}

pub fn generate_service_unit(bin_path: &str, config_path: &str) -> String {
    format!(
        r#"[Unit]
Description=TeuPC Monitoring Daemon Agent
After=network.target network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
WorkingDirectory=/var/lib/teupc
StateDirectory=teupc
ExecStart={bin_path} --daemon --config {config_path}
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
AmbientCapabilities=CAP_PERFMON CAP_SYS_ADMIN CAP_NET_ADMIN
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=read-only
ProtectKernelTunables=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes

[Install]
WantedBy=multi-user.target
"#
    )
}

pub async fn run_daemon(config: DaemonConfig) -> Result<()> {
    require_api_key(&config)?;
    let machine_id = resolve_machine_id(&config.machine_id);
    let hostname = resolve_hostname(&config.machine_name);
    let base_url = config.server_url.trim_end_matches('/').to_string();
    let telemetry_endpoint = format!("{}/api/telemetry.php", base_url);
    let events_endpoint = format!("{}/api/events.php", base_url);
    let snapshot_endpoint = format!("{}/api/snapshot.php", base_url);

    println!("=======================================================");
    println!("  TeuPC Daemon - Background Monitoring Node Agent");
    println!("=======================================================");
    println!("[*] Machine ID : {}", machine_id);
    println!("[*] Hostname   : {}", hostname);
    println!("[*] Server URL : {}", base_url);
    println!("[*] Interval   : {}s", config.interval_seconds);
    println!("-------------------------------------------------------");

    let mut app = App::new();
    let (tx, mut rx) = tokio::sync::mpsc::channel(100);
    app.monitor_tx = Some(tx.clone());

    // Iniciar background collectors
    crate::monitor::spawn_monitors(tx).await;

    let mut tick_interval = interval(Duration::from_secs(config.interval_seconds));
    let mut initial_snapshot_sent = false;
    let mut cycle_count: u64 = 0;

    loop {
        tokio::select! {
            // 1. Receber eventos dos monitores
            maybe_event = rx.recv() => {
                if let Some(event) = maybe_event {
                    if let MonitorEvent::SystemEvent(ref sys_ev) = event {
                        // Envio em tempo real de anomalias/eventos críticos
                        let payload = serde_json::json!({
                            "machine_id": machine_id,
                            "severity": format!("{:?}", sys_ev.severity),
                            "category": format!("{:?}", sys_ev.category),
                            "title": sys_ev.title,
                            "details": sys_ev.details,
                            "timestamp": sys_ev.timestamp
                        });
                        let ev_url = events_endpoint.clone();
                        let key = config.api_key.clone();
                        let is_verbose = config.verbose;
                        tokio::spawn(async move {
                            if let Err(e) = send_http_post(&ev_url, &key, &payload.to_string()).await {
                                eprintln!("[!] Erro ao enviar evento anômalo para Central: {}", e);
                            } else if is_verbose {
                                println!("[EVENT] Alerta de hardware/processo despachado para a Central");
                            }
                        });
                    }
                    app.on_monitor_event(event);
                }
            }

            // 2. Intervalo periódico de telemetria
            _ = tick_interval.tick() => {
                cycle_count += 1;

                // Extrair métricas agregadas da App
                let cpu_usage = app.global_cpu;
                let cpu_temp = app.sensors.iter()
                    .find(|s| s.category == SensorCategory::Temperature && (s.label.contains("Package") || s.label.contains("Tctl") || s.label.contains("CPU") || s.label.contains("Core")))
                    .map(|s| s.value)
                    .or_else(|| app.sensors.iter().find(|s| s.category == SensorCategory::Temperature).map(|s| s.value));

                let rapl_watts = app.cpu_tuning.rapl_domains.package_w;
                let disk_read_kbs: f64 = app.disk_stats.iter().map(|d| d.read_kb_s).sum();
                let disk_write_kbs: f64 = app.disk_stats.iter().map(|d| d.write_kb_s).sum();
                let net_rx_kbs: f64 = app.networks.iter().map(|n| n.rx_per_sec as f64 / 1024.0).sum();
                let net_tx_kbs: f64 = app.networks.iter().map(|n| n.tx_per_sec as f64 / 1024.0).sum();
                let gpu_usage = app.gpus.first().and_then(|g| g.usage).map(|u| u as f32);
                let ip_public = app.public_ip.as_ref().map(|p| p.ip.clone()).unwrap_or_default();
                let ip_local = app.networks.iter()
                    .find(|n| !n.ipv4.is_empty() && !n.ipv4.starts_with("127."))
                    .map(|n| n.ipv4.clone())
                    .unwrap_or_default();

                let os_name = app.metadata.as_ref().map(|m| m.os.clone()).unwrap_or_else(|| "Linux".to_string());
                let kernel_ver = app.metadata.as_ref().map(|m| m.kernel.clone()).unwrap_or_default();
                let virt = app.metadata.as_ref().map(|m| m.virtualization.clone()).unwrap_or_else(|| "Bare Metal".to_string());

                // Top 15 processos
                let mut top_procs = app.processes.clone();
                top_procs.sort_by(|a, b| b.cpu_usage.partial_cmp(&a.cpu_usage).unwrap_or(std::cmp::Ordering::Equal));
                top_procs.truncate(15);

                let payload = serde_json::json!({
                    "machine_id": machine_id,
                    "hostname": hostname,
                    "os": os_name,
                    "kernel": kernel_ver,
                    "cpu_brand": app.cpu_brand,
                    "cpu_cores": app.cpus.len(),
                    "mem_total": app.mem_total,
                    "ip_public": ip_public,
                    "ip_local": ip_local,
                    "virtualization": virt,
                    "cpu_usage": cpu_usage,
                    "mem_used": app.mem_used,
                    "swap_used": app.swap_used,
                    "swap_total": app.swap_total,
                    "gpu_usage": gpu_usage,
                    "disk_read_kbs": disk_read_kbs,
                    "disk_write_kbs": disk_write_kbs,
                    "net_rx_kbs": net_rx_kbs,
                    "net_tx_kbs": net_tx_kbs,
                    "cpu_temp": cpu_temp,
                    "rapl_watts": rapl_watts,
                    "load_1m": app.load_stats.load_1m,
                    "top_processes": top_procs,
                    "sensors": app.sensors,
                    "batteries": app.batteries,
                    "disks": app.disk_stats
                });

                let tel_url = telemetry_endpoint.clone();
                let key = config.api_key.clone();
                let is_verbose = config.verbose;

                tokio::spawn(async move {
                    match send_http_post(&tel_url, &key, &payload.to_string()).await {
                        Ok(_) => {
                            if is_verbose {
                                println!("[{}] Telemetria enviada com sucesso (CPU: {:.1}%, RAM: {:.1}MB)", 
                                    Local::now().format("%H:%M:%S"),
                                    cpu_usage,
                                    payload["mem_used"].as_u64().unwrap_or(0) as f64 / 1024.0 / 1024.0
                                );
                            }
                        }
                        Err(e) => {
                            eprintln!("[{}] [!] Erro ao enviar telemetria: {}", Local::now().format("%H:%M:%S"), e);
                        }
                    }
                });

                // Enviar snapshot inicial assim que tivermos metadados completos
                if config.push_snapshot_on_start && !initial_snapshot_sent && cycle_count >= 2 && !app.cpu_brand.is_empty() {
                    initial_snapshot_sent = true;
                    let snap_url = snapshot_endpoint.clone();
                    let snap_key = config.api_key.clone();
                    let snap_mid = machine_id.clone();
                    let snapshot_struct = AppSnapshot {
                        metadata: app.metadata.clone(),
                        motherboard: app.motherboard.clone(),
                        firmware: app.firmware.clone(),
                        cpu_brand: app.cpu_brand.clone(),
                        cpu_flags: app.cpu_flags.clone(),
                        cpu_cache: app.cpu_cache.clone(),
                        cpu_vulnerabilities: app.cpu_vulnerabilities.clone(),
                        cpu_tuning: app.cpu_tuning.clone(),
                        load_stats: app.load_stats.clone(),
                        mem_total: app.mem_total,
                        mem_advanced: app.advanced_mem.clone(),
                        gpus: app.gpus.clone(),
                        disks: app.disk_stats.clone(),
                        software_raids: app.software_raids.clone(),
                        filesystems: app.filesystems.clone(),
                        nvme_health: app.nvme_health.clone(),
                        audio: app.audio.clone(),
                        kernel_diagnostics: app.kernel_diag.clone(),
                        input_devices: app.input_devices.clone(),
                        acpi_sleep: app.sleep_details.clone(),
                        networks: app.networks.clone(),
                        network_advanced: app.network_advanced.clone(),
                        pci: app.pci_devices.clone(),
                        usb: app.usb_devices.clone(),
                        batteries: app.batteries.clone(),
                        displays: app.displays.clone(),
                        public_ip: app.public_ip.clone(),
                        power_limits: app.power.clone(),
                        peripherals: app.peripherals.clone(),
                        logged_events: app.system_events.iter().cloned().collect(),
                    };

                    let snap_payload = serde_json::json!({
                        "machine_id": snap_mid,
                        "snapshot": snapshot_struct
                    });

                    tokio::spawn(async move {
                        if let Ok(_) = send_http_post(&snap_url, &snap_key, &snap_payload.to_string()).await {
                            println!("[*] Snapshot técnico de inicialização registrado na Central com sucesso.");
                        }
                    });
                }
            }
        }
    }
}
