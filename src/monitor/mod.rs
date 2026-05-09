use nvml_wrapper::Nvml;
use serde::{Deserialize, Serialize};
use std::fs;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::time::Instant;
use sysinfo::{Components, Disks, System};
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

#[derive(Debug, Clone, Serialize)]
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
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct PciDevice {
    pub slot: String,
    pub vendor: String,
    pub device: String,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct UsbDevice {
    pub id: String,
    pub name: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct SystemMetadata {
    pub os: String,
    pub kernel: String,
    pub uptime: String,
    pub shell: String,
    pub resolution: String,
    pub de: String,
    pub wm: String,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct MotherboardInfo {
    pub vendor: String,
    pub name: String,
    pub bios_version: String,
    pub bios_date: String,
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
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct BatteryInfo {
    pub name: String,
    pub model: String,
    pub capacity: u32,
    pub status: String,
    pub power_now: f32,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct DiskStats {
    pub name: String,
    pub model: String,
    pub read_kb_s: f64,
    pub write_kb_s: f64,
    pub total_read_gb: f64,
    pub total_write_gb: f64,
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
pub struct PeripheralDevice {
    pub name: String,
    pub bus: String,
    pub battery: Option<u32>,
}

pub enum MonitorEvent {
    CpuUpdate {
        cpus: Vec<(String, f32, f32)>, // name, usage, freq_mhz
        global: f32,
    },
    MemoryUpdate(u64, u64, u64, u64),
    GpuUpdate(Vec<GpuInfo>),
    NetworkUpdate(Vec<NetworkInfo>),
    ProcessUpdate(Vec<(u32, String, f32, u64, String)>),
    SensorsUpdate {
        sensors: Vec<SensorDetail>,
        batteries: Vec<BatteryInfo>,
    },
    MetadataUpdate {
        metadata: SystemMetadata,
        motherboard: MotherboardInfo,
        cache: CpuCacheInfo,
        cpu_brand: String,
        cpu_flags: Vec<String>,
        disks: Vec<(String, u64, u64)>,
        disk_stats: Vec<DiskStats>,
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
        peripherals: Vec<PeripheralDevice>,
    },
    BenchmarkUpdate(BenchmarkResult),
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

fn read_network_totals() -> Vec<(String, u64, u64)> {
    let mut networks = Vec::new();
    let Ok(entries) = fs::read_dir("/sys/class/net") else {
        return networks;
    };

    for entry in entries.flatten() {
        let path = entry.path();
        let Some(name) = path.file_name().and_then(|name| name.to_str()) else {
            continue;
        };

        let rx = read_sysfs_u64(path.join("statistics/rx_bytes")).unwrap_or(0);
        let tx = read_sysfs_u64(path.join("statistics/tx_bytes")).unwrap_or(0);
        networks.push((name.to_string(), rx, tx));
    }

    networks.sort_by(|a, b| a.0.cmp(&b.0));
    networks
}

fn read_motherboard_info() -> MotherboardInfo {
    let mut info = MotherboardInfo::default();
    let dmi_path = Path::new("/sys/class/dmi/id");
    if dmi_path.exists() {
        info.vendor = fs::read_to_string(dmi_path.join("board_vendor"))
            .unwrap_or_default()
            .trim()
            .to_string();
        info.name = fs::read_to_string(dmi_path.join("board_name"))
            .unwrap_or_default()
            .trim()
            .to_string();
        info.bios_version = fs::read_to_string(dmi_path.join("bios_version"))
            .unwrap_or_default()
            .trim()
            .to_string();
        info.bios_date = fs::read_to_string(dmi_path.join("bios_date"))
            .unwrap_or_default()
            .trim()
            .to_string();
    }
    info
}

fn read_cpu_cache_info() -> CpuCacheInfo {
    let mut info = CpuCacheInfo::default();
    // Simplified: check cpu0 cache.
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

fn read_batteries() -> Vec<BatteryInfo> {
    let mut batteries = Vec::new();
    let power_path = Path::new("/sys/class/power_supply");
    if let Ok(entries) = fs::read_dir(power_path) {
        for entry in entries.flatten() {
            let path = entry.path();
            let supply_type = fs::read_to_string(path.join("type")).unwrap_or_default();
            if supply_type.trim() == "Battery" {
                let name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
                let model = fs::read_to_string(path.join("model_name")).unwrap_or_else(|_| "Unknown".to_string()).trim().to_string();
                let capacity = fs::read_to_string(path.join("capacity")).unwrap_or_default().trim().parse().unwrap_or(0);
                let status = fs::read_to_string(path.join("status")).unwrap_or_else(|_| "Unknown".to_string()).trim().to_string();
                let power_now = fs::read_to_string(path.join("power_now")).ok()
                    .and_then(|s| s.trim().parse::<f32>().ok())
                    .map(|p| p / 1_000_000.0) // microwatts to watts
                    .unwrap_or(0.0);
                
                batteries.push(BatteryInfo { name, model, capacity, status, power_now });
            }
        }
    }
    batteries
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

fn read_cpu_frequencies() -> Vec<f32> {
    let mut freqs = Vec::new();
    if let Ok(entries) = fs::read_dir("/sys/devices/system/cpu") {
        let mut cores: Vec<_> = entries.flatten()
            .filter_map(|e| {
                let name = e.file_name().to_string_lossy().into_owned();
                if name.starts_with("cpu") && name[3..].chars().all(|c| c.is_ascii_digit()) {
                    Some((name[3..].parse::<u32>().unwrap_or(0), e.path()))
                } else {
                    None
                }
            })
            .collect();
        cores.sort_by_key(|c| c.0);
        for (_, path) in cores {
            if let Some(freq_khz) = read_sysfs_u64(path.join("cpufreq/scaling_cur_freq")) {
                freqs.push(freq_khz as f32 / 1000.0);
            }
        }
    }
    freqs
}

async fn read_pci_devices() -> Vec<PciDevice> {
    let mut devices = Vec::new();
    if let Ok(output) = tokio::process::Command::new("lspci").arg("-mm").output().await {
        let out_str = String::from_utf8_lossy(&output.stdout);
        for line in out_str.lines() {
            let parts: Vec<&str> = line.split("\" \"").collect();
            if parts.len() >= 3 {
                devices.push(PciDevice {
                    slot: parts[0].trim_matches('"').to_string(),
                    vendor: parts[1].trim_matches('"').to_string(),
                    device: parts[2].trim_matches('"').to_string(),
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

fn read_disk_io() -> Vec<(String, u64, u64)> {
    let mut stats = Vec::new();
    if let Ok(content) = fs::read_to_string("/proc/diskstats") {
        for line in content.lines() {
            let parts: Vec<&str> = line.split_whitespace().collect();
            if parts.len() >= 13 {
                let name = parts[2].to_string();
                // We only care about main devices, not partitions (usually)
                if (name.starts_with("sd") || name.starts_with("nvme")) && !name.chars().last().unwrap().is_ascii_digit() || (name.starts_with("nvme") && name.contains('n')) {
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
            
            // Temperatures
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
                    }
                }
            }
        }
    }
    sensors
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
                
                // Resolution and refresh (heuristic)
                let res_part = parts.iter().find(|p| p.contains('x') && p.chars().next().map(|c| c.is_ascii_digit()).unwrap_or(false));
                if let Some(res) = res_part {
                    displays.push(DisplayInfo {
                        name,
                        resolution: res.to_string(),
                        refresh_rate: 60.0, // Default if not found easily
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

        // Simple Single Core (calculating primes for 2 seconds)
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

        // Simple Multi Core (same but with all cores)
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

pub async fn spawn_monitors(tx: Sender<MonitorEvent>) {
    // 1. Static Metadata Task
    let tx_meta = tx.clone();
    tokio::spawn(async move {
        let mut sys = System::new();
        sys.refresh_all();
        let motherboard = read_motherboard_info();
        let cache = read_cpu_cache_info();
        let cpu_flags = read_cpu_flags();
        let cpu_brand = sys.cpus().first().map(|c| c.brand().to_string()).unwrap_or_default();
        let os = format!("{} {}", System::name().unwrap_or_default(), System::os_version().unwrap_or_default());
        let kernel = System::kernel_version().unwrap_or_default();
        let shell = std::env::var("SHELL").unwrap_or_default();
        let de = std::env::var("XDG_CURRENT_DESKTOP").unwrap_or_default();
        let wm = std::env::var("XDG_SESSION_DESKTOP").unwrap_or_default();
        let resolution = detect_resolution_sync();

        let mut last_disk_totals = read_disk_io();
        let mut last_refresh = Instant::now();

        loop {
            let now = Instant::now();
            let elapsed = now.duration_since(last_refresh).as_secs_f64();
            let current_io = read_disk_io();
            
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

                disk_stats.push(DiskStats {
                    name: name.clone(),
                    model,
                    read_kb_s: r_kb,
                    write_kb_s: w_kb,
                    total_read_gb: *r as f64 / 1_073_741_824.0,
                    total_write_gb: *w as f64 / 1_073_741_824.0,
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
            };

            let _ = tx_meta.send(MonitorEvent::MetadataUpdate {
                metadata,
                motherboard: motherboard.clone(),
                cache: cache.clone(),
                cpu_brand: cpu_brand.clone(),
                cpu_flags: cpu_flags.clone(),
                disks,
                disk_stats,
            }).await;
            
            last_disk_totals = current_io;
            last_refresh = now;
            sleep(Duration::from_secs(2)).await;
        }
    });

    // 2. CPU/RAM/Processes Task
    let tx_cpu = tx.clone();
    tokio::spawn(async move {
        let mut sys = System::new_all();
        loop {
            sys.refresh_all();
            let freqs = read_cpu_frequencies();
            let cpus = sys.cpus().iter().enumerate().map(|(i, c)| {
                let freq = freqs.get(i).copied().unwrap_or(0.0);
                (c.name().to_string(), c.cpu_usage(), freq)
            }).collect();
            let global_cpu = sys.global_cpu_info().cpu_usage();
            let _ = tx_cpu.send(MonitorEvent::CpuUpdate { cpus, global: global_cpu }).await;

            let _ = tx_cpu.send(MonitorEvent::MemoryUpdate(
                sys.used_memory(),
                sys.total_memory(),
                sys.used_swap(),
                sys.total_swap(),
            )).await;

            let processes = sys.processes().iter().map(|(pid, proc)| {
                (pid.as_u32(), proc.name().to_string(), proc.cpu_usage(), proc.memory(), format!("{:?}", proc.status()))
            }).collect();
            let _ = tx_cpu.send(MonitorEvent::ProcessUpdate(processes)).await;

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
                            });
                        }
                    }
                }
            }

            // Probe sysfs GPUs
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
                            
                            if vendor == "Intel" {
                                let mut info = GpuInfo {
                                    name: "Intel GPU".to_string(),
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
                                    name: "AMD GPU".to_string(),
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
            
            let mut networks = Vec::new();
            for (name, rx, tx) in current.iter() {
                let prev = last_totals.iter().find(|(n, _, _)| n == name);
                let (rx_s, tx_s) = prev.map(|(_, orx, otx)| {
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
                });
            }

            let _ = tx_net.send(MonitorEvent::NetworkUpdate(networks)).await;
            last_totals = current;
            last_refresh = now;
            sleep(Duration::from_millis(1000)).await;
        }
    });

    // 5. Sensors & Battery Task
    let tx_sens = tx.clone();
    tokio::spawn(async move {
        loop {
            let sensors = read_all_sensors();
            let batteries = read_batteries();
            
            let _ = tx_sens.send(MonitorEvent::SensorsUpdate {
                sensors,
                batteries,
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
            sleep(Duration::from_secs(60)).await; // slow update
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
                peripherals: Vec::new(), // Placeholder
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
