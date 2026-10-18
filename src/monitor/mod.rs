use nvml_wrapper::Nvml;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::ffi::CString;
use std::fs::{self, OpenOptions};
use std::io::Write;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::time::Instant;
use sysinfo::{Disks, System};
use tokio::sync::mpsc::Sender;
use tokio::time::{sleep, Duration};

#[derive(Deserialize, Debug, Default, Clone, Serialize)]
pub struct IntelGpuTop {
    pub engines: Option<IntelEngines>,
    pub frequency: Option<IntelFrequency>,
    pub power: Option<IntelPower>,
    pub rc6: Option<IntelRc6>,
}

#[derive(Deserialize, Debug, Default, Clone, Serialize)]
pub struct IntelEngines {
    #[serde(rename = "Render/3D", alias = "Render/3D/0")]
    pub render: Option<IntelEngineUsage>,
    #[serde(rename = "Video", alias = "Video/0")]
    pub video: Option<IntelEngineUsage>,
    #[serde(rename = "Blitter", alias = "Blitter/0")]
    pub blitter: Option<IntelEngineUsage>,
    #[serde(rename = "VideoEnhance", alias = "VideoEnhance/0")]
    pub video_enhance: Option<IntelEngineUsage>,
}

#[derive(Deserialize, Debug, Default, Clone, Serialize)]
pub struct IntelEngineUsage {
    pub busy: f32,
}

#[derive(Deserialize, Debug, Default, Clone, Serialize)]
pub struct IntelFrequency {
    pub actual: f32,
}

#[derive(Deserialize, Debug, Default, Clone, Serialize)]
pub struct IntelPower {
    #[serde(rename = "GPU")]
    pub gpu: f32,
    #[serde(rename = "Package")]
    pub package: f32,
}

#[derive(Deserialize, Debug, Default, Clone, Serialize)]
pub struct IntelRc6 {
    pub value: f32,
}

