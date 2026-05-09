use crate::monitor::{
    BatteryInfo, BenchmarkResult, CpuCacheInfo, DiskStats, DisplayInfo, GpuInfo, MonitorEvent,
    MotherboardInfo, NetworkInfo, PciDevice, PowerLimits, PublicIpInfo, SensorDetail,
    SystemMetadata, UsbDevice,
};
use serde::Serialize;
use std::collections::{HashMap, VecDeque};
use std::fs;
use tokio::sync::mpsc::Sender;

const HISTORY_LIMIT: usize = 120;

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

#[derive(Clone, Copy)]
pub enum ProcessSort {
    Cpu,
    Memory,
    Pid,
}

#[derive(Serialize)]
pub struct AppSnapshot {
    pub metadata: Option<SystemMetadata>,
    pub motherboard: MotherboardInfo,
    pub cpu_brand: String,
    pub cpu_flags: Vec<String>,
    pub mem_total: u64,
    pub gpus: Vec<GpuInfo>,
    pub disks: Vec<DiskStats>,
    pub pci: Vec<PciDevice>,
    pub usb: Vec<UsbDevice>,
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
    pub cpus: Vec<(String, f32, f32)>, // name, usage, freq
    pub global_cpu: f32,
    pub cpu_flags: Vec<String>,
    pub mem_used: u64,
    pub mem_total: u64,
    pub swap_used: u64,
    pub swap_total: u64,
    pub gpus: Vec<GpuInfo>,
    pub networks: Vec<NetworkInfo>,
    pub processes: Vec<(u32, String, f32, u64, String)>,
    pub sensors: Vec<SensorDetail>,
    pub batteries: Vec<BatteryInfo>,
    pub motherboard: MotherboardInfo,
    pub cpu_cache: CpuCacheInfo,
    pub cpu_brand: String,
    pub metadata: Option<SystemMetadata>,
    pub disks: Vec<(String, u64, u64)>,
    pub disk_stats: Vec<DiskStats>,
    pub pci_devices: Vec<PciDevice>,
    pub usb_devices: Vec<UsbDevice>,

    // Extreme Data
    pub displays: Vec<DisplayInfo>,
    pub public_ip: Option<PublicIpInfo>,
    pub latency_ms: Option<u64>,
    pub power: PowerLimits,
    pub benchmark: BenchmarkResult,

    // Channels
    pub monitor_tx: Option<Sender<MonitorEvent>>,
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
            cpu_flags: Vec::new(),
            mem_used: 0,
            mem_total: 0,
            swap_used: 0,
            swap_total: 0,
            gpus: Vec::new(),
            networks: Vec::new(),
            processes: Vec::new(),
            sensors: Vec::new(),
            batteries: Vec::new(),
            motherboard: MotherboardInfo::default(),
            cpu_cache: CpuCacheInfo::default(),
            cpu_brand: String::new(),
            metadata: None,
            disks: Vec::new(),
            disk_stats: Vec::new(),
            pci_devices: Vec::new(),
            usb_devices: Vec::new(),
            displays: Vec::new(),
            public_ip: None,
            latency_ms: None,
            power: PowerLimits::default(),
            benchmark: BenchmarkResult::default(),
            monitor_tx: None,
        }
    }

    pub fn on_monitor_event(&mut self, event: MonitorEvent) {
        if self.paused {
            match event {
                MonitorEvent::MetadataUpdate { .. }
                | MonitorEvent::BusUpdate { .. }
                | MonitorEvent::BenchmarkUpdate(..)
                | MonitorEvent::ExtremeUpdate { .. } => {}
                _ => return,
            }
        }

        match event {
            MonitorEvent::CpuUpdate { cpus, global } => {
                self.cpus = cpus;
                self.global_cpu = global;
                self.cpu_history.push_back(global.clamp(0.0, 100.0) as u64);
                if self.cpu_history.len() > HISTORY_LIMIT {
                    self.cpu_history.pop_front();
                }
            }
            MonitorEvent::MemoryUpdate(used, total, s_used, s_total) => {
                self.mem_used = used;
                self.mem_total = total;
                self.swap_used = s_used;
                self.swap_total = s_total;
            }
            MonitorEvent::GpuUpdate(gpus) => {
                self.gpus = gpus;
            }
            MonitorEvent::NetworkUpdate(networks) => {
                self.networks = networks;
                self.update_network_history();
            }
            MonitorEvent::ProcessUpdate(processes) => {
                self.processes = processes;
            }
            MonitorEvent::SensorsUpdate { sensors, batteries } => {
                self.sensors = sensors;
                self.batteries = batteries;
            }
            MonitorEvent::MetadataUpdate {
                metadata,
                motherboard,
                cache,
                cpu_brand,
                cpu_flags,
                disks,
                disk_stats,
            } => {
                self.metadata = Some(metadata);
                self.motherboard = motherboard;
                self.cpu_cache = cache;
                self.cpu_brand = cpu_brand;
                self.cpu_flags = cpu_flags;
                self.disks = disks;
                self.disk_stats = disk_stats;
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
                ..
            } => {
                self.displays = displays;
                self.public_ip = public_ip;
                self.latency_ms = latency_ms;
                self.power = power;
            }
            MonitorEvent::BenchmarkUpdate(result) => {
                self.benchmark = result;
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

    pub fn save_snapshot(&self) -> String {
        let snapshot = AppSnapshot {
            metadata: self.metadata.clone(),
            motherboard: self.motherboard.clone(),
            cpu_brand: self.cpu_brand.clone(),
            cpu_flags: self.cpu_flags.clone(),
            mem_total: self.mem_total,
            gpus: self.gpus.clone(),
            disks: self.disk_stats.clone(),
            pci: self.pci_devices.clone(),
            usb: self.usb_devices.clone(),
        };

        match serde_json::to_string_pretty(&snapshot) {
            Ok(json) => {
                let filename = format!(
                    "teupc_snapshot_{}.json",
                    chrono::Local::now().format("%Y%m%d_%H%M%S")
                );
                if fs::write(&filename, json).is_ok() {
                    format!("Salvo em {}", filename)
                } else {
                    "Erro ao salvar arquivo".to_string()
                }
            }
            Err(_) => "Erro ao gerar JSON".to_string(),
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
