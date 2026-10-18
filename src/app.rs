use crate::monitor::{
    AcAdapterInfo, AcpiSleepDetails, AdvancedMemoryInfo, AudioSubsystem, BatteryInfo,
    BenchmarkResult, CpuCacheInfo, CpuCoreDetail, CpuTuningDetails, CpuVulnerability,
    DiskStats, DisplayInfo, FileSystemInfo, FirmwareDetails, GpuInfo, InputDeviceInfo,
    KernelDiagnostics, MonitorEvent, MotherboardInfo, NetworkAdvancedStats, NetworkInfo,
    NvmeHealth, PciDevice, PeripheralDevice, PowerLimits, ProcessDetail, PublicIpInfo,
    SensorDetail, SoftwareRaidInfo, SystemLoadStats, SystemLogEvent, SystemMetadata, UsbDevice,
};
use serde::Serialize;
use std::collections::{HashMap, VecDeque};
use std::fs;
use tokio::sync::mpsc::Sender;

const HISTORY_LIMIT: usize = 120;
const EVENTS_LIMIT: usize = 100;

#[derive(Clone, Copy, PartialEq)]
pub enum CurrentScreen {
    Overview,
    Processes,
    SystemInfo,
    Network,
    Sensors,
    Devices,
    Benchmarks,
    Help,
}

#[derive(Clone, Copy, PartialEq)]
pub enum ProcessSort {
    Cpu,
    Memory,
    Pid,
    DiskRead,
    DiskWrite,
}

#[derive(Serialize)]
pub struct AppSnapshot {
    pub metadata: Option<SystemMetadata>,
    pub motherboard: MotherboardInfo,
    pub firmware: FirmwareDetails,
    pub cpu_brand: String,
    pub cpu_flags: Vec<String>,
    pub cpu_cache: CpuCacheInfo,
    pub cpu_vulnerabilities: Vec<CpuVulnerability>,
    pub cpu_tuning: CpuTuningDetails,
    pub load_stats: SystemLoadStats,
    pub mem_total: u64,
    pub mem_advanced: AdvancedMemoryInfo,
    pub gpus: Vec<GpuInfo>,
    pub disks: Vec<DiskStats>,
    pub software_raids: Vec<SoftwareRaidInfo>,
    pub filesystems: Vec<FileSystemInfo>,
    pub nvme_health: Vec<NvmeHealth>,
    pub audio: AudioSubsystem,
    pub kernel_diagnostics: KernelDiagnostics,
    pub input_devices: Vec<InputDeviceInfo>,
    pub acpi_sleep: AcpiSleepDetails,
    pub networks: Vec<NetworkInfo>,
    pub network_advanced: NetworkAdvancedStats,
    pub pci: Vec<PciDevice>,
    pub usb: Vec<UsbDevice>,
    pub batteries: Vec<BatteryInfo>,
    pub displays: Vec<DisplayInfo>,
    pub public_ip: Option<PublicIpInfo>,
    pub power_limits: PowerLimits,
    pub peripherals: Vec<PeripheralDevice>,
    pub logged_events: Vec<SystemLogEvent>,
}

pub struct App {
    pub current_screen: CurrentScreen,
    pub should_quit: bool,
    pub paused: bool,
    pub process_sort: ProcessSort,
    pub selected_process: usize,
    pub cpu_history: VecDeque<u64>,
    pub network_history: HashMap<String, NetworkHistory>,
    pub disk_history: HashMap<String, DiskHistory>,

    // System Data
    pub cpus: Vec<CpuCoreDetail>,
    pub global_cpu: f32,
    pub cpu_throttled: bool,
    pub load_stats: SystemLoadStats,
    pub cpu_tuning: CpuTuningDetails,
    pub cpu_flags: Vec<String>,
    pub cpu_vulnerabilities: Vec<CpuVulnerability>,
    pub mem_used: u64,
    pub mem_total: u64,
    pub swap_used: u64,
    pub swap_total: u64,
    pub advanced_mem: AdvancedMemoryInfo,
    pub gpus: Vec<GpuInfo>,
    pub networks: Vec<NetworkInfo>,
    pub network_advanced: NetworkAdvancedStats,
    pub processes: Vec<ProcessDetail>,
    pub sensors: Vec<SensorDetail>,
    pub batteries: Vec<BatteryInfo>,
    pub ac_adapters: Vec<AcAdapterInfo>,
    pub peripherals: Vec<PeripheralDevice>,
    pub motherboard: MotherboardInfo,
    pub firmware: FirmwareDetails,
    pub cpu_cache: CpuCacheInfo,
    pub cpu_brand: String,
    pub metadata: Option<SystemMetadata>,
    pub disks: Vec<(String, u64, u64)>,
    pub disk_stats: Vec<DiskStats>,
    pub software_raids: Vec<SoftwareRaidInfo>,
    pub filesystems: Vec<FileSystemInfo>,
    pub nvme_health: Vec<NvmeHealth>,
    pub audio: AudioSubsystem,
    pub kernel_diag: KernelDiagnostics,
    pub input_devices: Vec<InputDeviceInfo>,
    pub sleep_details: AcpiSleepDetails,
    pub pci_devices: Vec<PciDevice>,
    pub usb_devices: Vec<UsbDevice>,