#[derive(Debug, Clone, Serialize, Default)]
pub struct GpuInfo {
    pub name: String,
    pub vendor: String,
    pub usage: Option<u32>,
    pub mem_used: u64,
    pub mem_total: u64,
    pub temp: Option<u32>,
    pub driver: String,
    pub clock_mhz: Option<u32>,
    pub power_w: Option<u32>,
    pub fan_speed: Option<u32>,
    pub intel_details: Option<IntelEngines>,
    pub intel_frequency_mhz: Option<f32>,
    pub intel_power_w: Option<f32>,
    pub intel_package_power_w: Option<f32>,
    pub intel_rc6: Option<f32>,
    pub status: Option<String>,
    pub pcie_link_speed: Option<String>,
    pub pcie_link_width: Option<String>,
    pub encoder_usage: Option<u32>,
    pub decoder_usage: Option<u32>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct NetworkInfo {
    pub name: String,
    pub rx_total: u64,
    pub tx_total: u64,
    pub rx_per_sec: u64,
    pub tx_per_sec: u64,
    pub ipv4: String,
    pub mac: String,
    pub speed_mbps: Option<u64>,
    pub rx_errors: u64,
    pub tx_errors: u64,
    pub rx_dropped: u64,
    pub tx_dropped: u64,
    pub iface_type: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct PciDevice {
    pub slot: String,
    pub vendor: String,
    pub device: String,
    pub link_speed: Option<String>,
    pub link_width: Option<String>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct UsbDevice {
    pub id: String,
    pub name: String,
}

#[derive(Debug, Clone, Serialize, Default)]
pub struct SystemMetadata {
    pub os: String,
    pub kernel: String,
    pub uptime: String,
    pub shell: String,
    pub resolution: String,
    pub de: String,
    pub wm: String,
    pub session_type: String,
    pub virtualization: String,
    pub docker_containers: Option<u32>,
    pub podman_containers: Option<u32>,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct MotherboardInfo {
    pub vendor: String,
    pub name: String,
    pub bios_version: String,
    pub bios_date: String,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct FirmwareDetails {
    pub boot_mode: String,
    pub secure_boot: String,
    pub tpm_version: String,
    pub tpm_description: String,
    pub chassis_type: String,
    pub chassis_vendor: String,
    pub product_uuid: String,
    pub product_serial: String,
    pub product_sku: String,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct CpuCacheInfo {
    pub l1d: String,
    pub l1i: String,
    pub l2: String,
    pub l3: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct SensorDetail {
    pub label: String,
    pub value: f32,
    pub unit: String,
    pub category: SensorCategory,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
pub enum SensorCategory {
    #[default]
    Temperature,
    Voltage,
    Fan,
    Power,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct BatteryInfo {
    pub name: String,
    pub model: String,
    pub capacity: u32,
    pub status: String,
    pub power_now: f32,
    pub health_pct: f32,
    pub cycle_count: u32,
    pub technology: String,
    pub voltage_v: f32,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct AcAdapterInfo {
    pub name: String,
    pub online: bool,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct DiskStats {
    pub name: String,
    pub model: String,
    pub read_kb_s: f64,
    pub write_kb_s: f64,
    pub total_read_gb: f64,
    pub total_write_gb: f64,
    pub scheduler: String,
    pub trim_supported: bool,
    pub sector_size: String,
    pub is_rotational: bool,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct SoftwareRaidInfo {
    pub name: String,
    pub level: String,
    pub status: String,
    pub devices: Vec<String>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct NvmeHealth {
    pub device: String,
    pub model: String,
    pub serial: String,
    pub firmware: String,
    pub percentage_used: Option<u8>,
    pub temperature_c: Option<u32>,
    pub data_read_tb: Option<f64>,
    pub data_written_tb: Option<f64>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct FileSystemInfo {
    pub mount_point: String,
    pub device: String,
    pub fs_type: String,
    pub total_bytes: u64,
    pub available_bytes: u64,
    pub inodes_total: u64,
    pub inodes_free: u64,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct BenchmarkResult {
    pub single_core: u64,
    pub multi_core: u64,
    pub is_running: bool,
    pub progress: u8,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct DisplayInfo {
    pub name: String,
    pub resolution: String,
    pub refresh_rate: f32,
    pub is_primary: bool,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct PublicIpInfo {
    pub ip: String,
    pub city: String,
    pub country: String,
    pub org: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct PowerLimits {
    pub current_governor: String,
    pub pl1_w: Option<f32>,
    pub pl2_w: Option<f32>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct RaplEnergyDomains {
    pub package_w: Option<f32>,
    pub core_w: Option<f32>,
    pub uncore_w: Option<f32>,
    pub dram_w: Option<f32>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct CpuTuningDetails {
    pub cpufreq_driver: String,
    pub turbo_boost_enabled: bool,
    pub available_governors: Vec<String>,
    pub rapl_domains: RaplEnergyDomains,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct PeripheralDevice {
    pub name: String,
    pub bus: String,
    pub battery: Option<u32>,
    pub device_type: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct RamSlotInfo {
    pub locator: String,
    pub bank_locator: String,
    pub capacity_gb: f32,
    pub mem_type: String,
    pub speed_mts: u32,
    pub manufacturer: String,
    pub part_number: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct MeminfoDetails {
    pub mem_available: u64,
    pub buffers: u64,
    pub cached: u64,
    pub dirty: u64,
    pub writeback: u64,
    pub slab: u64,
    pub page_tables: u64,
    pub shmem: u64,
    pub hugepages_total: u64,
    pub hugepages_free: u64,
    pub hugepage_size_kb: u64,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct ZramInfo {
    pub enabled: bool,
    pub device: String,
    pub disksize: u64,
    pub orig_data_size: u64,
    pub compr_data_size: u64,
    pub mem_used_total: u64,
    pub algorithm: String,
    pub compression_ratio: f32,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct AdvancedMemoryInfo {
    pub slots: Vec<RamSlotInfo>,
    pub meminfo: MeminfoDetails,
    pub zram: Option<ZramInfo>,
    pub zswap_enabled: bool,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct CpuCoreDetail {
    pub name: String,
    pub usage: f32,
    pub freq_mhz: f32,
    pub max_freq_mhz: f32,
    pub core_type: String,
    pub throttle_count: u64,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct CpuVulnerability {
    pub name: String,
    pub status: String,
    pub is_mitigated: bool,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct SystemLoadStats {
    pub load_1m: f32,
    pub load_5m: f32,
    pub load_15m: f32,
    pub running_processes: u32,
    pub total_processes: u32,
    pub context_switches_per_sec: u64,
    pub interrupts_per_sec: u64,
    pub microcode: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct WifiInfo {
    pub interface: String,
    pub ssid: String,
    pub frequency_ghz: f32,
    pub channel: u32,
    pub signal_dbm: i32,
    pub signal_quality_pct: u8,
    pub bitrate_mbps: f32,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct ListeningPort {
    pub protocol: String,
    pub local_address: String,
    pub state: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct ArpEntry {
    pub ip_address: String,
    pub mac_address: String,
    pub interface: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct NetworkAdvancedStats {
    pub default_gateway: String,
    pub dns_servers: Vec<String>,
    pub tcp_established: usize,
    pub tcp_time_wait: usize,
    pub tcp_listen: usize,
    pub wifi: Option<WifiInfo>,
    pub listening_ports: Vec<ListeningPort>,
    pub arp_entries: Vec<ArpEntry>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct AudioCardInfo {
    pub id: String,
    pub name: String,
    pub driver: String,
    pub codecs: Vec<String>,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct AudioSubsystem {
    pub cards: Vec<AudioCardInfo>,
    pub audio_server: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct KernelDiagnostics {
    pub tainted_flags: String,
    pub is_tainted: bool,
    pub loaded_modules_count: usize,
    pub boot_cmdline: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct InputDeviceInfo {
    pub name: String,
    pub handler: String,
    pub bustype: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct AcpiSleepDetails {
    pub supported_states: Vec<String>,
    pub current_mem_sleep: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct ProcessDetail {
    pub pid: u32,
    pub name: String,
    pub user: String,
    pub cpu_usage: f32,
    pub mem_bytes: u64,
    pub status: String,
    pub threads: u32,
    pub read_kb_s: f64,
    pub write_kb_s: f64,
}

// --- LOGGING & ANOMALY STRUCTURES (Items 2 & 6) ---

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub enum EventSeverity {
    Info,
    Warning,
    Critical,
}

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub enum EventCategory {
    HardwareAnomaly,
    ProcessSpike,
    KernelCritical,
    Throttling,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct SystemLogEvent {
    pub timestamp: String,
    pub severity: EventSeverity,
    pub category: EventCategory,
    pub title: String,
    pub details: String,
}

pub enum MonitorEvent {
    CpuUpdate {
        cpus: Vec<CpuCoreDetail>,
        global: f32,
        load_stats: SystemLoadStats,
        throttled: bool,
        cpu_tuning: CpuTuningDetails,
    },
    MemoryUpdate {
        used: u64,
        total: u64,
        s_used: u64,
        s_total: u64,
        advanced: AdvancedMemoryInfo,
    },
    GpuUpdate(Vec<GpuInfo>),
    NetworkUpdate {
        networks: Vec<NetworkInfo>,
        advanced: NetworkAdvancedStats,
    },
    ProcessUpdate(Vec<ProcessDetail>),
    SensorsUpdate {
        sensors: Vec<SensorDetail>,
        batteries: Vec<BatteryInfo>,
        ac_adapters: Vec<AcAdapterInfo>,
        peripherals: Vec<PeripheralDevice>,
    },
    MetadataUpdate {
        metadata: SystemMetadata,
        motherboard: MotherboardInfo,
        firmware: FirmwareDetails,
        cache: CpuCacheInfo,
        cpu_brand: String,
        cpu_flags: Vec<String>,
        disks: Vec<(String, u64, u64)>,
        disk_stats: Vec<DiskStats>,
        software_raids: Vec<SoftwareRaidInfo>,
        filesystems: Vec<FileSystemInfo>,
        nvme_health: Vec<NvmeHealth>,
        vulnerabilities: Vec<CpuVulnerability>,
        audio: AudioSubsystem,
        kernel_diag: KernelDiagnostics,
        input_devices: Vec<InputDeviceInfo>,
        sleep_details: AcpiSleepDetails,
    },
    BusUpdate {
        pci: Vec<PciDevice>,
        usb: Vec<UsbDevice>,
    },
    ExtremeUpdate {
        displays: Vec<DisplayInfo>,
        public_ip: Option<PublicIpInfo>,
        latency_ms: Option<u64>,
        power: PowerLimits,
    },
    BenchmarkUpdate(BenchmarkResult),
    SystemEvent(SystemLogEvent),
}

async fn emit_and_persist_event(event: SystemLogEvent, tx: &Sender<MonitorEvent>) {
    let log_line = format!(
        "[{}] [{:?}] [{:?}] {}: {}\n",
        event.timestamp, event.severity, event.category, event.title, event.details
    );
    if let Ok(mut file) = OpenOptions::new().create(true).append(true).open("teupc_events.log") {
        let _ = file.write_all(log_line.as_bytes());
    }
    // send() em vez de try_send(): com o canal cheio o alerta era descartado em silêncio
    // e nunca chegava à Central. Esperar o consumidor é melhor que perder o evento.
    let _ = tx.send(MonitorEvent::SystemEvent(event)).await;
}

enum IntelGpuTopResult {
    Metrics(IntelGpuTop),
    Unavailable(String),
}

async fn read_intel_gpu_top() -> IntelGpuTopResult {
    let output = match tokio::process::Command::new("intel_gpu_top")
        .arg("-J")
        .arg("-s")
        .arg("100")
        .arg("-n")
        .arg("1")
        .output()
        .await
    {
        Ok(output) => output,
        Err(_) => return IntelGpuTopResult::Unavailable("intel_gpu_top nao encontrado".to_string()),
    };

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        let reason = if stderr.contains("Permission denied") || stderr.contains("CAP_PERFMON") {
            "uso indisponivel: falta permissao CAP_PERFMON".to_string()
        } else {
            "uso indisponivel: intel_gpu_top falhou".to_string()
        };
        return IntelGpuTopResult::Unavailable(reason);
    }

    let parsed = match serde_json::from_slice::<serde_json::Value>(&output.stdout) {
        Ok(parsed) => parsed,
        Err(_) => {
            return IntelGpuTopResult::Unavailable(
                "uso indisponivel: saida invalida do intel_gpu_top".to_string(),
            )
        }
    };
    let Some(first) = parsed.as_array().and_then(|items| items.first()).cloned() else {
        return IntelGpuTopResult::Unavailable(
            "uso indisponivel: sem amostra do intel_gpu_top".to_string(),
        );
    };

    match serde_json::from_value::<IntelGpuTop>(first) {
        Ok(metrics) => IntelGpuTopResult::Metrics(metrics),
        Err(_) => IntelGpuTopResult::Unavailable(
            "uso indisponivel: formato desconhecido do intel_gpu_top".to_string(),
        ),
    }
}

fn intel_gpu_usage(engines: &IntelEngines) -> u32 {
    [
        engines.render.as_ref(),
        engines.video.as_ref(),
        engines.blitter.as_ref(),
        engines.video_enhance.as_ref(),
    ]
    .into_iter()
    .flatten()
    .map(|engine| engine.busy)
    .fold(0.0, f32::max)
    .clamp(0.0, 100.0) as u32
}

fn read_sysfs_u32(path: PathBuf) -> Option<u32> {
    fs::read_to_string(path).ok()?.trim().parse().ok()
}

fn read_sysfs_u64(path: PathBuf) -> Option<u64> {
    fs::read_to_string(path).ok()?.trim().parse().ok()
}

fn read_network_totals() -> Vec<(String, u64, u64, u64, u64, u64, u64, String)> {
    let mut networks = Vec::new();
    let Ok(entries) = fs::read_dir("/sys/class/net") else {
        return networks;
    };

    for entry in entries.flatten() {
        let path = entry.path();
        let Some(name) = path.file_name().and_then(|name| name.to_str()) else {
            continue;
        };

        let stats = path.join("statistics");
        let rx = read_sysfs_u64(stats.join("rx_bytes")).unwrap_or(0);
        let tx = read_sysfs_u64(stats.join("tx_bytes")).unwrap_or(0);
        let rx_err = read_sysfs_u64(stats.join("rx_errors")).unwrap_or(0);
        let tx_err = read_sysfs_u64(stats.join("tx_errors")).unwrap_or(0);
        let rx_drop = read_sysfs_u64(stats.join("rx_dropped")).unwrap_or(0);
        let tx_drop = read_sysfs_u64(stats.join("tx_dropped")).unwrap_or(0);

        let iface_type = if name == "lo" {
            "Loopback".to_string()
        } else if path.join("wireless").exists() || name.starts_with("wl") || name.starts_with("wlan") {
            "Wi-Fi".to_string()
        } else if path.join("bridge").exists() || name.starts_with("br") || name == "docker0" {
            "Bridge".to_string()
        } else if name.starts_with("tun") || name.starts_with("tap") || name.starts_with("wg") {
            "VPN/Tunnel".to_string()
        } else if name.starts_with("veth") {
            "Virtual/Container".to_string()
        } else if name.starts_with("en") || name.starts_with("eth") {
            "Ethernet".to_string()
        } else {
            "Rede".to_string()
        };

        networks.push((name.to_string(), rx, tx, rx_err, tx_err, rx_drop, tx_drop, iface_type));
    }

    networks.sort_by(|a, b| a.0.cmp(&b.0));
    networks
}

fn read_motherboard_info() -> MotherboardInfo {
    let mut info = MotherboardInfo::default();
    let dmi_path = Path::new("/sys/class/dmi/id");
    if dmi_path.exists() {
        info.vendor = fs::read_to_string(dmi_path.join("board_vendor")).unwrap_or_default().trim().to_string();
        info.name = fs::read_to_string(dmi_path.join("board_name")).unwrap_or_default().trim().to_string();
        info.bios_version = fs::read_to_string(dmi_path.join("bios_version")).unwrap_or_default().trim().to_string();
        info.bios_date = fs::read_to_string(dmi_path.join("bios_date")).unwrap_or_default().trim().to_string();
    }
    info
}

fn read_firmware_details() -> FirmwareDetails {
    let dmi_path = Path::new("/sys/class/dmi/id");
    let boot_mode = if Path::new("/sys/firmware/efi").exists() {
        "UEFI (64-bit)".to_string()
    } else {
        "Legacy BIOS".to_string()
    };

    let secure_boot = if let Ok(content) = fs::read_to_string("/sys/kernel/security/tpm0/binary_bios_measurements") {
        if !content.is_empty() { "Ativo (TPM)" } else { "Inativo" }
    } else if Path::new("/sys/firmware/efi/efivars").exists() {
        let mut sb = "Desabilitado";
        if let Ok(entries) = fs::read_dir("/sys/firmware/efi/efivars") {
            for e in entries.flatten() {
                let name = e.file_name().to_string_lossy().to_string();
                if name.starts_with("SecureBoot-") {
                    if let Ok(data) = fs::read(e.path()) {
                        if data.last() == Some(&1) {
                            sb = "Habilitado (Secure Boot)";
                        }
                    }
                }
            }
        }
        sb
    } else {
        "Não Suportado"
    }.to_string();

    let mut tpm_version = "Não detectado".to_string();
    let mut tpm_desc = String::new();
    if Path::new("/sys/class/tpm/tpm0").exists() {
        let major = fs::read_to_string("/sys/class/tpm/tpm0/tpm_version_major").unwrap_or_default().trim().to_string();
        if major == "2" {
            tpm_version = "TPM 2.0 (Ativo)".to_string();
        } else if major == "1" {
            tpm_version = "TPM 1.2 (Ativo)".to_string();
        } else {
            tpm_version = "TPM Presente".to_string();
        }
        tpm_desc = fs::read_to_string("/sys/class/tpm/tpm0/device/description").unwrap_or_default().trim().to_string();
    }

    let raw_chassis = fs::read_to_string(dmi_path.join("chassis_type")).unwrap_or_default().trim().to_string();
    let chassis_type = match raw_chassis.as_str() {
        "3" => "Desktop",
        "4" => "Low Profile Desktop",
        "6" => "Mini Tower",
        "7" => "Tower",
        "8" => "Portable",
        "9" => "Laptop",
        "10" => "Notebook",
        "11" => "Hand Held",
        "14" => "Sub Notebook",
        "17" => "Main Server",
        "23" => "Rack Mount Server",
        "30" => "Tablet",
        "31" => "Convertible",
        "35" => "Mini PC",
        _ => "Gabinete Padrão",
    }.to_string();

    let chassis_vendor = fs::read_to_string(dmi_path.join("chassis_vendor")).unwrap_or_default().trim().to_string();
    let product_uuid = fs::read_to_string(dmi_path.join("product_uuid")).unwrap_or_default().trim().to_string();
    let product_serial = fs::read_to_string(dmi_path.join("product_serial")).unwrap_or_default().trim().to_string();
    let product_sku = fs::read_to_string(dmi_path.join("product_sku")).unwrap_or_default().trim().to_string();

    FirmwareDetails {
        boot_mode,
        secure_boot,
        tpm_version,
        tpm_description: tpm_desc,
        chassis_type,
        chassis_vendor,
        product_uuid,
        product_serial,
        product_sku,
    }
}

fn read_cpu_cache_info() -> CpuCacheInfo {
    let mut info = CpuCacheInfo::default();
    let cache_base = Path::new("/sys/devices/system/cpu/cpu0/cache");
    if let Ok(entries) = fs::read_dir(cache_base) {
        for entry in entries.flatten() {
            let path = entry.path();
            let level = fs::read_to_string(path.join("level")).unwrap_or_default();
            let cache_type = fs::read_to_string(path.join("type")).unwrap_or_default();
            let size = fs::read_to_string(path.join("size")).unwrap_or_default().trim().to_string();

            match (level.trim(), cache_type.trim()) {
                ("1", "Data") => info.l1d = size,
                ("1", "Instruction") => info.l1i = size,
                ("2", _) => info.l2 = size,
                ("3", _) => info.l3 = size,
                _ => {}
            }
        }
    }
    info
}

fn read_cpu_tuning_details(last_rapl: &mut HashMap<String, (u64, Instant)>) -> CpuTuningDetails {
    let cpufreq_driver = fs::read_to_string("/sys/devices/system/cpu/cpu0/cpufreq/scaling_driver")
        .unwrap_or_else(|_| "generic".to_string())
        .trim()
        .to_string();

    let turbo_boost_enabled = fs::read_to_string("/sys/devices/system/cpu/cpufreq/boost")
        .map(|s| s.trim() == "1")
        .or_else(|_| fs::read_to_string("/sys/devices/system/cpu/intel_pstate/no_turbo").map(|s| s.trim() == "0"))
        .unwrap_or(true);

    let available_governors = fs::read_to_string("/sys/devices/system/cpu/cpu0/cpufreq/scaling_available_governors")
        .unwrap_or_default()
        .split_whitespace()
        .map(|s| s.to_string())
        .collect();

    let mut rapl_domains = RaplEnergyDomains::default();
    let rapl_base = Path::new("/sys/class/powercap");
    if let Ok(entries) = fs::read_dir(rapl_base) {
        for entry in entries.flatten() {
            let path = entry.path();
            let name = fs::read_to_string(path.join("name")).unwrap_or_default().trim().to_string();
            if let Some(energy_uj) = read_sysfs_u64(path.join("energy_uj")) {
                let now = Instant::now();
                if let Some((prev_uj, prev_time)) = last_rapl.get(&name) {
                    let elapsed = now.duration_since(*prev_time).as_secs_f32();
                    if elapsed > 0.0 {
                        let watts = (energy_uj.saturating_sub(*prev_uj) as f32 / 1_000_000.0) / elapsed;
                        if name.contains("package") || name == "intel-rapl:0" {
                            rapl_domains.package_w = Some(watts);
                        } else if name.contains("core") {
                            rapl_domains.core_w = Some(watts);
                        } else if name.contains("uncore") {
                            rapl_domains.uncore_w = Some(watts);
                        } else if name.contains("dram") {
                            rapl_domains.dram_w = Some(watts);
                        }
                    }
                }
                last_rapl.insert(name, (energy_uj, now));
            }
        }
    }

    CpuTuningDetails {
        cpufreq_driver,
        turbo_boost_enabled,
        available_governors,
        rapl_domains,
    }
}

fn read_audio_subsystem() -> AudioSubsystem {
    let mut cards = Vec::new();
    if let Ok(content) = fs::read_to_string("/proc/asound/cards") {
        let mut cur_id = String::new();
        let mut cur_driver = String::new();

        for line in content.lines() {
            let trimmed = line.trim();
            if line.starts_with(' ') {
                let cur_name = trimmed.to_string();
                if !cur_id.is_empty() {
                    let mut codecs = Vec::new();
                    let codec_dir = format!("/proc/asound/card{}", cur_id);
                    if let Ok(sub) = fs::read_dir(codec_dir) {
                        for se in sub.flatten() {
                            let fn_str = se.file_name().to_string_lossy().to_string();
                            if fn_str.starts_with("codec") {
                                if let Ok(c_content) = fs::read_to_string(se.path()) {
                                    if let Some(c_line) = c_content.lines().find(|l| l.starts_with("Codec:")) {
                                        codecs.push(c_line.replace("Codec:", "").trim().to_string());
                                    }
                                }
                            }
                        }
                    }

                    cards.push(AudioCardInfo {
                        id: cur_id.clone(),
                        name: cur_name,
                        driver: cur_driver.clone(),
                        codecs,
                    });
                }
            } else if let Some((id_str, rest)) = trimmed.split_once(" [") {
                cur_id = id_str.trim().to_string();
                if let Some((drv, _)) = rest.split_once("]:") {
                    cur_driver = drv.trim().to_string();
                }
            }
        }
    }

    let audio_server = if Path::new("/run/user/1000/pipewire-0").exists() || Command::new("pgrep").arg("pipewire").output().map(|o| o.status.success()).unwrap_or(false) {
        "PipeWire".to_string()
    } else if Command::new("pgrep").arg("pulseaudio").output().map(|o| o.status.success()).unwrap_or(false) {
        "PulseAudio".to_string()
    } else {
        "ALSA Puro".to_string()
    };

    AudioSubsystem { cards, audio_server }
}

fn read_kernel_diagnostics() -> KernelDiagnostics {
    let tainted_val = fs::read_to_string("/proc/sys/kernel/tainted").unwrap_or_default().trim().parse::<u32>().unwrap_or(0);
    let is_tainted = tainted_val != 0;
    let tainted_flags = if tainted_val == 0 {
        "0 (Kernel Limpo / Não Contaminado)".to_string()
    } else {
        let mut flags = Vec::new();
        if tainted_val & 1 != 0 { flags.push("P: Proprietary Module (ex: NVIDIA)"); }
        if tainted_val & (1 << 12) != 0 { flags.push("O: Out-of-tree Driver"); }
        if tainted_val & (1 << 13) != 0 { flags.push("E: Unsigned Module"); }
        format!("{} ({})", tainted_val, flags.join(", "))
    };

    let loaded_modules_count = fs::read_to_string("/proc/modules").map(|c| c.lines().count()).unwrap_or(0);
    let boot_cmdline = fs::read_to_string("/proc/cmdline").unwrap_or_default().trim().to_string();

    KernelDiagnostics {
        tainted_flags,
        is_tainted,
        loaded_modules_count,
        boot_cmdline,
    }
}

fn read_input_devices_and_acpi() -> (Vec<InputDeviceInfo>, AcpiSleepDetails) {
    let mut inputs = Vec::new();
    if let Ok(content) = fs::read_to_string("/proc/bus/input/devices") {
        let mut cur_name = String::new();
        let mut cur_handler = String::new();
        let mut cur_bus = "Padrão".to_string();

        for line in content.lines() {
            if line.starts_with("N: Name=") {
                cur_name = line.replace("N: Name=", "").trim_matches('"').trim().to_string();
            } else if line.starts_with("I: Bus=") {
                if line.contains("Bus=0003") { cur_bus = "USB".to_string(); }
                else if line.contains("Bus=0005") { cur_bus = "Bluetooth".to_string(); }
                else if line.contains("Bus=0011") { cur_bus = "PS/2 (i8042)".to_string(); }
                else if line.contains("Bus=0018") { cur_bus = "I2C".to_string(); }
            } else if line.starts_with("H: Handlers=") {
                cur_handler = line.replace("H: Handlers=", "").trim().to_string();
            } else if line.is_empty() && !cur_name.is_empty() {
                inputs.push(InputDeviceInfo {
                    name: cur_name.clone(),
                    handler: cur_handler.clone(),
                    bustype: cur_bus.clone(),
                });
                cur_name.clear();
                cur_handler.clear();
                cur_bus = "Padrão".to_string();
            }
        }
    }

    let raw_sleep = fs::read_to_string("/sys/power/mem_sleep").unwrap_or_default();
    let mut supported_states = Vec::new();
    let mut current_mem_sleep = "s2idle".to_string();
    for st in raw_sleep.split_whitespace() {
        if st.starts_with('[') && st.ends_with(']') {
            let active = st.trim_matches('[').trim_matches(']').to_string();
            current_mem_sleep = active.clone();
            supported_states.push(active);
        } else {
            supported_states.push(st.to_string());
        }
    }

    (inputs, AcpiSleepDetails { supported_states, current_mem_sleep })
}

fn read_listening_ports_and_arp() -> (Vec<ListeningPort>, Vec<ArpEntry>) {
    let mut ports = Vec::new();
    for (file, proto) in [("/proc/net/tcp", "TCP"), ("/proc/net/tcp6", "TCP6"), ("/proc/net/udp", "UDP"), ("/proc/net/udp6", "UDP6")] {
        if let Ok(content) = fs::read_to_string(file) {
            for line in content.lines().skip(1) {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 4 {
                    let state = parts[3];
                    if state == "0A" || proto.starts_with("UDP") {
                        if let Some((hex_ip, hex_port)) = parts[1].split_once(':') {
                            if let Ok(port) = u16::from_str_radix(hex_port, 16) {
                                let ip_str = if hex_ip.len() == 8 {
                                    if let Ok(ip_num) = u32::from_str_radix(hex_ip, 16) {
                                        let b = ip_num.to_le_bytes();
                                        format!("{}.{}.{}.{}", b[0], b[1], b[2], b[3])
                                    } else { "0.0.0.0".to_string() }
                                } else { "::".to_string() };

                                ports.push(ListeningPort {
                                    protocol: proto.to_string(),
                                    local_address: format!("{}:{}", ip_str, port),
                                    state: if state == "0A" { "LISTEN".to_string() } else { "BOUND".to_string() },
                                });
                            }
                        }
                    }
                }
            }
        }
    }

    let mut arp_entries = Vec::new();
    if let Ok(content) = fs::read_to_string("/proc/net/arp") {
        for line in content.lines().skip(1) {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() >= 6 && parts[3] != "00:00:00:00:00:00" {
                arp_entries.push(ArpEntry {
                    ip_address: parts[0].to_string(),
                    mac_address: parts[3].to_string(),
                    interface: parts[5].to_string(),
                });
            }
        }
    }

    (ports, arp_entries)
}

fn read_batteries() -> (Vec<BatteryInfo>, Vec<AcAdapterInfo>) {
    let mut batteries = Vec::new();
    let mut ac_adapters = Vec::new();
    let power_path = Path::new("/sys/class/power_supply");

    if let Ok(entries) = fs::read_dir(power_path) {
        for entry in entries.flatten() {
            let path = entry.path();
            let supply_type = fs::read_to_string(path.join("type")).unwrap_or_default();
            let supply_type_trimmed = supply_type.trim();

            if supply_type_trimmed == "Battery" {
                let name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
                let model = fs::read_to_string(path.join("model_name")).unwrap_or_else(|_| "Unknown".to_string()).trim().to_string();
                let capacity = read_sysfs_u32(path.join("capacity")).unwrap_or(0);
                let status = fs::read_to_string(path.join("status")).unwrap_or_else(|_| "Unknown".to_string()).trim().to_string();
                
                let power_now = read_sysfs_u64(path.join("power_now"))
                    .map(|p| p as f32 / 1_000_000.0)
                    .unwrap_or(0.0);

                let energy_full = read_sysfs_u64(path.join("energy_full"))
                    .or_else(|| read_sysfs_u64(path.join("charge_full")))
                    .unwrap_or(0) as f32;
                let energy_design = read_sysfs_u64(path.join("energy_full_design"))
                    .or_else(|| read_sysfs_u64(path.join("charge_full_design")))
                    .unwrap_or(0) as f32;

                let health_pct = if energy_design > 0.0 {
                    ((energy_full / energy_design) * 100.0).clamp(0.0, 100.0)
                } else {
                    100.0
                };

                let cycle_count = read_sysfs_u32(path.join("cycle_count")).unwrap_or(0);
                let technology = fs::read_to_string(path.join("technology")).unwrap_or_else(|_| "Li-ion".to_string()).trim().to_string();
                let voltage_v = read_sysfs_u64(path.join("voltage_now")).map(|v| v as f32 / 1_000_000.0).unwrap_or(0.0);

                batteries.push(BatteryInfo {
                    name,
                    model,
                    capacity,
                    status,
                    power_now,
                    health_pct,
                    cycle_count,
                    technology,
                    voltage_v,
                });
            } else if supply_type_trimmed == "Mains" || supply_type_trimmed == "AC" {
                let name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
                let online = read_sysfs_u32(path.join("online")).map(|v| v == 1).unwrap_or(false);
                ac_adapters.push(AcAdapterInfo { name, online });
            }
        }
    }
    (batteries, ac_adapters)
}

fn read_wireless_peripherals() -> Vec<PeripheralDevice> {
    let mut peripherals = Vec::new();
    let power_path = Path::new("/sys/class/power_supply");
    if let Ok(entries) = fs::read_dir(power_path) {
        for entry in entries.flatten() {
            let path = entry.path();
            let name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
            if name.starts_with("hidpp_battery") || name.starts_with("hid-") || name.starts_with("wacom_battery") {
                let model = fs::read_to_string(path.join("model_name")).unwrap_or_else(|_| name.clone()).trim().to_string();
                let capacity = read_sysfs_u32(path.join("capacity"));
                let device_type = if model.to_lowercase().contains("mouse") {
                    "Mouse".to_string()
                } else if model.to_lowercase().contains("keyboard") {
                    "Teclado".to_string()
                } else if model.to_lowercase().contains("headset") {
                    "Headset".to_string()
                } else {
                    "Periférico".to_string()
                };

                peripherals.push(PeripheralDevice {
                    name: model,
                    bus: "Wireless/BT".to_string(),
                    battery: capacity,
                    device_type,
                });
            }
        }
    }
    peripherals
}

fn read_cpu_flags() -> Vec<String> {
    if let Ok(content) = fs::read_to_string("/proc/cpuinfo") {
        if let Some(line) = content.lines().find(|l| l.starts_with("flags") || l.starts_with("Features")) {
            return line.split(':')
                .nth(1)
                .unwrap_or_default()
                .split_whitespace()
                .map(|s| s.to_string())
                .collect();
        }
    }
    Vec::new()
}

fn read_cpu_core_details(sys: &System) -> Vec<CpuCoreDetail> {
    let mut details = Vec::new();

    let cpu_dir = Path::new("/sys/devices/system/cpu");
    let mut max_freqs = Vec::new();

    if let Ok(entries) = fs::read_dir(cpu_dir) {
        let mut core_dirs: Vec<_> = entries.flatten()
            .filter_map(|e| {
                let name = e.file_name().to_string_lossy().into_owned();
                if name.starts_with("cpu") && name[3..].chars().all(|c| c.is_ascii_digit()) {
                    Some((name[3..].parse::<u32>().unwrap_or(0), e.path()))
                } else {
                    None
                }
            })
            .collect();
        core_dirs.sort_by_key(|c| c.0);

        for (_, path) in &core_dirs {
            let max_freq = read_sysfs_u64(path.join("cpufreq/cpuinfo_max_freq"))
                .or_else(|| read_sysfs_u64(path.join("cpufreq/scaling_max_freq")))
                .map(|f| f as f32 / 1000.0)
                .unwrap_or(0.0);
            max_freqs.push(max_freq);
        }

        let max_val = max_freqs.iter().cloned().fold(0.0, f32::max);
        let min_val = max_freqs.iter().cloned().filter(|v| *v > 0.0).fold(f32::MAX, f32::min);
        let is_heterogeneous = max_val > 0.0 && min_val > 0.0 && (max_val - min_val) > 400.0;

        for (i, (_, path)) in core_dirs.iter().enumerate() {
            let name = format!("CPU {}", i);
            let freq = read_sysfs_u64(path.join("cpufreq/scaling_cur_freq"))
                .map(|f| f as f32 / 1000.0)
                .unwrap_or(0.0);
            let max_f = max_freqs.get(i).copied().unwrap_or(0.0);
            let usage = sys.cpus().get(i).map(|c| c.cpu_usage()).unwrap_or(0.0);

            let throttle_cnt = read_sysfs_u64(path.join("thermal_throttle/core_throttle_count")).unwrap_or(0);

            let core_type = if is_heterogeneous {
                if max_f >= (max_val - 200.0) {
                    "P-Core".to_string()
                } else {
                    "E-Core".to_string()
                }
            } else {
                "Core".to_string()
            };

            details.push(CpuCoreDetail {
                name,
                usage,
                freq_mhz: freq,
                max_freq_mhz: max_f,
                core_type,
                throttle_count: throttle_cnt,
            });
        }
    }

    details
}

fn read_cpu_vulnerabilities() -> Vec<CpuVulnerability> {
    let mut vulns = Vec::new();
    let vuln_dir = Path::new("/sys/devices/system/cpu/vulnerabilities");
    if let Ok(entries) = fs::read_dir(vuln_dir) {
        for entry in entries.flatten() {
            let name = entry.file_name().to_string_lossy().to_string();
            let status = fs::read_to_string(entry.path()).unwrap_or_default().trim().to_string();
            let is_mitigated = status.starts_with("Mitigation") || status.contains("Not affected");
            vulns.push(CpuVulnerability {
                name,
                status,
                is_mitigated,
            });
        }
    }
    vulns.sort_by(|a, b| a.name.cmp(&b.name));
    vulns
}

fn read_system_load_stats() -> SystemLoadStats {
    let mut stats = SystemLoadStats::default();

    if let Ok(content) = fs::read_to_string("/proc/loadavg") {
        let parts: Vec<&str> = content.split_whitespace().collect();
        if parts.len() >= 4 {
            stats.load_1m = parts[0].parse().unwrap_or(0.0);
            stats.load_5m = parts[1].parse().unwrap_or(0.0);
            stats.load_15m = parts[2].parse().unwrap_or(0.0);

            if let Some((run, total)) = parts[3].split_once('/') {
                stats.running_processes = run.parse().unwrap_or(0);
                stats.total_processes = total.parse().unwrap_or(0);
            }
        }
    }

    if let Ok(content) = fs::read_to_string("/proc/cpuinfo") {
        for line in content.lines() {
            if line.starts_with("microcode") {
                if let Some(val) = line.split(':').nth(1) {
                    stats.microcode = val.trim().to_string();
                    break;
                }
            }
        }
    }

    if let Ok(content) = fs::read_to_string("/proc/stat") {
        for line in content.lines() {
            if line.starts_with("ctxt ") {
                stats.context_switches_per_sec = line.split_whitespace().nth(1).and_then(|v| v.parse().ok()).unwrap_or(0);
            } else if line.starts_with("intr ") {
                stats.interrupts_per_sec = line.split_whitespace().nth(1).and_then(|v| v.parse().ok()).unwrap_or(0);
            }
        }
    }

    stats
}

fn read_advanced_memory() -> AdvancedMemoryInfo {
    let mut meminfo = MeminfoDetails::default();

    if let Ok(content) = fs::read_to_string("/proc/meminfo") {
        for line in content.lines() {
            let mut parts = line.split_whitespace();
            let key = parts.next().unwrap_or_default().trim_end_matches(':');
            let val = parts.next().and_then(|v| v.parse::<u64>().ok()).unwrap_or(0);

            match key {
                "MemAvailable" => meminfo.mem_available = val * 1024,
                "Buffers" => meminfo.buffers = val * 1024,
                "Cached" => meminfo.cached = val * 1024,
                "Dirty" => meminfo.dirty = val * 1024,
                "Writeback" => meminfo.writeback = val * 1024,
                "Slab" => meminfo.slab = val * 1024,
                "PageTables" => meminfo.page_tables = val * 1024,
                "Shmem" => meminfo.shmem = val * 1024,
                "HugePages_Total" => meminfo.hugepages_total = val,
                "HugePages_Free" => meminfo.hugepages_free = val,
                "Hugepagesize" => meminfo.hugepage_size_kb = val,
                _ => {}
            }
        }
    }

    // ZRAM
    let mut zram = None;
    let zram_base = Path::new("/sys/block/zram0");
    if zram_base.exists() {
        let disksize = read_sysfs_u64(zram_base.join("disksize")).unwrap_or(0);
        let orig = read_sysfs_u64(zram_base.join("orig_data_size")).unwrap_or(0);
        let compr = read_sysfs_u64(zram_base.join("compr_data_size")).unwrap_or(0);
        let mem_used = read_sysfs_u64(zram_base.join("mem_used_total")).unwrap_or(0);
        let algo = fs::read_to_string(zram_base.join("comp_algorithm")).unwrap_or_default().trim().to_string();

        let ratio = if compr > 0 { orig as f32 / compr as f32 } else { 0.0 };

        zram = Some(ZramInfo {
            enabled: true,
            device: "zram0".to_string(),
            disksize,
            orig_data_size: orig,
            compr_data_size: compr,
            mem_used_total: mem_used,
            algorithm: algo,
            compression_ratio: ratio,
        });
    }

    let zswap_enabled = fs::read_to_string("/sys/module/zswap/parameters/enabled")
        .map(|s| s.trim() == "Y" || s.trim() == "1")
        .unwrap_or(false);

    // RAM Slots
    let mut slots = Vec::new();
    if let Ok(output) = Command::new("dmidecode").args(["-t", "memory"]).output() {
        if output.status.success() {
            let out_str = String::from_utf8_lossy(&output.stdout);
            let mut cur_slot = RamSlotInfo::default();
            let mut in_device = false;

            for line in out_str.lines() {
                let trimmed = line.trim();
                if trimmed == "Memory Device" {
                    if in_device && cur_slot.capacity_gb > 0.0 {
                        slots.push(cur_slot);
                    }
                    cur_slot = RamSlotInfo::default();
                    in_device = true;
                } else if in_device {
                    if let Some((k, v)) = trimmed.split_once(':') {
                        let val = v.trim();
                        match k.trim() {
                            "Locator" => cur_slot.locator = val.to_string(),
                            "Bank Locator" => cur_slot.bank_locator = val.to_string(),
                            "Type" => cur_slot.mem_type = val.to_string(),
                            "Speed" | "Configured Memory Speed" => {
                                if let Some(sp) = val.split_whitespace().next().and_then(|s| s.parse().ok()) {
                                    cur_slot.speed_mts = sp;
                                }
                            }
                            "Manufacturer" => cur_slot.manufacturer = val.to_string(),
                            "Part Number" => cur_slot.part_number = val.to_string(),
                            "Size" => {
                                if val.contains("GB") {
                                    cur_slot.capacity_gb = val.split_whitespace().next().and_then(|s| s.parse().ok()).unwrap_or(0.0);
                                } else if val.contains("MB") {
                                    let mb: f32 = val.split_whitespace().next().and_then(|s| s.parse().ok()).unwrap_or(0.0);
                                    cur_slot.capacity_gb = mb / 1024.0;
                                }
                            }
                            _ => {}
                        }
                    }
                }
            }
            if in_device && cur_slot.capacity_gb > 0.0 {
                slots.push(cur_slot);
            }
        }
    }

    AdvancedMemoryInfo {
        slots,
        meminfo,
        zram,
        zswap_enabled,
    }
}

async fn read_pci_devices() -> Vec<PciDevice> {
    let mut devices = Vec::new();
    if let Ok(output) = tokio::process::Command::new("lspci").arg("-mm").output().await {
        let out_str = String::from_utf8_lossy(&output.stdout);
        for line in out_str.lines() {
            let parts: Vec<&str> = line.split("\" \"").collect();
            if parts.len() >= 3 {
                let slot = parts[0].trim_matches('"').to_string();
                let vendor = parts[1].trim_matches('"').to_string();
                let device = parts[2].trim_matches('"').to_string();

                let pci_path = PathBuf::from(format!("/sys/bus/pci/devices/0000:{}", slot));
                let link_speed = fs::read_to_string(pci_path.join("current_link_speed")).ok().map(|s| s.trim().to_string());
                let link_width = fs::read_to_string(pci_path.join("current_link_width")).ok().map(|s| format!("x{}", s.trim()));

                devices.push(PciDevice {
                    slot,
                    vendor,
                    device,
                    link_speed,
                    link_width,
                });
            }
        }
    }
    devices
}

async fn read_usb_devices() -> Vec<UsbDevice> {
    let mut devices = Vec::new();
    if let Ok(output) = tokio::process::Command::new("lsusb").output().await {
        let out_str = String::from_utf8_lossy(&output.stdout);
        for line in out_str.lines() {
            if let Some(id_index) = line.find("ID ") {
                let id_and_name = &line[id_index + 3..];
                if let Some(space_index) = id_and_name.find(' ') {
                    devices.push(UsbDevice {
                        id: id_and_name[..space_index].to_string(),
                        name: id_and_name[space_index + 1..].to_string(),
                    });
                }
            }
        }
    }
    devices
}

async fn read_network_details(name: &str) -> (String, String, Option<u64>) {
    let mac = fs::read_to_string(format!("/sys/class/net/{}/address", name)).unwrap_or_default().trim().to_string();
    let speed = read_sysfs_u64(PathBuf::from(format!("/sys/class/net/{}/speed", name)));
    
    let mut ipv4 = "Unknown".to_string();
    if let Ok(output) = tokio::process::Command::new("ip").args(["-j", "addr", "show", name]).output().await {
        if let Ok(parsed) = serde_json::from_slice::<serde_json::Value>(&output.stdout) {
            if let Some(addr_info) = parsed.as_array().and_then(|a| a.first()) {
                if let Some(addr_list) = addr_info.get("addr_info").and_then(|a| a.as_array()) {
                    for addr in addr_list {
                        if addr.get("family").and_then(|f| f.as_str()) == Some("inet") {
                            ipv4 = addr.get("local").and_then(|l| l.as_str()).unwrap_or("Unknown").to_string();
                            break;
                        }
                    }
                }
            }
        }
    }

    (ipv4, mac, speed)
}

fn read_network_advanced_stats() -> NetworkAdvancedStats {
    let mut stats = NetworkAdvancedStats::default();

    if let Ok(content) = fs::read_to_string("/proc/net/route") {
        for line in content.lines().skip(1) {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() >= 3 && parts[1] == "00000000" {
                if let Ok(gw_hex) = u32::from_str_radix(parts[2], 16) {
                    let bytes = gw_hex.to_le_bytes();
                    stats.default_gateway = format!("{}.{}.{}.{}", bytes[0], bytes[1], bytes[2], bytes[3]);
                    break;
                }
            }
        }
    }

    if let Ok(content) = fs::read_to_string("/etc/resolv.conf") {
        for line in content.lines() {
            let line = line.trim();
            if line.starts_with("nameserver") {
                if let Some(ip) = line.split_whitespace().nth(1) {
                    stats.dns_servers.push(ip.to_string());
                }
            }
        }
    }

    for file in ["/proc/net/tcp", "/proc/net/tcp6"] {
        if let Ok(content) = fs::read_to_string(file) {
            for line in content.lines().skip(1) {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 4 {
                    match parts[3] {
                        "01" => stats.tcp_established += 1,
                        "06" => stats.tcp_time_wait += 1,
                        "0A" => stats.tcp_listen += 1,
                        _ => {}
                    }
                }
            }
        }
    }

    let (listening_ports, arp_entries) = read_listening_ports_and_arp();
    stats.listening_ports = listening_ports;
    stats.arp_entries = arp_entries;

    let wireless_path = Path::new("/proc/net/wireless");
    if wireless_path.exists() {
        if let Ok(output) = Command::new("nmcli").args(["-t", "-f", "active,ssid,chan,freq,signal,bars,rate", "dev", "wifi"]).output() {
            if output.status.success() {
                let out_str = String::from_utf8_lossy(&output.stdout);
                for line in out_str.lines() {
                    if line.starts_with("yes:") || line.starts_with("sim:") {
                        let parts: Vec<&str> = line.split(':').collect();
                        if parts.len() >= 7 {
                            let ssid = parts[1].to_string();
                            let chan = parts[2].parse().unwrap_or(0);
                            let freq = parts[3].replace("MHz", "").trim().parse::<f32>().unwrap_or(0.0) / 1000.0;
                            let signal = parts[4].parse::<u8>().unwrap_or(0);
                            let rate = parts[6].replace("Mbit/s", "").trim().parse::<f32>().unwrap_or(0.0);

                            stats.wifi = Some(WifiInfo {
                                interface: "wlan".to_string(),
                                ssid,
                                frequency_ghz: freq,
                                channel: chan,
                                signal_dbm: (signal as i32 / 2) - 100,
                                signal_quality_pct: signal,
                                bitrate_mbps: rate,
                            });
                            break;
                        }
                    }
                }
            }
        }
    }

    stats
}

fn read_disk_io() -> Vec<(String, u64, u64)> {
    let mut stats = Vec::new();
    if let Ok(content) = fs::read_to_string("/proc/diskstats") {
        for line in content.lines() {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() >= 13 {
                let name = parts[2].to_string();
                if (name.starts_with("sd") || name.starts_with("nvme") || name.starts_with("vd"))
                    && (!name.chars().last().unwrap().is_ascii_digit() || (name.starts_with("nvme") && name.contains('n') && !name.contains('p'))) {
                     let read_sectors: u64 = parts[5].parse().unwrap_or(0);
                     let write_sectors: u64 = parts[9].parse().unwrap_or(0);
                     stats.push((name, read_sectors * 512, write_sectors * 512));
                }
            }
        }
    }
    stats
}

fn read_all_sensors() -> Vec<SensorDetail> {
    let mut sensors = Vec::new();
    let hwmon_path = Path::new("/sys/class/hwmon");
    if let Ok(entries) = fs::read_dir(hwmon_path) {
        for entry in entries.flatten() {
            let path = entry.path();
            let name = fs::read_to_string(path.join("name")).unwrap_or_default().trim().to_string();
            
            if let Ok(sub_entries) = fs::read_dir(&path) {
                for sub in sub_entries.flatten() {
                    let sub_path = sub.path();
                    let file_name = sub_path.file_name().unwrap_or_default().to_string_lossy();
                    
                    if file_name.starts_with("temp") && file_name.ends_with("_input") {
                        let label_file = sub_path.with_file_name(file_name.replace("_input", "_label"));
                        let label = fs::read_to_string(label_file).unwrap_or_else(|_| format!("{}:{}", name, file_name.replace("_input", ""))).trim().to_string();
                        if let Some(val) = read_sysfs_u32(sub_path) {
                            sensors.push(SensorDetail { label, value: val as f32 / 1000.0, unit: "°C".to_string(), category: SensorCategory::Temperature });
                        }
                    } else if file_name.starts_with("in") && file_name.ends_with("_input") {
                         let label_file = sub_path.with_file_name(file_name.replace("_input", "_label"));
                         let label = fs::read_to_string(label_file).unwrap_or_else(|_| format!("{}:{}", name, file_name.replace("_input", ""))).trim().to_string();
                         if let Some(val) = read_sysfs_u32(sub_path) {
                            sensors.push(SensorDetail { label, value: val as f32 / 1000.0, unit: "V".to_string(), category: SensorCategory::Voltage });
                         }
                    } else if file_name.starts_with("fan") && file_name.ends_with("_input") {
                         let label_file = sub_path.with_file_name(file_name.replace("_input", "_label"));
                         let label = fs::read_to_string(label_file).unwrap_or_else(|_| format!("{}:{}", name, file_name.replace("_input", ""))).trim().to_string();
                         if let Some(val) = read_sysfs_u32(sub_path) {
                            sensors.push(SensorDetail { label, value: val as f32, unit: "RPM".to_string(), category: SensorCategory::Fan });
                         }
                    } else if file_name.starts_with("power") && file_name.ends_with("_input") {
                        let label_file = sub_path.with_file_name(file_name.replace("_input", "_label"));
                        let label = fs::read_to_string(label_file).unwrap_or_else(|_| format!("{}:{}", name, file_name.replace("_input", ""))).trim().to_string();
                        if let Some(val) = read_sysfs_u64(sub_path) {
                           sensors.push(SensorDetail { label, value: val as f32 / 1_000_000.0, unit: "W".to_string(), category: SensorCategory::Power });
                        }
                   }
                }
            }
        }
    }
    sensors
}

fn read_nvme_health() -> Vec<NvmeHealth> {
    let mut health_list = Vec::new();
    let nvme_dir = Path::new("/sys/class/nvme");
    if let Ok(entries) = fs::read_dir(nvme_dir) {
        for entry in entries.flatten() {
            let path = entry.path();
            let dev_name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
            let model = fs::read_to_string(path.join("model")).unwrap_or_else(|_| "Unknown NVMe".to_string()).trim().to_string();
            let serial = fs::read_to_string(path.join("serial")).unwrap_or_default().trim().to_string();
            let firmware = fs::read_to_string(path.join("firmware_rev")).unwrap_or_default().trim().to_string();

            let mut temp = None;
            if let Ok(hw_entries) = fs::read_dir(path.join("device/hwmon")) {
                if let Some(h) = hw_entries.flatten().next() {
                    temp = read_sysfs_u32(h.path().join("temp1_input")).map(|t| t / 1000);
                }
            }

            health_list.push(NvmeHealth {
                device: dev_name,
                model,
                serial,
                firmware,
                percentage_used: None,
                temperature_c: temp,
                data_read_tb: None,
                data_written_tb: None,
            });
        }
    }
    health_list
}

// /proc/mounts escapa espaço, tab, newline e barra invertida como octal (\040, \011, \012, \134).
fn unescape_mount_path(raw: &str) -> String {
    raw.replace("\\040", " ")
        .replace("\\011", "\t")
        .replace("\\012", "\n")
        .replace("\\134", "\\")
}

fn read_filesystems_and_inodes() -> Vec<FileSystemInfo> {
    let mut fs_list = Vec::new();
    let mut seen_mounts = std::collections::HashSet::new();

    if let Ok(content) = fs::read_to_string("/proc/mounts") {
        for line in content.lines() {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() >= 3 {
                let device = parts[0];
                let mount_point = unescape_mount_path(parts[1]);
                let fs_type = parts[2];

                if !device.starts_with("/dev/") {
                    continue;
                }
                if seen_mounts.contains(&mount_point) {
                    continue;
                }
                seen_mounts.insert(mount_point.clone());

                let mut total_bytes = 0;
                let mut avail_bytes = 0;
                let mut inodes_tot = 0;
                let mut inodes_fr = 0;

                unsafe {
                    let mut stat: libc::statvfs = std::mem::zeroed();
                    let c_path = CString::new(mount_point.as_str()).unwrap_or_default();
                    if libc::statvfs(c_path.as_ptr(), &mut stat) == 0 {
                        total_bytes = stat.f_blocks * stat.f_frsize;
                        avail_bytes = stat.f_bavail * stat.f_frsize;
                        inodes_tot = stat.f_files;
                        inodes_fr = stat.f_ffree;
                    }
                }

                fs_list.push(FileSystemInfo {
                    mount_point: mount_point.clone(),
                    device: device.to_string(),
                    fs_type: fs_type.to_string(),
                    total_bytes,
                    available_bytes: avail_bytes,
                    inodes_total: inodes_tot,
                    inodes_free: inodes_fr,
                });
            }
        }
    }
    fs_list
}

fn read_software_raids() -> Vec<SoftwareRaidInfo> {
    let mut raids = Vec::new();
    if let Ok(content) = fs::read_to_string("/proc/mdstat") {
        for line in content.lines() {
            if line.starts_with("md") {
                let parts: Vec<&str> = line.split_whitespace().collect();
                if parts.len() >= 3 {
                    let name = parts[0].to_string();
                    let status = parts[1].to_string();
                    let level = parts[2].to_string();
                    let devices = parts.iter().skip(3).map(|s| s.to_string()).collect();
                    raids.push(SoftwareRaidInfo { name, level, status, devices });
                }
            }
        }
    }
    raids
}

fn detect_virtualization() -> (String, Option<u32>, Option<u32>) {
    let mut virt = "Bare Metal".to_string();

    if let Ok(output) = Command::new("systemd-detect-virt").output() {
        if output.status.success() {
            let out = String::from_utf8_lossy(&output.stdout).trim().to_string();
            if !out.is_empty() && out != "none" {
                virt = out.to_uppercase();
            }
        }
    }

    if virt == "Bare Metal" {
        if Path::new("/.dockerenv").exists() {
            virt = "Docker Container".to_string();
        } else if Path::new("/run/systemd/container").exists() {
            virt = "Container".to_string();
        }
    }

    let docker_count = if Path::new("/var/run/docker.sock").exists() {
        Command::new("docker")
            .args(["ps", "-q"])
            .output()
            .ok()
            .map(|o| String::from_utf8_lossy(&o.stdout).lines().count() as u32)
    } else {
        None
    };

    let podman_count = Command::new("podman")
        .args(["ps", "-q"])
        .output()
        .ok()
        .map(|o| String::from_utf8_lossy(&o.stdout).lines().count() as u32);

    (virt, docker_count, podman_count)
}

async fn read_public_ip() -> Option<PublicIpInfo> {
    let output = tokio::process::Command::new("curl")
        .args(["-s", "https://ipinfo.io/json"])
        .output()
        .await
        .ok()?;
    
    if output.status.success() {
        let json: serde_json::Value = serde_json::from_slice(&output.stdout).ok()?;
        Some(PublicIpInfo {
            ip: json["ip"].as_str().unwrap_or("Unknown").to_string(),
            city: json["city"].as_str().unwrap_or("Unknown").to_string(),
            country: json["country"].as_str().unwrap_or("Unknown").to_string(),
            org: json["org"].as_str().unwrap_or("Unknown").to_string(),
        })
    } else {
        None
    }
}

async fn check_latency() -> Option<u64> {
    let start = Instant::now();
    let output = tokio::process::Command::new("ping")
        .args(["-c", "1", "-W", "1", "1.1.1.1"])
        .output()
        .await
        .ok()?;
    
    if output.status.success() {
        Some(start.elapsed().as_millis() as u64)
    } else {
        None
    }
}

fn read_displays() -> Vec<DisplayInfo> {
    let mut displays = Vec::new();
    if let Ok(output) = Command::new("xrandr").arg("--query").output() {
        let out_str = String::from_utf8_lossy(&output.stdout);
        for line in out_str.lines() {
            if line.contains(" connected") {
                let parts: Vec<&str> = line.split_whitespace().collect();
                let name = parts[0].to_string();
                let is_primary = line.contains("primary");
                
                let res_part = parts.iter().find(|p| p.contains('x') && p.chars().next().map(|c| c.is_ascii_digit()).unwrap_or(false));
                if let Some(res) = res_part {
                    displays.push(DisplayInfo {
                        name,
                        resolution: res.to_string(),
                        refresh_rate: 60.0,
                        is_primary,
                    });
                }
            }
        }
    }
    displays
}

fn read_power_limits() -> PowerLimits {
    let governor = fs::read_to_string("/sys/devices/system/cpu/cpu0/cpufreq/scaling_governor")
        .unwrap_or_else(|_| "Unknown".to_string()).trim().to_string();
    
    let pl1 = read_sysfs_u64(PathBuf::from("/sys/class/powercap/intel-rapl:0/constraint_0_power_limit_uw"))
        .map(|v| v as f32 / 1_000_000.0);
    let pl2 = read_sysfs_u64(PathBuf::from("/sys/class/powercap/intel-rapl:0/constraint_1_power_limit_uw"))
        .map(|v| v as f32 / 1_000_000.0);

    PowerLimits { current_governor: governor, pl1_w: pl1, pl2_w: pl2 }
}

pub fn run_cpu_benchmark(tx: Sender<MonitorEvent>) {
    tokio::spawn(async move {
        let mut result = BenchmarkResult { is_running: true, progress: 0, ..Default::default() };
        let _ = tx.send(MonitorEvent::BenchmarkUpdate(result.clone())).await;

        let start = Instant::now();
        let mut count = 0;
        let mut num = 2;
        while start.elapsed().as_secs() < 2 {
            let mut is_prime = true;
            for i in 2..=(num as f64).sqrt() as u64 {
                if num % i == 0 { is_prime = false; break; }
            }
            if is_prime { count += 1; }
            num += 1;
            result.progress = (start.elapsed().as_millis() * 50 / 2000) as u8;
            let _ = tx.send(MonitorEvent::BenchmarkUpdate(result.clone())).await;
        }
        result.single_core = count;
        result.progress = 50;
        let _ = tx.send(MonitorEvent::BenchmarkUpdate(result.clone())).await;

        let n_cpus = sysinfo::System::new_all().cpus().len();
        let (btx, mut brx) = tokio::sync::mpsc::channel(n_cpus);
        for _ in 0..n_cpus {
            let btx_clone = btx.clone();
            std::thread::spawn(move || {
                let start = Instant::now();
                let mut c = 0;
                let mut n = 2;
                while start.elapsed().as_secs() < 2 {
                    let mut is_p = true;
                    for i in 2..=(n as f64).sqrt() as u64 {
                        if n % i == 0 { is_p = false; break; }
                    }
                    if is_p { c += 1; }
                    n += 1;
                }
                let _ = btx_clone.blocking_send(c);
            });
        }
        
        let mut total_count = 0;
        for _ in 0..n_cpus {
            if let Some(c) = brx.recv().await { total_count += c; }
            result.progress = 50 + ((total_count as f32 / (result.single_core as f32 * n_cpus as f32)) * 50.0).min(49.0) as u8;
            let _ = tx.send(MonitorEvent::BenchmarkUpdate(result.clone())).await;
        }
        
        result.multi_core = total_count;
        result.is_running = false;
        result.progress = 100;
        let _ = tx.send(MonitorEvent::BenchmarkUpdate(result)).await;
    });
}

fn get_process_user(uid: u32, user_cache: &mut HashMap<u32, String>) -> String {
    if let Some(u) = user_cache.get(&uid) {
        return u.clone();
    }

    let mut user_name = uid.to_string();
    if let Ok(content) = fs::read_to_string("/etc/passwd") {
        for line in content.lines() {
            let parts: Vec<&str> = line.split(':').collect();
            if parts.len() >= 3 && parts[2] == uid.to_string() {
                user_name = parts[0].to_string();
                break;
            }
        }
    }
    user_cache.insert(uid, user_name.clone());
    user_name
}

// Check and log Process Spikes (Item 6)
async fn check_and_log_process_spikes(
    processes: &[ProcessDetail],
    spike_cooldowns: &mut HashMap<(u32, &'static str), Instant>,
    tx: &Sender<MonitorEvent>,
) {
    let now = Instant::now();
    let timestamp = chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string();

    for p in processes {
        // 1. CPU Spike > 85%
        if p.cpu_usage > 85.0 {
            let key = (p.pid, "CPU_SPIKE");
            let should_log = match spike_cooldowns.get(&key) {
                Some(prev) => prev.elapsed().as_secs() > 30,
                None => true,
            };
            if should_log {
                spike_cooldowns.insert(key, now);
                emit_and_persist_event(
                    SystemLogEvent {
                        timestamp: timestamp.clone(),
                        severity: EventSeverity::Warning,
                        category: EventCategory::ProcessSpike,
                        title: format!("Pico de CPU: {} (PID {})", p.name, p.pid),
                        details: format!("Processo consumindo {:.1}% de CPU (Usuário: {})", p.cpu_usage, p.user),
                    },
                    tx,
                ).await;
            }
        }

        // 2. High Memory Allocation > 4 GB
        if p.mem_bytes > 4 * 1024 * 1024 * 1024 {
            let key = (p.pid, "MEM_SPIKE");
            let should_log = match spike_cooldowns.get(&key) {
                Some(prev) => prev.elapsed().as_secs() > 60,
                None => true,
            };
            if should_log {
                spike_cooldowns.insert(key, now);
                emit_and_persist_event(
                    SystemLogEvent {
                        timestamp: timestamp.clone(),
                        severity: EventSeverity::Warning,
                        category: EventCategory::ProcessSpike,
                        title: format!("Alta Alocação de Memória: {} (PID {})", p.name, p.pid),
                        details: format!("Processo utilizando {:.2} GB de RAM (Usuário: {})", p.mem_bytes as f64 / (1024.0 * 1024.0 * 1024.0), p.user),
                    },
                    tx,
                ).await;
            }
        }

        // 3. Disk Write Spike > 50 MB/s
        if p.write_kb_s > 50_000.0 {
            let key = (p.pid, "DISK_WRITE_SPIKE");
            let should_log = match spike_cooldowns.get(&key) {
                Some(prev) => prev.elapsed().as_secs() > 30,
                None => true,
            };
            if should_log {
                spike_cooldowns.insert(key, now);
                emit_and_persist_event(
                    SystemLogEvent {
                        timestamp: timestamp.clone(),
                        severity: EventSeverity::Info,
                        category: EventCategory::ProcessSpike,
                        title: format!("Pico de Escrita no Disco: {} (PID {})", p.name, p.pid),
                        details: format!("Taxa de escrita: {:.1} MB/s (Usuário: {})", p.write_kb_s / 1024.0, p.user),
                    },
                    tx,
                ).await;
            }
        }
    }

    spike_cooldowns.retain(|_, time| time.elapsed().as_secs() < 120);
}

// dmesg é um buffer circular: comparar a contagem de linhas deixa de detectar qualquer
// coisa quando ele enche. Ancorar na última linha vista sobrevive à volta do buffer.
fn new_dmesg_lines<'a>(lines: &'a [&'a str], anchor: &Option<String>) -> &'a [&'a str] {
    let Some(last) = anchor else { return &[] };
    match lines.iter().position(|l| *l == last.as_str()) {
        Some(i) => &lines[i + 1..],
        None => lines, // buffer deu a volta: reprocessa tudo uma vez e reancora
    }
}

// Check and log Hardware & Kernel Critical Anomalies (Item 2)
async fn check_and_log_kernel_anomalies(
    last_line: &mut Option<String>,
    warned_unavailable: &mut bool,
    tx: &Sender<MonitorEvent>,
) {
    let output = match tokio::process::Command::new("dmesg")
        .args(["--ctime", "--level=emerg,alert,crit,err,warn"])
        .output()
        .await
    {
        Ok(out) if out.status.success() => out,
        _ => {
            // Sem aviso o recurso some em silêncio: o README promete OOM/GPU hang/erro de I/O.
            if !*warned_unavailable {
                *warned_unavailable = true;
                emit_and_persist_event(
                    SystemLogEvent {
                        timestamp: chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string(),
                        severity: EventSeverity::Info,
                        category: EventCategory::KernelCritical,
                        title: "Detecção de anomalias de kernel indisponível".to_string(),
                        details: "dmesg inacessível para este usuário (requer root ou CAP_SYSLOG); OOM killer, GPU hang e erros de I/O não serão detectados.".to_string(),
                    },
                    tx,
                ).await;
            }
            return;
        }
    };

    let out_str = String::from_utf8_lossy(&output.stdout);
    let lines: Vec<&str> = out_str.lines().collect();
    let anchor = last_line.clone();
    let timestamp = chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string();

    for line in new_dmesg_lines(&lines, &anchor) {
        let lower = line.to_lowercase();
        if lower.contains("out of memory") || lower.contains("invoked oom-killer") || lower.contains("killed process") {
            emit_and_persist_event(
                SystemLogEvent {
                    timestamp: timestamp.clone(),
                    severity: EventSeverity::Critical,
                    category: EventCategory::KernelCritical,
                    title: "Kernel OOM Killer Disparado".to_string(),
                    details: line.to_string(),
                },
                tx,
            ).await;
        } else if lower.contains("gpu hang") || lower.contains("gpu reset") || lower.contains("fallen off the bus") {
            emit_and_persist_event(
                SystemLogEvent {
                    timestamp: timestamp.clone(),
                    severity: EventSeverity::Critical,
                    category: EventCategory::HardwareAnomaly,
                    title: "GPU Hang / Reset Detectado".to_string(),
                    details: line.to_string(),
                },
                tx,
            ).await;
        } else if lower.contains("buffer i/o error") || lower.contains("i/o error") {
            emit_and_persist_event(
                SystemLogEvent {
                    timestamp: timestamp.clone(),
                    severity: EventSeverity::Critical,
                    category: EventCategory::HardwareAnomaly,
                    title: "Erro de I/O em Armazenamento".to_string(),
                    details: line.to_string(),
                },
                tx,
            ).await;
        } else if lower.contains("machine check exception") || lower.contains("mce:") {
            emit_and_persist_event(
                SystemLogEvent {
                    timestamp: timestamp.clone(),
                    severity: EventSeverity::Critical,
                    category: EventCategory::HardwareAnomaly,
                    title: "Hardware MCE (Machine Check Exception)".to_string(),
                    details: line.to_string(),
                },
                tx,
            ).await;
        }
    }

    if let Some(last) = lines.last() {
        *last_line = Some((*last).to_string());
    }
}

pub async fn spawn_monitors(tx: Sender<MonitorEvent>) {
    // 1. Static & Hardware Deep Metadata Task
    let tx_meta = tx.clone();
    tokio::spawn(async move {
        let mut sys = System::new();
        sys.refresh_all();
        let motherboard = read_motherboard_info();
        let firmware = read_firmware_details();
        let cache = read_cpu_cache_info();
        let cpu_flags = read_cpu_flags();
        let cpu_brand = sys.cpus().first().map(|c| c.brand().to_string()).unwrap_or_default();
        let os = format!("{} {}", System::name().unwrap_or_default(), System::os_version().unwrap_or_default());
        let kernel = System::kernel_version().unwrap_or_default();
        let shell = std::env::var("SHELL").unwrap_or_default();
        let de = std::env::var("XDG_CURRENT_DESKTOP").unwrap_or_default();
        let wm = std::env::var("XDG_SESSION_DESKTOP").unwrap_or_default();
        let session_type = std::env::var("XDG_SESSION_TYPE").unwrap_or_else(|_| "X11/Wayland".to_string());
        let resolution = detect_resolution_sync();
        let vulnerabilities = read_cpu_vulnerabilities();
        let audio = read_audio_subsystem();
        let kernel_diag = read_kernel_diagnostics();
        let (input_devices, sleep_details) = read_input_devices_and_acpi();

        let mut last_disk_totals = read_disk_io();
        let mut last_refresh = Instant::now();

        loop {
            let now = Instant::now();
            let elapsed = now.duration_since(last_refresh).as_secs_f64();
            let current_io = read_disk_io();
            let (virt, dock, pod) = detect_virtualization();
            let filesystems = read_filesystems_and_inodes();
            let nvme_health = read_nvme_health();
            let software_raids = read_software_raids();

            let mut disk_stats = Vec::new();
            for (name, r, w) in &current_io {
                let prev = last_disk_totals.iter().find(|(n, _, _)| n == name);
                let (r_kb, w_kb) = prev.map(|(_, pr, pw)| {
                    if elapsed > 0.0 {
                        (
                            ((*r).saturating_sub(*pr) as f64 / 1024.0 / elapsed),
                            ((*w).saturating_sub(*pw) as f64 / 1024.0 / elapsed)
                        )
                    } else { (0.0, 0.0) }
                }).unwrap_or((0.0, 0.0));

                let model = fs::read_to_string(format!("/sys/block/{}/device/model", name))
                    .or_else(|_| fs::read_to_string(format!("/sys/block/{}/../device/model", name)))
                    .unwrap_or_else(|_| "Unknown".to_string())
                    .trim().to_string();

                let scheduler = fs::read_to_string(format!("/sys/block/{}/queue/scheduler", name))
                    .ok()
                    .and_then(|s| {
                        s.split_whitespace().find(|w| w.starts_with('[')).map(|w| w.trim_matches('[').trim_matches(']').to_string())
                    }).unwrap_or_else(|| "none".to_string());

                let trim_supported = read_sysfs_u64(PathBuf::from(format!("/sys/block/{}/queue/discard_granularity", name))).unwrap_or(0) > 0;
                let logical_bs = read_sysfs_u32(PathBuf::from(format!("/sys/block/{}/queue/logical_block_size", name))).unwrap_or(512);
                let physical_bs = read_sysfs_u32(PathBuf::from(format!("/sys/block/{}/queue/physical_block_size", name))).unwrap_or(512);
                let sector_size = format!("{}B / {}B", logical_bs, physical_bs);
                let is_rotational = read_sysfs_u32(PathBuf::from(format!("/sys/block/{}/queue/rotational", name))).unwrap_or(0) == 1;

                disk_stats.push(DiskStats {
                    name: name.clone(),
                    model,
                    read_kb_s: r_kb,
                    write_kb_s: w_kb,
                    total_read_gb: *r as f64 / 1_073_741_824.0,
                    total_write_gb: *w as f64 / 1_073_741_824.0,
                    scheduler,
                    trim_supported,
                    sector_size,
                    is_rotational,
                });
            }

            let uptime_secs = System::uptime();
            let uptime = format!("{}h {}m", uptime_secs / 3600, (uptime_secs % 3600) / 60);
            let disks = Disks::new_with_refreshed_list()
                .iter()
                .map(|d| (d.mount_point().to_string_lossy().to_string(), d.available_space(), d.total_space()))
                .collect();

            let metadata = SystemMetadata {
                os: os.clone(),
                kernel: kernel.clone(),
                uptime,
                shell: shell.clone(),
                resolution: resolution.clone(),
                de: de.clone(),
                wm: wm.clone(),
                session_type: session_type.clone(),
                virtualization: virt,
                docker_containers: dock,
                podman_containers: pod,
            };

            let _ = tx_meta.send(MonitorEvent::MetadataUpdate {
                metadata,
                motherboard: motherboard.clone(),
                firmware: firmware.clone(),
                cache: cache.clone(),
                cpu_brand: cpu_brand.clone(),
                cpu_flags: cpu_flags.clone(),
                disks,
                disk_stats,
                software_raids,
                filesystems,
                nvme_health,
                vulnerabilities: vulnerabilities.clone(),
                audio: audio.clone(),
                kernel_diag: kernel_diag.clone(),
                input_devices: input_devices.clone(),
                sleep_details: sleep_details.clone(),
            }).await;
            
            last_disk_totals = current_io;
            last_refresh = now;
            sleep(Duration::from_secs(2)).await;
        }
    });

    // 2. CPU, RAM, Tuning and Process Deep Task with Spike & Anomaly Detection
    let tx_cpu = tx.clone();
    tokio::spawn(async move {
        let mut sys = System::new_all();
        let mut user_cache = HashMap::new();
        let mut last_proc_io: HashMap<u32, (u64, u64)> = HashMap::new();
        let mut last_rapl: HashMap<String, (u64, Instant)> = HashMap::new();
        let mut spike_cooldowns = HashMap::new();
        let mut last_dmesg_line: Option<String> = None;
        let mut dmesg_warned = false;
        let mut last_throttle_total: u64 = 0;
        let mut was_throttling = false;
        let mut last_refresh = Instant::now();

        loop {
            sys.refresh_all();
            let now = Instant::now();
            let elapsed = now.duration_since(last_refresh).as_secs_f64().max(0.1);

            let cpus = read_cpu_core_details(&sys);
            // core_throttle_count só cresce desde o boot: um delta é o que indica throttling agora.
            let throttle_total: u64 = cpus.iter().map(|c| c.throttle_count).sum();
            let has_throttle = throttle_total > last_throttle_total;
            last_throttle_total = throttle_total;
            let global_cpu = sys.global_cpu_info().cpu_usage();
            let load_stats = read_system_load_stats();
            let cpu_tuning = read_cpu_tuning_details(&mut last_rapl);

            // Log Thermal Throttling Transitions
            if has_throttle && !was_throttling {
                let timestamp = chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string();
                emit_and_persist_event(
                    SystemLogEvent {
                        timestamp,
                        severity: EventSeverity::Critical,
                        category: EventCategory::Throttling,
                        title: "Thermal Throttling Ativo".to_string(),
                        details: "O processador atingiu o limite de temperatura e reduziu clocks.".to_string(),
                    },
                    &tx_cpu,
                ).await;
            }
            was_throttling = has_throttle;

            let _ = tx_cpu.send(MonitorEvent::CpuUpdate {
                cpus,
                global: global_cpu,
                load_stats,
                throttled: has_throttle,
                cpu_tuning,
            }).await;

            let adv_mem = read_advanced_memory();
            let _ = tx_cpu.send(MonitorEvent::MemoryUpdate {
                used: sys.used_memory(),
                total: sys.total_memory(),
                s_used: sys.used_swap(),
                s_total: sys.total_swap(),
                advanced: adv_mem,
            }).await;

            // Processes & Spike Detection
            let mut processes = Vec::new();
            let mut current_proc_io = HashMap::new();

            for (pid, proc) in sys.processes() {
                let pid_u32 = pid.as_u32();
                let uid = proc.user_id().map(|u| **u).unwrap_or(1000);
                let user = get_process_user(uid, &mut user_cache);

                let mut r_kb = 0.0;
                let mut w_kb = 0.0;
                if let Ok(io_content) = fs::read_to_string(format!("/proc/{}/io", pid_u32)) {
                    let mut r_bytes = 0u64;
                    let mut w_bytes = 0u64;
                    for line in io_content.lines() {
                        if line.starts_with("read_bytes:") {
                            r_bytes = line.split_whitespace().nth(1).and_then(|v| v.parse().ok()).unwrap_or(0);
                        } else if line.starts_with("write_bytes:") {
                            w_bytes = line.split_whitespace().nth(1).and_then(|v| v.parse().ok()).unwrap_or(0);
                        }
                    }
                    current_proc_io.insert(pid_u32, (r_bytes, w_bytes));

                    if let Some((pr, pw)) = last_proc_io.get(&pid_u32) {
                        r_kb = (r_bytes.saturating_sub(*pr) as f64 / 1024.0 / elapsed).max(0.0);
                        w_kb = (w_bytes.saturating_sub(*pw) as f64 / 1024.0 / elapsed).max(0.0);
                    }
                }

                let threads = fs::read_to_string(format!("/proc/{}/status", pid_u32)).ok()
                    .and_then(|st| {
                        st.lines().find(|l| l.starts_with("Threads:")).and_then(|l| l.split_whitespace().nth(1)).and_then(|t| t.parse().ok())
                    }).unwrap_or(1);

                processes.push(ProcessDetail {
                    pid: pid_u32,
                    name: proc.name().to_string(),
                    user,
                    cpu_usage: proc.cpu_usage(),
                    mem_bytes: proc.memory(),
                    status: format!("{:?}", proc.status()),
                    threads,
                    read_kb_s: r_kb,
                    write_kb_s: w_kb,
                });
            }

            // Check Process Spikes (Item 6)
            check_and_log_process_spikes(&processes, &mut spike_cooldowns, &tx_cpu).await;

            // Check Kernel Anomalies (Item 2)
            check_and_log_kernel_anomalies(&mut last_dmesg_line, &mut dmesg_warned, &tx_cpu).await;

            let _ = tx_cpu.send(MonitorEvent::ProcessUpdate(processes)).await;
            last_proc_io = current_proc_io;
            last_refresh = now;

            sleep(Duration::from_millis(1000)).await;
        }
    });

    // 3. GPU Task
    let tx_gpu = tx.clone();
    tokio::spawn(async move {
        let nvml = Nvml::init().ok();
        loop {
            let mut gpus = Vec::new();
            if let Some(nvml) = &nvml {
                if let Ok(count) = nvml.device_count() {
                    let driver = nvml.sys_driver_version().unwrap_or_default();
                    for i in 0..count {
                        if let Ok(device) = nvml.device_by_index(i) {
                            let encoder = device.encoder_utilization().map(|e| e.utilization).ok();
                            let decoder = device.decoder_utilization().map(|d| d.utilization).ok();
                            let link_gen = device.current_pcie_link_gen().ok().map(|g| format!("Gen {}", g));
                            let link_width = device.current_pcie_link_width().ok().map(|w| format!("x{}", w));

                            gpus.push(GpuInfo {
                                name: device.name().unwrap_or_default(),
                                vendor: "NVIDIA".to_string(),
                                usage: device.utilization_rates().map(|u| u.gpu).ok(),
                                mem_used: device.memory_info().map(|m| m.used).unwrap_or(0),
                                mem_total: device.memory_info().map(|m| m.total).unwrap_or(0),
                                temp: device.temperature(nvml_wrapper::enum_wrappers::device::TemperatureSensor::Gpu).ok(),
                                driver: driver.clone(),
                                clock_mhz: device.clock_info(nvml_wrapper::enum_wrappers::device::Clock::Graphics).ok(),
                                power_w: device.power_usage().ok().map(|p| p / 1000),
                                fan_speed: device.fan_speed(0).ok(),
                                intel_details: None,
                                intel_frequency_mhz: None,
                                intel_power_w: None,
                                intel_package_power_w: None,
                                intel_rc6: None,
                                status: None,
                                pcie_link_speed: link_gen,
                                pcie_link_width: link_width,
                                encoder_usage: encoder,
                                decoder_usage: decoder,
                            });
                        }
                    }
                }
            }

            if let Ok(entries) = fs::read_dir("/sys/class/drm") {
                for entry in entries.flatten() {
                    let path = entry.path();
                    let name_str = path.file_name().and_then(|n| n.to_str()).unwrap_or("");
                    if name_str.starts_with("card") && !name_str.contains('-') {
                        let device_path = path.join("device");
                        if let Ok(vendor_id) = fs::read_to_string(device_path.join("vendor")) {
                            let vendor = match vendor_id.trim() {
                                "0x1002" => "AMD",
                                "0x8086" => "Intel",
                                _ => "Generic",
                            };

                            let pcie_link_speed = fs::read_to_string(device_path.join("current_link_speed")).ok().map(|s| s.trim().to_string());
                            let pcie_link_width = fs::read_to_string(device_path.join("current_link_width")).ok().map(|s| format!("x{}", s.trim()));
                            
                            if vendor == "Intel" {
                                let mut info = GpuInfo {
                                    name: "Intel Graphics".to_string(),
                                    vendor: "Intel".to_string(),
                                    usage: None,
                                    mem_used: 0,
                                    mem_total: 0,
                                    temp: None,
                                    driver: "i915/xe".to_string(),
                                    clock_mhz: None,
                                    power_w: None,
                                    fan_speed: None,
                                    intel_details: None,
                                    intel_frequency_mhz: None,
                                    intel_power_w: None,
                                    intel_package_power_w: None,
                                    intel_rc6: None,
                                    status: None,
                                    pcie_link_speed,
                                    pcie_link_width,
                                    encoder_usage: None,
                                    decoder_usage: None,
                                };
                                match read_intel_gpu_top().await {
                                    IntelGpuTopResult::Metrics(details) => {
                                        info.intel_frequency_mhz = details.frequency.as_ref().map(|f| f.actual);
                                        info.intel_power_w = details.power.as_ref().map(|p| p.gpu);
                                        info.intel_package_power_w = details.power.as_ref().map(|p| p.package);
                                        info.intel_rc6 = details.rc6.as_ref().map(|r| r.value);
                                        info.intel_details = details.engines;
                                        info.usage = info.intel_details.as_ref().map(intel_gpu_usage);
                                    }
                                    IntelGpuTopResult::Unavailable(reason) => info.status = Some(reason),
                                }
                                gpus.push(info);
                            } else if vendor == "AMD" {
                                let mut info = GpuInfo {
                                    name: "AMD Radeon GPU".to_string(),
                                    vendor: "AMD".to_string(),
                                    usage: read_sysfs_u32(device_path.join("gpu_busy_percent")),
                                    mem_used: read_sysfs_u64(device_path.join("mem_info_vram_used")).unwrap_or(0),
                                    mem_total: read_sysfs_u64(device_path.join("mem_info_vram_total")).unwrap_or(0),
                                    temp: None,
                                    driver: "amdgpu".to_string(),
                                    clock_mhz: None,
                                    power_w: None,
                                    fan_speed: None,
                                    intel_details: None,
                                    intel_frequency_mhz: None,
                                    intel_power_w: None,
                                    intel_package_power_w: None,
                                    intel_rc6: None,
                                    status: None,
                                    pcie_link_speed,
                                    pcie_link_width,
                                    encoder_usage: None,
                                    decoder_usage: None,
                                };
                                if let Ok(hwmon) = fs::read_dir(device_path.join("hwmon")) {
                                    if let Some(h) = hwmon.flatten().next() {
                                        info.temp = read_sysfs_u32(h.path().join("temp1_input")).map(|v| v / 1000);
                                        info.fan_speed = read_sysfs_u32(h.path().join("fan1_input"));
                                    }
                                }
                                gpus.push(info);
                            }
                        }
                    }
                }
            }

            let _ = tx_gpu.send(MonitorEvent::GpuUpdate(gpus)).await;
            sleep(Duration::from_millis(1000)).await;
        }
    });

    // 4. Network Task
    let tx_net = tx.clone();
    tokio::spawn(async move {
        let mut last_totals = read_network_totals();
        let mut last_refresh = Instant::now();
        loop {
            let now = Instant::now();
            let elapsed = now.duration_since(last_refresh).as_secs_f64();
            let current = read_network_totals();
            let adv_stats = read_network_advanced_stats();
            
            let mut networks = Vec::new();
            for (name, rx, tx, rx_err, tx_err, rx_drop, tx_drop, iface_type) in current.iter() {
                let prev = last_totals.iter().find(|(n, _, _, _, _, _, _, _)| n == name);
                let (rx_s, tx_s) = prev.map(|(_, orx, otx, _, _, _, _, _)| {
                    if elapsed > 0.0 {
                        (((*rx).saturating_sub(*orx) as f64 / elapsed) as u64, ((*tx).saturating_sub(*otx) as f64 / elapsed) as u64)
                    } else { (0,0) }
                }).unwrap_or((0,0));
                
                let (ipv4, mac, speed) = read_network_details(name).await;
                
                networks.push(NetworkInfo {
                    name: name.clone(),
                    rx_total: *rx,
                    tx_total: *tx,
                    rx_per_sec: rx_s,
                    tx_per_sec: tx_s,
                    ipv4,
                    mac,
                    speed_mbps: speed,
                    rx_errors: *rx_err,
                    tx_errors: *tx_err,
                    rx_dropped: *rx_drop,
                    tx_dropped: *tx_drop,
                    iface_type: iface_type.clone(),
                });
            }

            let _ = tx_net.send(MonitorEvent::NetworkUpdate {
                networks,
                advanced: adv_stats,
            }).await;
            last_totals = current;
            last_refresh = now;
            sleep(Duration::from_millis(1000)).await;
        }
    });

    // 5. Sensors, Battery & Peripheral Task
    let tx_sens = tx.clone();
    tokio::spawn(async move {
        loop {
            let sensors = read_all_sensors();
            let (batteries, ac_adapters) = read_batteries();
            let peripherals = read_wireless_peripherals();
            
            let _ = tx_sens.send(MonitorEvent::SensorsUpdate {
                sensors,
                batteries,
                ac_adapters,
                peripherals,
            }).await;

            sleep(Duration::from_millis(2000)).await;
        }
    });

    // 6. Bus Task (PCI/USB)
    let tx_bus = tx.clone();
    tokio::spawn(async move {
        loop {
            let pci = read_pci_devices().await;
            let usb = read_usb_devices().await;
            let _ = tx_bus.send(MonitorEvent::BusUpdate { pci, usb }).await;
            sleep(Duration::from_secs(30)).await;
        }
    });

    // 7. Extreme Task (Displays, Public IP, Latency, Power)
    let tx_extreme = tx.clone();
    tokio::spawn(async move {
        let mut public_ip = None;
        let mut ip_last_check = Instant::now() - Duration::from_secs(3600);

        loop {
            if ip_last_check.elapsed().as_secs() > 3600 {
                public_ip = read_public_ip().await;
                ip_last_check = Instant::now();
            }

            let latency = check_latency().await;
            let displays = read_displays();
            let power = read_power_limits();
            
            let _ = tx_extreme.send(MonitorEvent::ExtremeUpdate {
                displays,
                public_ip: public_ip.clone(),
                latency_ms: latency,
                power,
            }).await;

            sleep(Duration::from_secs(10)).await;
        }
    });
}

fn detect_resolution_sync() -> String {
    if let Ok(output) = Command::new("xrandr").arg("--current").output() {
        let out_str = String::from_utf8_lossy(&output.stdout);
        if let Some(line) = out_str.lines().find(|l| l.contains('*')) {
            return line.split_whitespace().next().unwrap_or("Unknown").to_string();
        }
    }
    "Unknown".to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dmesg_diff_sem_ancora_nao_processa_nada() {
        let lines = vec!["a", "b", "c"];
        assert!(new_dmesg_lines(&lines, &None).is_empty());
    }

    #[test]
    fn dmesg_diff_processa_apenas_as_linhas_novas() {
        let lines = vec!["a", "b", "c", "d"];
        let anchor = Some("b".to_string());
        assert_eq!(new_dmesg_lines(&lines, &anchor), &["c", "d"]);
    }

    #[test]
    fn dmesg_diff_reprocessa_quando_o_buffer_deu_a_volta() {
        // a âncora sumiu do buffer circular: melhor reprocessar do que perder a anomalia
        let lines = vec!["x", "y"];
        let anchor = Some("a".to_string());
        assert_eq!(new_dmesg_lines(&lines, &anchor), &["x", "y"]);
    }

    #[test]
    fn mount_path_com_espaco_escaped() {
        assert_eq!(unescape_mount_path("/mnt/meu\\040disco"), "/mnt/meu disco");
        assert_eq!(unescape_mount_path("/mnt/normal"), "/mnt/normal");
    }
}
