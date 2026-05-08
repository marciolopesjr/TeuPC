use nvml_wrapper::Nvml;
use serde::Deserialize;
use std::fs;
use std::path::{Path, PathBuf};
use std::process::Command;
use sysinfo::{Disks, Networks, System};

#[derive(Deserialize, Debug, Default)]
pub struct IntelGpuTop {
    pub engines: Option<IntelEngines>,
}

#[derive(Deserialize, Debug, Default)]
pub struct IntelEngines {
    #[serde(rename = "Render/3D/0")]
    pub render: Option<IntelEngineUsage>,
    #[serde(rename = "Video/0")]
    pub video: Option<IntelEngineUsage>,
    #[serde(rename = "Blitter/0")]
    pub blitter: Option<IntelEngineUsage>,
}

#[derive(Deserialize, Debug, Default)]
pub struct IntelEngineUsage {
    pub busy: f32,
}

pub struct GpuInfo {
    pub name: String,
    pub vendor: String,
    pub usage: u32,
    pub mem_used: u64,
    pub mem_total: u64,
    pub temp: Option<u32>,
    pub driver: String,
    pub clock_mhz: Option<u32>,
    pub power_w: Option<u32>,
    pub fan_speed: Option<u32>,
    pub intel_details: Option<IntelEngines>,
}

pub struct SystemMetadata {
    pub os: String,
    pub kernel: String,
    pub uptime: String,
    pub shell: String,
    pub resolution: String,
    pub de: String,
    pub wm: String,
}

pub struct Monitor {
    sys: System,
    networks: Networks,
    nvml: Option<Nvml>,
    gpus: Vec<GpuInfo>,
}

impl Monitor {
    pub fn new() -> Self {
        let mut sys = System::new_all();
        sys.refresh_all();
        let networks = Networks::new_with_refreshed_list();
        let nvml = Nvml::init().ok();
        let mut monitor = Self {
            sys,
            networks,
            nvml,
            gpus: Vec::new(),
        };
        monitor.refresh_gpus();
        monitor
    }

    pub fn refresh(&mut self) {
        self.sys.refresh_all();
        self.networks.refresh_list();
        self.networks.refresh();
        self.refresh_gpus();
    }

    pub fn get_metadata(&self) -> SystemMetadata {
        let uptime_secs = System::uptime();
        let uptime = format!("{}h {}m", uptime_secs / 3600, (uptime_secs % 3600) / 60);
        SystemMetadata {
            os: format!(
                "{} {}",
                System::name().unwrap_or_default(),
                System::os_version().unwrap_or_default()
            ),
            kernel: System::kernel_version().unwrap_or_else(|| "Unknown".to_string()),
            uptime,
            shell: std::env::var("SHELL").unwrap_or_else(|_| "Unknown".to_string()),
            resolution: self.detect_resolution(),
            de: std::env::var("XDG_CURRENT_DESKTOP").unwrap_or_else(|_| "Unknown".to_string()),
            wm: std::env::var("XDG_SESSION_DESKTOP").unwrap_or_else(|_| "Unknown".to_string()),
        }
    }

    fn detect_resolution(&self) -> String {
        if let Ok(output) = Command::new("xrandr").arg("--current").output() {
            let out_str = String::from_utf8_lossy(&output.stdout);
            if let Some(line) = out_str.lines().find(|l| l.contains('*')) {
                return line.split_whitespace().next().unwrap_or("Unknown").to_string();
            }
        }
        "Unknown".to_string()
    }

    pub fn get_gpus(&self) -> &[GpuInfo] {
        &self.gpus
    }

    fn refresh_gpus(&mut self) {
        self.gpus = self.probe_gpus();
    }