    // Event Logging & Anomalies
    pub system_events: VecDeque<SystemLogEvent>,

    // Extreme Data
    pub displays: Vec<DisplayInfo>,
    pub public_ip: Option<PublicIpInfo>,
    pub latency_ms: Option<u64>,
    pub power: PowerLimits,
    pub benchmark: BenchmarkResult,

    // Channels & Status
    pub monitor_tx: Option<Sender<MonitorEvent>>,
    pub status_message: Option<String>,
}

pub struct NetworkHistory {
    pub rx: VecDeque<u64>,
    pub tx: VecDeque<u64>,
}

pub struct DiskHistory {
    pub read: VecDeque<u64>,
    pub write: VecDeque<u64>,
}

impl App {
    pub fn new() -> Self {
        Self {
            current_screen: CurrentScreen::Overview,
            should_quit: false,
            paused: false,
            process_sort: ProcessSort::Cpu,
            selected_process: 0,
            cpu_history: VecDeque::with_capacity(HISTORY_LIMIT),
            network_history: HashMap::new(),
            disk_history: HashMap::new(),
            cpus: Vec::new(),
            global_cpu: 0.0,
            cpu_throttled: false,
            load_stats: SystemLoadStats::default(),
            cpu_tuning: CpuTuningDetails::default(),
            cpu_flags: Vec::new(),
            cpu_vulnerabilities: Vec::new(),
            mem_used: 0,
            mem_total: 0,
            swap_used: 0,
            swap_total: 0,
            advanced_mem: AdvancedMemoryInfo::default(),
            gpus: Vec::new(),
            networks: Vec::new(),
            network_advanced: NetworkAdvancedStats::default(),
            processes: Vec::new(),
            sensors: Vec::new(),
            batteries: Vec::new(),
            ac_adapters: Vec::new(),
            peripherals: Vec::new(),
            motherboard: MotherboardInfo::default(),
            firmware: FirmwareDetails::default(),
            cpu_cache: CpuCacheInfo::default(),
            cpu_brand: String::new(),
            metadata: None,
            disks: Vec::new(),
            disk_stats: Vec::new(),
            software_raids: Vec::new(),
            filesystems: Vec::new(),
            nvme_health: Vec::new(),
            audio: AudioSubsystem::default(),
            kernel_diag: KernelDiagnostics::default(),
            input_devices: Vec::new(),
            sleep_details: AcpiSleepDetails::default(),
            pci_devices: Vec::new(),
            usb_devices: Vec::new(),
            system_events: VecDeque::with_capacity(EVENTS_LIMIT),
            displays: Vec::new(),
            public_ip: None,
            latency_ms: None,
            power: PowerLimits::default(),
            benchmark: BenchmarkResult::default(),
            monitor_tx: None,
            status_message: None,
        }
    }

    pub fn on_monitor_event(&mut self, event: MonitorEvent) {
        if self.paused {
            match event {
                MonitorEvent::MetadataUpdate { .. }
                | MonitorEvent::BusUpdate { .. }
                | MonitorEvent::BenchmarkUpdate(..)
                | MonitorEvent::ExtremeUpdate { .. }
                | MonitorEvent::SystemEvent(..) => {}
                _ => return,
            }
        }

        match event {
            MonitorEvent::CpuUpdate {
                cpus,
                global,
                load_stats,
                throttled,
                cpu_tuning,
            } => {
                self.cpus = cpus;
                self.global_cpu = global;
                self.load_stats = load_stats;
                self.cpu_throttled = throttled;
                self.cpu_tuning = cpu_tuning;
                self.cpu_history.push_back(global.clamp(0.0, 100.0) as u64);
                if self.cpu_history.len() > HISTORY_LIMIT {
                    self.cpu_history.pop_front();
                }
            }
            MonitorEvent::MemoryUpdate { used, total, s_used, s_total, advanced } => {
                self.mem_used = used;
                self.mem_total = total;
                self.swap_used = s_used;
                self.swap_total = s_total;
                self.advanced_mem = advanced;
            }
            MonitorEvent::GpuUpdate(gpus) => {
                self.gpus = gpus;
            }
            MonitorEvent::NetworkUpdate { networks, advanced } => {
                self.networks = networks;
                self.network_advanced = advanced;
                self.update_network_history();
            }
            MonitorEvent::ProcessUpdate(processes) => {
                self.processes = processes;
            }
            MonitorEvent::SensorsUpdate { sensors, batteries, ac_adapters, peripherals } => {
                self.sensors = sensors;
                self.batteries = batteries;
                self.ac_adapters = ac_adapters;
                self.peripherals = peripherals;
            }
            MonitorEvent::MetadataUpdate {
                metadata,
                motherboard,
                firmware,
                cache,
                cpu_brand,
                cpu_flags,
                disks,
                disk_stats,
                software_raids,
                filesystems,
                nvme_health,
                vulnerabilities,
                audio,
                kernel_diag,
                input_devices,
                sleep_details,
            } => {
                self.metadata = Some(metadata);
                self.motherboard = motherboard;
                self.firmware = firmware;
                self.cpu_cache = cache;
                self.cpu_brand = cpu_brand;
                self.cpu_flags = cpu_flags;
                self.disks = disks;
                self.disk_stats = disk_stats;
                self.software_raids = software_raids;
                self.filesystems = filesystems;
                self.nvme_health = nvme_health;
                self.cpu_vulnerabilities = vulnerabilities;
                self.audio = audio;
                self.kernel_diag = kernel_diag;
                self.input_devices = input_devices;
                self.sleep_details = sleep_details;
                self.update_disk_history();
            }
            MonitorEvent::BusUpdate { pci, usb } => {
                self.pci_devices = pci;
                self.usb_devices = usb;
            }
            MonitorEvent::ExtremeUpdate {
                displays,
                public_ip,
                latency_ms,
                power,
            } => {
                self.displays = displays;
                self.public_ip = public_ip;
                self.latency_ms = latency_ms;
                self.power = power;
            }
            MonitorEvent::BenchmarkUpdate(result) => {
                self.benchmark = result;
            }
            MonitorEvent::SystemEvent(log_event) => {
                let alert_msg = format!("⚠ [{:?}] {}", log_event.category, log_event.title);
                self.status_message = Some(alert_msg);
                self.system_events.push_front(log_event);
                if self.system_events.len() > EVENTS_LIMIT {
                    self.system_events.pop_back();
                }
            }
        }
    }

    pub fn start_benchmark(&self) {
        if let Some(tx) = &self.monitor_tx {
            if !self.benchmark.is_running {
                crate::monitor::run_cpu_benchmark(tx.clone());
            }
        }
    }

    pub fn save_snapshot(&mut self) -> String {
        let snapshot = AppSnapshot {
            metadata: self.metadata.clone(),
            motherboard: self.motherboard.clone(),
            firmware: self.firmware.clone(),
            cpu_brand: self.cpu_brand.clone(),
            cpu_flags: self.cpu_flags.clone(),
            cpu_cache: self.cpu_cache.clone(),
            cpu_vulnerabilities: self.cpu_vulnerabilities.clone(),
            cpu_tuning: self.cpu_tuning.clone(),
            load_stats: self.load_stats.clone(),
            mem_total: self.mem_total,
            mem_advanced: self.advanced_mem.clone(),
            gpus: self.gpus.clone(),
            disks: self.disk_stats.clone(),
            software_raids: self.software_raids.clone(),
            filesystems: self.filesystems.clone(),
            nvme_health: self.nvme_health.clone(),
            audio: self.audio.clone(),
            kernel_diagnostics: self.kernel_diag.clone(),
            input_devices: self.input_devices.clone(),
            acpi_sleep: self.sleep_details.clone(),
            networks: self.networks.clone(),
            network_advanced: self.network_advanced.clone(),
            pci: self.pci_devices.clone(),
            usb: self.usb_devices.clone(),
            batteries: self.batteries.clone(),
            displays: self.displays.clone(),
            public_ip: self.public_ip.clone(),
            power_limits: self.power.clone(),
            peripherals: self.peripherals.clone(),
            logged_events: self.system_events.iter().cloned().collect(),
        };

        match serde_json::to_string_pretty(&snapshot) {
            Ok(json) => {
                let filename = format!(
                    "teupc_snapshot_{}.json",
                    chrono::Local::now().format("%Y%m%d_%H%M%S")
                );
                if fs::write(&filename, json).is_ok() {
                    let msg = format!("Snapshot salvo: {}", filename);
                    self.status_message = Some(msg.clone());
                    msg
                } else {
                    let msg = "Erro ao salvar snapshot".to_string();
                    self.status_message = Some(msg.clone());
                    msg
                }
            }
            Err(_) => {
                let msg = "Erro ao serializar snapshot".to_string();
                self.status_message = Some(msg.clone());
                msg
            }
        }
    }