    fn probe_gpus(&self) -> Vec<GpuInfo> {
        let mut gpus = Vec::new();

        if let Some(nvml) = &self.nvml {
            if let Ok(count) = nvml.device_count() {
                let driver = nvml.sys_driver_version().unwrap_or_else(|_| "Unknown".to_string());
                for i in 0..count {
                    if let Ok(device) = nvml.device_by_index(i) {
                        let name = device.name().unwrap_or_default();
                        let usage = device.utilization_rates().map(|u| u.gpu).unwrap_or(0);
                        let mem = device
                            .memory_info()
                            .map(|m| (m.used, m.total))
                            .unwrap_or((0, 0));
                        let temp = device
                            .temperature(nvml_wrapper::enum_wrappers::device::TemperatureSensor::Gpu)
                            .ok();
                        let clock = device
                            .clock_info(nvml_wrapper::enum_wrappers::device::Clock::Graphics)
                            .ok();
                        let power = device.power_usage().ok().map(|p| p / 1000);
                        let fan = device.fan_speed(0).ok();
                        gpus.push(GpuInfo {
                            name,
                            vendor: "NVIDIA".to_string(),
                            usage: usage.min(100),
                            mem_used: mem.0,
                            mem_total: mem.1,
                            temp,
                            driver: driver.clone(),
                            clock_mhz: clock,
                            power_w: power,
                            fan_speed: fan,
                            intel_details: None,
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
                    if let Some(gpu) = self.probe_sysfs_gpu_detailed(&path) {
                        if !gpus
                            .iter()
                            .any(|g| g.name.contains(&gpu.name) || gpu.name.contains(&g.name))
                        {
                            gpus.push(gpu);
                        }
                    }
                }
            }
        }
        gpus
    }

    fn probe_sysfs_gpu_detailed(&self, path: &Path) -> Option<GpuInfo> {
        let device_path = path.join("device");
        if !device_path.exists() {
            return None;
        }
        let vendor_id = fs::read_to_string(device_path.join("vendor"))
            .ok()?
            .trim()
            .to_string();
        let vendor = match vendor_id.as_str() {
            "0x1002" => "AMD",
            "0x8086" => "Intel",
            _ => "Generic",
        };

        let mut name = format!("{} GPU", vendor);
        if let Ok(output) = Command::new("lspci").output() {
            let out_str = String::from_utf8_lossy(&output.stdout);
            for line in out_str.lines() {
                if (line.contains("VGA") || line.contains("Display") || line.contains("3D"))
                    && line.to_lowercase().contains(&vendor.to_lowercase())
                {
                    if let Some(pretty_name) = line.split(':').nth(2) {
                        name = pretty_name.trim().to_string();
                        break;
                    }
                }
            }
        }

        let mut info = GpuInfo {
            name,
            vendor: vendor.to_string(),
            usage: 0,
            mem_used: 0,
            mem_total: 0,
            temp: None,
            driver: "Kernel DRM".to_string(),
            clock_mhz: None,
            power_w: None,
            fan_speed: None,
            intel_details: None,
        };

        if vendor == "AMD" {
            info.usage = read_sysfs_u32(device_path.join("gpu_busy_percent"))
                .unwrap_or(0)
                .min(100);
            info.mem_used = read_sysfs_u64(device_path.join("mem_info_vram_used")).unwrap_or(0);
            info.mem_total = read_sysfs_u64(device_path.join("mem_info_vram_total")).unwrap_or(0);
            if let Ok(hwmon) = fs::read_dir(device_path.join("hwmon")) {
                for h in hwmon.flatten() {
                    let h_path = h.path();
                    if info.temp.is_none() {
                        info.temp = read_sysfs_u32(h_path.join("temp1_input")).map(|v| v / 1000);
                    }
                    if info.fan_speed.is_none() {
                        info.fan_speed = read_sysfs_u32(h_path.join("fan1_input"));
                    }
                }
            }
        } else if vendor == "Intel" {
            if let Some(details) = read_intel_gpu_top() {
                info.intel_details = details.engines;
                info.usage = info
                    .intel_details
                    .as_ref()
                    .and_then(|e| e.render.as_ref())
                    .map(|r| r.busy.clamp(0.0, 100.0) as u32)
                    .unwrap_or(0);
            }
        }
        Some(info)
    }

    pub fn get_global_cpu_usage(&self) -> f32 {
        self.sys.global_cpu_info().cpu_usage()
    }

    pub fn get_all_cpus_usage(&self) -> Vec<(String, f32)> {
        self.sys
            .cpus()
            .iter()
            .map(|c| (c.name().to_string(), c.cpu_usage()))
            .collect()
    }

    pub fn get_cpu_brand(&self) -> String {
        self.sys
            .cpus()
            .first()
            .map(|c| c.brand().to_string())
            .unwrap_or_default()
    }

    pub fn get_memory_info(&self) -> (u64, u64, u64, u64) {
        (
            self.sys.used_memory(),
            self.sys.total_memory(),
            self.sys.used_swap(),
            self.sys.total_swap(),
        )
    }

    pub fn get_uptime(&self) -> u64 {
        System::uptime()
    }

    pub fn get_hostname(&self) -> String {
        System::host_name().unwrap_or_default()
    }

    pub fn get_processes(&self) -> Vec<(u32, String, f32, u64, String)> {
        self.sys
            .processes()
            .iter()
            .map(|(pid, proc)| {
                (
                    pid.as_u32(),
                    proc.name().to_string(),
                    proc.cpu_usage(),
                    proc.memory(),
                    format!("{:?}", proc.status()),
                )
            })
            .collect()
    }

    pub fn get_disks_info(&self) -> Vec<(String, u64, u64)> {
        Disks::new_with_refreshed_list()
            .iter()
            .map(|d| {
                (
                    d.mount_point().to_string_lossy().to_string(),
                    d.available_space(),
                    d.total_space(),
                )
            })
            .collect()
    }

    pub fn get_networks_info(&self) -> Vec<(String, u64, u64)> {
        self.networks
            .iter()
            .map(|(n, d)| (n.clone(), d.received(), d.transmitted()))
            .collect()
    }
}

fn read_intel_gpu_top() -> Option<IntelGpuTop> {
    let output = Command::new("intel_gpu_top")
        .arg("-J")
        .arg("-s")
        .arg("100")
        .arg("-n")
        .arg("1")
        .output()
        .ok()?;

    if !output.status.success() {
        return None;
    }

    let parsed = serde_json::from_slice::<serde_json::Value>(&output.stdout).ok()?;
    let first = parsed.as_array()?.first()?.clone();
    serde_json::from_value::<IntelGpuTop>(first).ok()
}

fn read_sysfs_u32(path: PathBuf) -> Option<u32> {
    fs::read_to_string(path).ok()?.trim().parse().ok()
}

fn read_sysfs_u64(path: PathBuf) -> Option<u64> {
    fs::read_to_string(path).ok()?.trim().parse().ok()
}