    pub fn next_screen(&mut self) {
        self.current_screen = match self.current_screen {
            CurrentScreen::Overview => CurrentScreen::Processes,
            CurrentScreen::Processes => CurrentScreen::SystemInfo,
            CurrentScreen::SystemInfo => CurrentScreen::Network,
            CurrentScreen::Network => CurrentScreen::Sensors,
            CurrentScreen::Sensors => CurrentScreen::Devices,
            CurrentScreen::Devices => CurrentScreen::Benchmarks,
            CurrentScreen::Benchmarks => CurrentScreen::Help,
            CurrentScreen::Help => CurrentScreen::Overview,
        };
    }

    pub fn previous_screen(&mut self) {
        self.current_screen = match self.current_screen {
            CurrentScreen::Overview => CurrentScreen::Help,
            CurrentScreen::Processes => CurrentScreen::Overview,
            CurrentScreen::SystemInfo => CurrentScreen::Processes,
            CurrentScreen::Network => CurrentScreen::SystemInfo,
            CurrentScreen::Sensors => CurrentScreen::Network,
            CurrentScreen::Devices => CurrentScreen::Sensors,
            CurrentScreen::Benchmarks => CurrentScreen::Devices,
            CurrentScreen::Help => CurrentScreen::Benchmarks,
        };
    }

    pub fn select_next_process(&mut self) {
        let len = self.processes.len();
        if len > 0 {
            self.selected_process = (self.selected_process + 1).min(len - 1);
        }
    }

    pub fn select_previous_process(&mut self) {
        self.selected_process = self.selected_process.saturating_sub(1);
    }

    pub fn set_process_sort(&mut self, sort: ProcessSort) {
        self.process_sort = sort;
        self.selected_process = 0;
    }

    fn update_network_history(&mut self) {
        for network in &self.networks {
            let history = self
                .network_history
                .entry(network.name.clone())
                .or_insert_with(|| NetworkHistory {
                    rx: VecDeque::with_capacity(HISTORY_LIMIT),
                    tx: VecDeque::with_capacity(HISTORY_LIMIT),
                });

            history.rx.push_back(network.rx_per_sec);
            history.tx.push_back(network.tx_per_sec);
            if history.rx.len() > HISTORY_LIMIT {
                history.rx.pop_front();
            }
            if history.tx.len() > HISTORY_LIMIT {
                history.tx.pop_front();
            }
        }

        self.network_history
            .retain(|name, _| self.networks.iter().any(|n| n.name == *name));
    }

    fn update_disk_history(&mut self) {
        for disk in &self.disk_stats {
            let history = self
                .disk_history
                .entry(disk.name.clone())
                .or_insert_with(|| DiskHistory {
                    read: VecDeque::with_capacity(HISTORY_LIMIT),
                    write: VecDeque::with_capacity(HISTORY_LIMIT),
                });

            history.read.push_back(disk.read_kb_s as u64);
            history.write.push_back(disk.write_kb_s as u64);
            if history.read.len() > HISTORY_LIMIT {
                history.read.pop_front();
            }
            if history.write.len() > HISTORY_LIMIT {
                history.write.pop_front();
            }
        }

        self.disk_history
            .retain(|name, _| self.disk_stats.iter().any(|d| d.name == *name));
    }
}
