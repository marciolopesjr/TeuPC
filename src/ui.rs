use ratatui::{
    layout::{Constraint, Direction, Layout, Rect},
    style::{Color, Modifier, Style},
    widgets::{Block, Borders, Gauge, LineGauge, Paragraph, Row, Sparkline, Table, Tabs},
    Frame,
};

use crate::app::{App, CurrentScreen, ProcessSort};
use crate::monitor::SensorCategory;

const BYTES_PER_GB: f64 = 1_073_741_824.0;
const BYTES_PER_MB: f64 = 1_048_576.0;

pub fn ui(f: &mut Frame, app: &App) {
    let size = f.size();
    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(3), Constraint::Min(0), Constraint::Length(1)])
        .split(size);

    let header_chunks = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(65), Constraint::Percentage(35)])
        .split(chunks[0]);

    let titles = vec![
        " [1] GERAL ",
        " [2] PROCESSOS ",
        " [3] HARDWARE ",
        " [4] REDE ",
        " [5] SENSORES ",
        " [6] BARRAMENTO ",
        " [7] BENCHMARK ",
        " [?] AJUDA ",
    ];
    let index = match app.current_screen {
        CurrentScreen::Overview => 0,
        CurrentScreen::Processes => 1,
        CurrentScreen::SystemInfo => 2,
        CurrentScreen::Network => 3,
        CurrentScreen::Sensors => 4,
        CurrentScreen::Devices => 5,
        CurrentScreen::Benchmarks => 6,
        CurrentScreen::Help => 7,
    };
    f.render_widget(
        Tabs::new(titles)
            .block(Block::default().title(" ⚡ TeuPC Professional | Monitor & Diagnóstico ").borders(Borders::ALL))
            .select(index)
            .style(Style::default().fg(Color::Gray))
            .highlight_style(Style::default().add_modifier(Modifier::BOLD).fg(Color::Cyan)),
        header_chunks[0],
    );

    let status = if app.paused { "⏸ PAUSADO" } else { "● AO VIVO" };
    let info_str = if let Some(meta) = &app.metadata {
        let virt_str = if meta.virtualization != "Bare Metal" {
            format!(" | 📦 {}", meta.virtualization)
        } else {
            String::new()
        };
        let dock_str = if let Some(c) = meta.docker_containers {
            format!(" | 🐳:{}", c)
        } else {
            String::new()
        };
        format!(" {} | {} | ⏱ {}{}{} ", status, meta.os, meta.uptime, virt_str, dock_str)
    } else {
        format!(" {} | INICIALIZANDO SENSORES... ", status)
    };
    
    let header_block = Block::default().borders(Borders::ALL);
    let header_style = if app.paused {
        Style::default().fg(Color::Yellow)
    } else {
        Style::default().fg(Color::Green)
    };
    f.render_widget(
        Paragraph::new(info_str)
            .block(header_block)
            .style(header_style)
            .alignment(ratatui::layout::Alignment::Right),
        header_chunks[1],
    );

    match app.current_screen {
        CurrentScreen::Overview => render_overview(f, app, chunks[1]),
        CurrentScreen::Processes => render_processes(f, app, chunks[1]),
        CurrentScreen::SystemInfo => render_hardware(f, app, chunks[1]),
        CurrentScreen::Network => render_network(f, app, chunks[1]),
        CurrentScreen::Sensors => render_sensors(f, app, chunks[1]),
        CurrentScreen::Devices => render_devices(f, app, chunks[1]),
        CurrentScreen::Benchmarks => render_benchmarks(f, app, chunks[1]),
        CurrentScreen::Help => render_help(f, chunks[1]),
    }

    let status_bar = if let Some(msg) = &app.status_message {
        format!(" >> {} << | q: sair | s: snapshot | b: bench | [1-7]: abas | Tab: trocar tela", msg)
    } else {
        " q: sair | s: snapshot JSON | b: benchmark | c/m/p/r/w: ordenar processos | Tab: trocar tela | Espaço: pausar".to_string()
    };
    f.render_widget(Paragraph::new(status_bar).style(Style::default().fg(Color::DarkGray)), chunks[2]);
}

fn render_overview(f: &mut Frame, app: &App, area: Rect) {
    let main_layout = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([
            Constraint::Percentage(28), // CPU Cores & Topology
            Constraint::Min(0),          // Main Performance Hub
            Constraint::Percentage(30), // Telemetry, Thermals & Power
        ])
        .split(area);

    // --- LEFT COLUMN: CORE VITALS & TOPOLOGY ---
    let left_layout = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Min(0),
            Constraint::Length(if app.cpu_throttled { 3 } else { 0 }),
        ])
        .split(main_layout[0]);

    let core_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints(app.cpus.iter().map(|_| Constraint::Length(1)).collect::<Vec<_>>())
        .split(left_layout[0]);
    
    for (i, core) in app.cpus.iter().enumerate() {
        if i < core_chunks.len() {
            let color = if core.usage > 80.0 {
                Color::Red
            } else if core.usage > 50.0 {
                Color::Yellow
            } else {
                Color::Green
            };

            let type_tag = match core.core_type.as_str() {
                "P-Core" => "[P]",
                "E-Core" => "[E]",
                _ => "",
            };

            let title = format!(" {} {} {:.1}GHz", core.name, type_tag, core.freq_mhz / 1000.0);
            f.render_widget(
                LineGauge::default()
                    .block(Block::default().title(title).title_style(Style::default().fg(Color::DarkGray)))
                    .gauge_style(Style::default().fg(color))
                    .ratio((core.usage as f64 / 100.0).clamp(0.0, 1.0)),
                core_chunks[i],
            );
        }
    }

    if app.cpu_throttled {
        f.render_widget(
            Paragraph::new(" ⚠ THERMAL THROTTLING ATIVO! ")
                .style(Style::default().fg(Color::Black).bg(Color::Red).add_modifier(Modifier::BOLD))
                .block(Block::default().borders(Borders::ALL)),
            left_layout[1],
        );
    }

    // --- CENTER COLUMN: PERFORMANCE HUB ---
    let center_layout = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(7), // Gauges Row
            Constraint::Min(0),    // CPU History
            Constraint::Length(9), // IO Activity (Net & Disk)
        ])
        .split(main_layout[1]);

    let gauge_row = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(34), Constraint::Percentage(33), Constraint::Percentage(33)])
        .split(center_layout[0]);

    // CPU Global & Load
    let load_title = format!(" CARGA CPU [{:.1}%] ", app.global_cpu);
    let load_sub = format!("Load: {:.2} {:.2} {:.2}", app.load_stats.load_1m, app.load_stats.load_5m, app.load_stats.load_15m);
    f.render_widget(
        Gauge::default()
            .block(Block::default().title(load_title).borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Cyan))
            .percent(percent_from_f32(app.global_cpu))
            .label(load_sub),
        gauge_row[0],
    );

    // RAM Global & ZRAM
    let mem_percent = if app.mem_total == 0 { 0 } else { percent_from_f64(app.mem_used as f64 / app.mem_total as f64 * 100.0) };
    let zram_tag = if let Some(z) = &app.advanced_mem.zram {
        format!(" (ZRAM: {:.1}x)", z.compression_ratio)
    } else {
        String::new()
    };
    let ram_label = format!("{:.1}/{:.1} GB{}", app.mem_used as f64 / BYTES_PER_GB, app.mem_total as f64 / BYTES_PER_GB, zram_tag);
    f.render_widget(
        Gauge::default()
            .block(Block::default().title(" MEMORIA RAM ").borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Yellow))
            .percent(mem_percent)
            .label(ram_label),
        gauge_row[1],
    );

    // SWAP Global
    let swap_percent = if app.swap_total == 0 { 0 } else { percent_from_f64(app.swap_used as f64 / app.swap_total as f64 * 100.0) };
    let swap_label = format!("{:.1}/{:.1} GB", app.swap_used as f64 / BYTES_PER_GB, app.swap_total as f64 / BYTES_PER_GB);
    f.render_widget(
        Gauge::default()
            .block(Block::default().title(" SWAP / VIRTUAL ").borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Magenta))
            .percent(swap_percent)
            .label(swap_label),
        gauge_row[2],
    );

    // Main CPU History Sparkline
    let history: Vec<u64> = app.cpu_history.iter().copied().collect();
    f.render_widget(
        Sparkline::default()
            .block(Block::default().title(" [ HISTORICO DE CARGA CPU - 120s ] ").borders(Borders::LEFT | Borders::RIGHT))
            .style(Style::default().fg(Color::Cyan))
            .data(&history),
        center_layout[1],
    );

    // IO Activity (Net & Disk)
    let heartbeat_row = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(50), Constraint::Percentage(50)])
        .split(center_layout[2]);

    let mut net_rx_pulse = vec![0u64; 120];
    for hist in app.network_history.values() {
        for (i, val) in hist.rx.iter().enumerate() {
            if i < net_rx_pulse.len() { net_rx_pulse[i] += val; }
        }
    }
    f.render_widget(
        Sparkline::default()
            .block(Block::default().title(" [ REDE RX (DOWNLOAD) ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Green))
            .data(&net_rx_pulse),
        heartbeat_row[0],
    );

    let mut disk_io_pulse = vec![0u64; 120];
    for hist in app.disk_history.values() {
        for (i, val) in hist.read.iter().enumerate() {
            if i < disk_io_pulse.len() { disk_io_pulse[i] += val; }
        }
    }
    f.render_widget(
        Sparkline::default()
            .block(Block::default().title(" [ DISCO I/O (LEITURA) ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Yellow))
            .data(&disk_io_pulse),
        heartbeat_row[1],
    );

    // --- RIGHT COLUMN: TELEMETRY, HARDWARE SIGNALS & LOGGED EVENTS ---
    let right_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(6), // Thermals & Fans
            Constraint::Length(6), // Power & RAPL
            Constraint::Length(6), // Network & Wi-Fi Signals
            Constraint::Min(0),    // Recent Hardware Anomalies & Process Spikes (Items 2 & 6)
        ])
        .split(main_layout[2]);

    // Thermals
    let mut temp_lines = Vec::new();
    for s in app.sensors.iter().filter(|s| s.category == SensorCategory::Temperature).take(3) {
        temp_lines.push(format!(" {:<16} {:.0}°C", s.label, s.value));
    }
    for s in app.sensors.iter().filter(|s| s.category == SensorCategory::Fan).take(1) {
        temp_lines.push(format!(" ❄ {:<14} {:.0} RPM", s.label, s.value));
    }
    f.render_widget(
        Paragraph::new(temp_lines.join("\n"))
            .block(Block::default().title(" [ TEMPERATURAS & FANS ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Red)),
        right_chunks[0],
    );

    // Power, Battery & RAPL
    let mut power_lines = vec![
        format!(" Gov: {} ({})", app.power.current_governor, app.cpu_tuning.cpufreq_driver),
    ];
    if let Some(pkg) = app.cpu_tuning.rapl_domains.package_w {
        power_lines.push(format!(" RAPL CPU: {:.1}W (Core: {:.1}W)", pkg, app.cpu_tuning.rapl_domains.core_w.unwrap_or(0.0)));
    } else if let Some(pl1) = app.power.pl1_w {
        power_lines.push(format!(" PL1: {:.1}W", pl1));
    }
    if let Some(bat) = app.batteries.first() {
        let health_color = if bat.health_pct > 80.0 { "🟢" } else { "🟡" };
        power_lines.push(format!(" Bat: {}% [{}] | Saude: {:.1}% {}", bat.capacity, bat.status, bat.health_pct, health_color));
    }
    if let Some(ac) = app.ac_adapters.first() {
        let ac_stat = if ac.online { "🔌 Tomada" } else { "🔋 Bateria" };
        power_lines.push(format!(" AC: {}", ac_stat));
    }
    f.render_widget(
        Paragraph::new(power_lines.join("\n"))
            .block(Block::default().title(" [ ENERGIA & RAPL ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Yellow)),
        right_chunks[1],
    );

    // External & Wi-Fi
    let mut net_lines = vec![
        format!(" Ping: {}ms | IP Ext: {}", app.latency_ms.unwrap_or(0), app.public_ip.as_ref().map(|p| p.ip.as_str()).unwrap_or("-")),
    ];
    if let Some(wifi) = &app.network_advanced.wifi {
        net_lines.push(format!(" Wi-Fi: {} ({}dBm - {}%)", wifi.ssid, wifi.signal_dbm, wifi.signal_quality_pct));
        net_lines.push(format!(" Bitrate: {:.1} Mbps (Canal {})", wifi.bitrate_mbps, wifi.channel));
    }
    if !app.network_advanced.dns_servers.is_empty() {
        net_lines.push(format!(" DNS: {}", app.network_advanced.dns_servers.join(", ")));
    }
    f.render_widget(
        Paragraph::new(net_lines.join("\n"))
            .block(Block::default().title(" [ REDE & CONECTIVIDADE ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Blue)),
        right_chunks[2],
    );

    // Recent Hardware Anomalies & Process Spikes
    let mut event_lines = Vec::new();
    for ev in app.system_events.iter().take(right_chunks[3].height.saturating_sub(2) as usize) {
        let tag = match ev.severity {
            crate::monitor::EventSeverity::Critical => "🔴",
            crate::monitor::EventSeverity::Warning => "🟡",
            crate::monitor::EventSeverity::Info => "🔵",
        };
        let time_str = ev.timestamp.split_whitespace().nth(1).unwrap_or("");
        event_lines.push(format!(" {} [{}] {}", tag, time_str, ev.title));
    }
    if event_lines.is_empty() {
        event_lines.push(" Nenhum alerta anômalo registrado no momento.".to_string());
    }
    f.render_widget(
        Paragraph::new(event_lines.join("\n"))
            .block(Block::default().title(" [ LOG DE ANOMALIAS & SPIKES ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::White)),
        right_chunks[3],
    );
}

fn render_processes(f: &mut Frame, app: &App, area: Rect) {
    let mut processes = app.processes.clone();
    match app.process_sort {
        ProcessSort::Cpu => processes.sort_by(|a, b| b.cpu_usage.partial_cmp(&a.cpu_usage).unwrap_or(std::cmp::Ordering::Equal)),
        ProcessSort::Memory => processes.sort_by(|a, b| b.mem_bytes.cmp(&a.mem_bytes)),
        ProcessSort::Pid => processes.sort_by(|a, b| a.pid.cmp(&b.pid)),
        ProcessSort::DiskRead => processes.sort_by(|a, b| b.read_kb_s.partial_cmp(&a.read_kb_s).unwrap_or(std::cmp::Ordering::Equal)),
        ProcessSort::DiskWrite => processes.sort_by(|a, b| b.write_kb_s.partial_cmp(&a.write_kb_s).unwrap_or(std::cmp::Ordering::Equal)),
    }
    
    let sort_label = match app.process_sort {
        ProcessSort::Cpu => "[ORDEM: CPU%]",
        ProcessSort::Memory => "[ORDEM: MEMORIA]",
        ProcessSort::Pid => "[ORDEM: PID]",
        ProcessSort::DiskRead => "[ORDEM: LEITURA DISCO]",
        ProcessSort::DiskWrite => "[ORDEM: ESCRITA DISCO]",
    };

    let selected_pid = processes.get(app.selected_process).map(|p| p.pid);
    let rows: Vec<Row> = processes.iter().take(area.height.saturating_sub(4) as usize).map(|p| {
        let style = if Some(p.pid) == selected_pid {
            Style::default().fg(Color::Black).bg(Color::Cyan).add_modifier(Modifier::BOLD)
        } else {
            Style::default()
        };
        Row::new(vec![
            p.pid.to_string(),
            p.user.clone(),
            p.name.clone(),
            p.threads.to_string(),
            p.status.clone(),
            format!("{:.1}%", p.cpu_usage),
            format!("{:.1} MB", p.mem_bytes as f64 / BYTES_PER_MB),
            format!("{:.1} KB/s", p.read_kb_s),
            format!("{:.1} KB/s", p.write_kb_s),
        ]).style(style)
    }).collect();

    f.render_widget(
        Table::new(rows, [
            Constraint::Length(8),
            Constraint::Length(10),
            Constraint::Min(20),
            Constraint::Length(8),
            Constraint::Length(10),
            Constraint::Length(8),
            Constraint::Length(12),
            Constraint::Length(14),
            Constraint::Length(14),
        ])
        .header(Row::new(vec!["PID", "USUÁRIO", "NOME", "THREADS", "STATUS", "CPU%", "RAM", "LEITURA", "ESCRITA"])
            .style(Style::default().add_modifier(Modifier::BOLD).fg(Color::Yellow)))
        .block(Block::default().title(format!(" PROCESSOS DO SISTEMA {} (Total: {}) | c: CPU | m: RAM | p: PID | r: Read | w: Write ", sort_label, app.processes.len())).borders(Borders::ALL)),
        area,
    );
}

fn render_hardware(f: &mut Frame, app: &App, area: Rect) {
    let main_chunks = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(50), Constraint::Percentage(50)])
        .split(area);

    // Left column: Motherboard/Firmware, CPU & Vulnerabilities
    let left_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(9), Constraint::Length(9), Constraint::Min(0)])
        .split(main_chunks[0]);

    // Motherboard, BIOS & Firmware Security
    let mut mb_info = vec![
        format!(" Placa-Mãe:  {} ({})", app.motherboard.name, app.motherboard.vendor),
        format!(" BIOS:        {} ({}) | Boot: {}", app.motherboard.bios_version, app.motherboard.bios_date, app.firmware.boot_mode),
        format!(" Segurança:   Secure Boot: {} | {}", app.firmware.secure_boot, app.firmware.tpm_version),
        format!(" Gabinete:    {} ({})", app.firmware.chassis_type, app.firmware.chassis_vendor),
    ];
    if let Some(meta) = &app.metadata {
        mb_info.push(format!(" Sistema:     {} (Kernel: {})", meta.os, meta.kernel));
        mb_info.push(format!(" Sessão:      {} ({}) | Virt: {}", meta.session_type, meta.wm, meta.virtualization));
    }
    f.render_widget(
        Paragraph::new(mb_info.join("\n")).block(Block::default().title(" [ PLACA-MÃE, BIOS & FIRMWARE ] ").borders(Borders::ALL)),
        left_chunks[0],
    );

    // Processor, Driver & Cache
    let turbo_str = if app.cpu_tuning.turbo_boost_enabled { "Ativo" } else { "Desativado" };
    let cpu_info = format!(
        " Modelo:    {}\n Cores:     {} threads | Microcode: {} | Turbo: {}\n Driver:     {} | Govs: {}\n Caches:    L1d: {} | L1i: {} | L2: {} | L3: {}\n Flags:     {}",
        app.cpu_brand,
        app.cpus.len(),
        app.load_stats.microcode,
        turbo_str,
        app.cpu_tuning.cpufreq_driver,
        app.cpu_tuning.available_governors.join(", "),
        app.cpu_cache.l1d,
        app.cpu_cache.l1i,
        app.cpu_cache.l2,
        app.cpu_cache.l3,
        app.cpu_flags.iter().take(10).cloned().collect::<Vec<_>>().join(" ")
    );
    f.render_widget(
        Paragraph::new(cpu_info).block(Block::default().title(" [ PROCESSADOR & ARQUITETURA ] ").borders(Borders::ALL)),
        left_chunks[1],
    );

    // Vulnerabilities & Mitigations
    let vuln_rows: Vec<Row> = app.cpu_vulnerabilities.iter().take(left_chunks[2].height.saturating_sub(3) as usize).map(|v| {
        let tag = if v.is_mitigated { "[MITIGADO]" } else { "[VULNERÁVEL]" };
        let tag_style = if v.is_mitigated { Style::default().fg(Color::Green) } else { Style::default().fg(Color::Red) };
        Row::new(vec![v.name.clone(), tag.to_string(), v.status.clone()]).style(tag_style).bottom_margin(0)
    }).collect();
    f.render_widget(
        Table::new(vuln_rows, [Constraint::Length(16), Constraint::Length(14), Constraint::Min(20)])
            .header(Row::new(vec!["VULNERABILIDADE", "ESTADO", "DETALHE DO KERNEL"]).style(Style::default().add_modifier(Modifier::BOLD)))
            .block(Block::default().title(" [ SEGURANÇA DA CPU (MITIGAÇÕES) ] ").borders(Borders::ALL)),
        left_chunks[2],
    );

    // Right column: Memory, Audio & Storage Low-Level
    let right_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(7), Constraint::Length(6), Constraint::Min(0)])
        .split(main_chunks[1]);

    // Memory Details (DMI Slots + Meminfo + ZRAM)
    let mut mem_details = Vec::new();
    if !app.advanced_mem.slots.is_empty() {
        for s in &app.advanced_mem.slots {
            mem_details.push(format!(" {}: {:.0}GB {} @ {} MT/s ({})", s.locator, s.capacity_gb, s.mem_type, s.speed_mts, s.manufacturer));
        }
    } else {
        mem_details.push(format!(" RAM Total: {:.1} GB | Usado: {:.1} GB", app.mem_total as f64 / BYTES_PER_GB, app.mem_used as f64 / BYTES_PER_GB));
    }
    let mi = &app.advanced_mem.meminfo;
    mem_details.push(format!(" Cached: {:.1}GB | Buffers: {:.1}MB | Dirty: {:.1}MB | Slab: {:.1}MB",
        mi.cached as f64 / BYTES_PER_GB, mi.buffers as f64 / BYTES_PER_MB, mi.dirty as f64 / BYTES_PER_MB, mi.slab as f64 / BYTES_PER_MB));
    if let Some(z) = &app.advanced_mem.zram {
        mem_details.push(format!(" ZRAM ({}) - Orig: {:.1}GB -> Compr: {:.1}GB ({:.2}x)", z.algorithm, z.orig_data_size as f64 / BYTES_PER_GB, z.compr_data_size as f64 / BYTES_PER_GB, z.compression_ratio));
    }
    f.render_widget(
        Paragraph::new(mem_details.join("\n")).block(Block::default().title(" [ SUB-SISTEMA DE MEMÓRIA & ZRAM ] ").borders(Borders::ALL)),
        right_chunks[0],
    );

    // Audio Subsystem & Codecs
    let mut audio_lines = vec![
        format!(" Servidor de Áudio: {}", app.audio.audio_server),
    ];
    for card in &app.audio.cards {
        let codecs_str = if card.codecs.is_empty() { String::new() } else { format!(" [Codec: {}]", card.codecs.join(", ")) };
        audio_lines.push(format!(" 🔊 Card {}: {} ({}){}", card.id, card.name, card.driver, codecs_str));
    }
    f.render_widget(
        Paragraph::new(audio_lines.join("\n")).block(Block::default().title(" [ SUB-SISTEMA DE ÁUDIO & CODECS ] ").borders(Borders::ALL)),
        right_chunks[1],
    );

    // Storage Low-Level & Filesystems
    let mut storage_rows: Vec<Row> = Vec::new();
    for d in &app.disk_stats {
        let rot_str = if d.is_rotational { "HDD" } else { "SSD" };
        let trim_str = if d.trim_supported { "TRIM: Sim" } else { "TRIM: Não" };
        storage_rows.push(Row::new(vec![
            d.name.clone(),
            format!("{} ({})", d.model, rot_str),
            d.scheduler.clone(),
            trim_str.to_string(),
            d.sector_size.clone(),
            format!("{:.1} GB", d.total_read_gb),
        ]).style(Style::default().fg(Color::Yellow)));
    }
    for fs in &app.filesystems {
        let used_pct = if fs.total_bytes > 0 { ((fs.total_bytes - fs.available_bytes) as f64 / fs.total_bytes as f64) * 100.0 } else { 0.0 };
        storage_rows.push(Row::new(vec![
            fs.mount_point.clone(),
            fs.fs_type.clone(),
            format!("{:.1} GB", fs.total_bytes as f64 / BYTES_PER_GB),
            format!("{:.1}%", used_pct),
            format!("{:.1}%", if fs.inodes_total > 0 { ((fs.inodes_total - fs.inodes_free) as f64 / fs.inodes_total as f64) * 100.0 } else { 0.0 }),
            "-".to_string(),
        ]));
    }
    f.render_widget(
        Table::new(storage_rows, [
            Constraint::Length(10),
            Constraint::Min(18),
            Constraint::Length(10),
            Constraint::Length(10),
            Constraint::Length(12),
            Constraint::Length(10),
        ])
        .header(Row::new(vec!["DISCO/PONTO", "MODELO/TIPO", "SCHEDULER", "TRIM/USO", "SETOR/INODE", "TOTAL LIDO"]).style(Style::default().add_modifier(Modifier::BOLD)))
        .block(Block::default().title(" [ CONTROLADORES DE DISCO, SCHEDULER & FILE SYSTEMS ] ").borders(Borders::ALL)),
        right_chunks[2],
    );
}

fn render_network(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Percentage(45), Constraint::Percentage(25), Constraint::Percentage(30)])
        .margin(1)
        .split(area);

    // Local interfaces table
    let rows: Vec<Row> = app.networks.iter().map(|n| {
        let speed_str = n.speed_mbps.map(|s| format!("{} Mbps", s)).unwrap_or_else(|| "-".to_string());
        Row::new(vec![
            n.name.clone(),
            n.iface_type.clone(),
            n.ipv4.clone(),
            n.mac.clone(),
            speed_str,
            format_bytes_per_sec(n.rx_per_sec),
            format_bytes_per_sec(n.tx_per_sec),
            format!("{}/{}", n.rx_errors, n.tx_errors),
            format!("{}/{}", n.rx_dropped, n.tx_dropped),
        ])
    }).collect();
    f.render_widget(
        Table::new(rows, [
            Constraint::Length(10),
            Constraint::Length(14),
            Constraint::Length(15),
            Constraint::Length(18),
            Constraint::Length(10),
            Constraint::Length(12),
            Constraint::Length(12),
            Constraint::Length(10),
            Constraint::Length(10),
        ])
        .header(Row::new(vec!["IFACE", "TIPO", "IP LOCAL", "MAC", "LINK", "TAXA RX", "TAXA TX", "ERROS", "DROPS"]).style(Style::default().add_modifier(Modifier::BOLD).fg(Color::Yellow)))
        .block(Block::default().title(" [ INTERFACES DE REDE LOCAIS ] ").borders(Borders::ALL)),
        chunks[0],
    );

    // Wi-Fi Telemetry & Gateway
    let adv = &app.network_advanced;
    let dns_str = if adv.dns_servers.is_empty() { "-".to_string() } else { adv.dns_servers.join(", ") };
    let mut wifi_lines = Vec::new();
    if let Some(wifi) = &adv.wifi {
        wifi_lines.push(format!(" Wi-Fi SSID: {} (Canal {} | {:.3} GHz) | Sinal: {} dBm ({}%) | Bitrate: {:.1} Mbps", wifi.ssid, wifi.channel, wifi.frequency_ghz, wifi.signal_dbm, wifi.signal_quality_pct, wifi.bitrate_mbps));
    }
    wifi_lines.push(format!(" Gateway Padrão: {} | Servidores DNS: {}", if adv.default_gateway.is_empty() { "-" } else { &adv.default_gateway }, dns_str));
    wifi_lines.push(format!(" Sockets TCP: {} Estabelecidas | {} TIME_WAIT | {} LISTEN | RTT/Ping: {} ms", adv.tcp_established, adv.tcp_time_wait, adv.tcp_listen, app.latency_ms.unwrap_or(0)));
    f.render_widget(
        Paragraph::new(wifi_lines.join("\n")).block(Block::default().title(" [ TELEMETRIA WI-FI & ROTEAMENTO ] ").borders(Borders::ALL)),
        chunks[1],
    );

    // Listening ports and ARP table
    let split_bot = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(60), Constraint::Percentage(40)])
        .split(chunks[2]);

    let port_rows: Vec<Row> = adv.listening_ports.iter().take(split_bot[0].height.saturating_sub(3) as usize).map(|p| {
        Row::new(vec![p.protocol.clone(), p.local_address.clone(), p.state.clone()])
    }).collect();
    f.render_widget(
        Table::new(port_rows, [Constraint::Length(8), Constraint::Min(20), Constraint::Length(10)])
            .header(Row::new(vec!["PROTO", "ENDEREÇO LOCAL (PORTA)", "ESTADO"]).style(Style::default().add_modifier(Modifier::BOLD)))
            .block(Block::default().title(" [ PORTAS EM ESCUTA (LISTEN) ] ").borders(Borders::ALL)),
        split_bot[0],
    );

    let arp_rows: Vec<Row> = adv.arp_entries.iter().take(split_bot[1].height.saturating_sub(3) as usize).map(|a| {
        Row::new(vec![a.ip_address.clone(), a.mac_address.clone(), a.interface.clone()])
    }).collect();
    f.render_widget(
        Table::new(arp_rows, [Constraint::Length(15), Constraint::Length(18), Constraint::Length(8)])
            .header(Row::new(vec!["IP VIZINHO", "MAC ADDRESS", "IFACE"]).style(Style::default().add_modifier(Modifier::BOLD)))
            .block(Block::default().title(" [ TABELA ARP / VIZINHOS ] ").borders(Borders::ALL)),
        split_bot[1],
    );
}

fn render_sensors(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(33), Constraint::Percentage(33), Constraint::Percentage(34)])
        .margin(1)
        .split(area);

    let temps: Vec<Row> = app.sensors.iter().filter(|s| s.category == SensorCategory::Temperature).map(|s| {
        let color = if s.value > 80.0 { Color::Red } else if s.value > 60.0 { Color::Yellow } else { Color::Green };
        Row::new(vec![s.label.clone(), format!("{:.1}°C", s.value)]).style(Style::default().fg(color))
    }).collect();
    f.render_widget(Table::new(temps, [Constraint::Min(20), Constraint::Length(10)]).header(Row::new(vec!["SENSOR", "TEMPERATURA"])).block(Block::default().title(" [ TÉRMICO ] ").borders(Borders::ALL)), chunks[0]);
    
    let volts: Vec<Row> = app.sensors.iter().filter(|s| s.category == SensorCategory::Voltage).map(|s| {
        Row::new(vec![s.label.clone(), format!("{:.3}V", s.value)])
    }).collect();
    f.render_widget(Table::new(volts, [Constraint::Min(20), Constraint::Length(10)]).header(Row::new(vec!["TRILHA", "VOLTAGEM"])).block(Block::default().title(" [ VOLTAGENS ] ").borders(Borders::ALL)), chunks[1]);

    let mut fan_and_periph: Vec<Row> = app.sensors.iter().filter(|s| s.category == SensorCategory::Fan).map(|s| {
        Row::new(vec![s.label.clone(), format!("{:.0} RPM", s.value)])
    }).collect();
    for p in &app.peripherals {
        let bat = p.battery.map(|b| format!("{}%", b)).unwrap_or_else(|| "Conectado".to_string());
        fan_and_periph.push(Row::new(vec![format!("🔋 {} ({})", p.name, p.device_type), bat]).style(Style::default().fg(Color::Cyan)));
    }
    f.render_widget(Table::new(fan_and_periph, [Constraint::Min(20), Constraint::Length(12)]).header(Row::new(vec!["COOLER / PERIFÉRICO", "VALOR"])).block(Block::default().title(" [ COOLERS & PERIFÉRICOS ] ").borders(Borders::ALL)), chunks[2]);
}

fn render_devices(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Percentage(40), Constraint::Percentage(30), Constraint::Percentage(30)])
        .margin(1)
        .split(area);

    let pci: Vec<Row> = app.pci_devices.iter().map(|d| {
        let link = match (&d.link_speed, &d.link_width) {
            (Some(s), Some(w)) => format!("{} {}", s, w),
            _ => "-".to_string(),
        };
        Row::new(vec![d.slot.clone(), d.vendor.clone(), d.device.clone(), link])
    }).collect();
    f.render_widget(
        Table::new(pci, [
            Constraint::Length(10),
            Constraint::Percentage(25),
            Constraint::Percentage(45),
            Constraint::Percentage(20),
        ])
        .header(Row::new(vec!["SLOT", "FABRICANTE", "DISPOSITIVO", "PCIe LINK"]).style(Style::default().add_modifier(Modifier::BOLD)))
        .block(Block::default().title(" [ BARRAMENTO PCI / PCIe ] ").borders(Borders::ALL)),
        chunks[0],
    );

    let bottom_split = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(50), Constraint::Percentage(50)])
        .split(chunks[1]);

    let usb: Vec<Row> = app.usb_devices.iter().map(|u| {
        Row::new(vec![u.id.clone(), u.name.clone()])
    }).collect();
    f.render_widget(
        Table::new(usb, [Constraint::Length(12), Constraint::Min(20)])
            .header(Row::new(vec!["ID USB", "DISPOSITIVO"]).style(Style::default().add_modifier(Modifier::BOLD)))
            .block(Block::default().title(" [ DISPOSITIVOS USB CONECTADOS ] ").borders(Borders::ALL)),
        bottom_split[0],
    );

    let input_rows: Vec<Row> = app.input_devices.iter().map(|inp| {
        Row::new(vec![inp.bustype.clone(), inp.name.clone(), inp.handler.clone()])
    }).collect();
    f.render_widget(
        Table::new(input_rows, [Constraint::Length(12), Constraint::Min(20), Constraint::Length(12)])
            .header(Row::new(vec!["BARRAMENTO", "DISPOSITIVO DE ENTRADA (HID)", "HANDLER"]).style(Style::default().add_modifier(Modifier::BOLD)))
            .block(Block::default().title(" [ TECLADOS, MOUSES & TOUCHPADS ] ").borders(Borders::ALL)),
        bottom_split[1],
    );

    let displays: Vec<Row> = app.displays.iter().map(|d| {
        let prim = if d.is_primary { "Sim ★" } else { "Não" };
        Row::new(vec![d.name.clone(), d.resolution.clone(), format!("{:.1} Hz", d.refresh_rate), prim.to_string()])
    }).collect();
    f.render_widget(
        Table::new(displays, [Constraint::Length(15), Constraint::Length(15), Constraint::Length(12), Constraint::Length(10)])
            .header(Row::new(vec!["DISPLAY", "RESOLUÇÃO", "TAXA", "PRIMÁRIO"]).style(Style::default().add_modifier(Modifier::BOLD)))
            .block(Block::default().title(" [ MONITORES & TELAS (DISPLAYS) ] ").borders(Borders::ALL)),
        chunks[2],
    );
}

fn render_benchmarks(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(4), Constraint::Length(4), Constraint::Min(0)])
        .margin(1)
        .split(area);

    let status_txt = if app.benchmark.is_running {
        "🔥 TESTE DE STRESS EM EXECUÇÃO... (Aguarde)"
    } else {
        "⚡ PRONTO PARA INICIAR (Pressione 'b' para rodar o Benchmark de CPU)"
    };
    f.render_widget(
        Paragraph::new(status_txt)
            .style(Style::default().add_modifier(Modifier::BOLD).fg(if app.benchmark.is_running { Color::Yellow } else { Color::Green }))
            .block(Block::default().title(" [ CONTROLE DE BENCHMARK ] ").borders(Borders::ALL)),
        chunks[0],
    );

    f.render_widget(
        LineGauge::default()
            .block(Block::default().title(format!(" PROGRESSO: {}% ", app.benchmark.progress)))
            .gauge_style(Style::default().fg(Color::Cyan))
            .ratio((app.benchmark.progress as f64 / 100.0).clamp(0.0, 1.0)),
        chunks[1],
    );

    let mut results = vec![
        "─── RESULTADOS DO BENCHMARK DE CPU ───".to_string(),
        "".to_string(),
        format!(" Pontuação Single-Core:  {} primos/2s", app.benchmark.single_core),
        format!(" Pontuação Multi-Core:   {} primos/2s ({} threads)", app.benchmark.multi_core, app.cpus.len()),
    ];
    if app.benchmark.single_core > 0 && app.benchmark.multi_core > 0 {
        let multi_ratio = app.benchmark.multi_core as f64 / app.benchmark.single_core as f64;
        results.push(format!(" Eficiência Multi-Thread: {:.2}x de escalabilidade", multi_ratio));
    }
    f.render_widget(
        Paragraph::new(results.join("\n")).block(Block::default().title(" [ PONTUAÇÃO & THROUGHPUT ] ").borders(Borders::ALL)),
        chunks[2],
    );
}

fn render_help(f: &mut Frame, area: Rect) {
    let text = [
        "╔═══════════════════════════════════════════════════════════════════════════╗",
        "║              ⚡ TEUPC PROFESSIONAL - GUIA DE COMANDOS & TELAS             ║",
        "╚═══════════════════════════════════════════════════════════════════════════╝",
        "",
        " [ NAVEGAÇÃO ENTRE TELAS ]",
        "  1 ou Tab/Setas : [1] GERAL     - HUD de alta densidade (Cores, Histórico, TDP, Thermals)",
        "  2              : [2] PROCESSOS - Lista detalhada de tarefas, PID, Usuário, I/O e RAM",
        "  3              : [3] HARDWARE  - Diagnóstico profundo (Placa-mãe, DMI RAM, NVMe, GPUs)",
        "  4              : [4] REDE      - Interfaces, Wi-Fi (dBm/Mbps), DNS, Sockets TCP, Gateway",
        "  5              : [5] SENSORES  - Temperaturas, Voltagens, Coolers RPM, Baterias Wireless",
        "  6              : [6] BARRAMENTO- Varredura de slots PCI/PCIe, Dispositivos USB e Displays",
        "  7              : [7] BENCHMARK - Stress test de CPU Single-Core e Multi-Core",
        "  ? ou h         : [?] AJUDA     - Este painel de ajuda e documentação de teclas",
        "",
        " [ CONTROLES & AÇÕES ]",
        "  s              : Exportar snapshot instantâneo do sistema em formato JSON técnico",
        "  b              : Iniciar teste de benchmark e stress do processador",
        "  Espaço         : Pausar / Retomar atualização ao vivo da telemetria",
        "  c / m / p      : Ordenar processos por CPU%, Memória ou PID",
        "  r / w          : Ordenar processos por taxa de Leitura ou Escrita no Disco",
        "  j / k ou Setas : Selecionar processos na tabela da tela de processos",
        "  q              : Sair do TeuPC",
    ];
    f.render_widget(
        Paragraph::new(text.join("\n"))
            .style(Style::default().fg(Color::White))
            .block(Block::default().title(" [ AJUDA & ATALHOS ] ").borders(Borders::ALL)),
        area,
    );
}

fn percent_from_f32(value: f32) -> u16 { value.clamp(0.0, 100.0) as u16 }
fn percent_from_f64(value: f64) -> u16 { value.clamp(0.0, 100.0) as u16 }
fn format_bytes_per_sec(bytes: u64) -> String {
    if bytes >= 1_000_000_000 {
        format!("{:.1} GB/s", bytes as f64 / 1_000_000_000.0)
    } else if bytes >= 1_000_000 {
        format!("{:.1} MB/s", bytes as f64 / 1_000_000.0)
    } else if bytes >= 1_000 {
        format!("{:.1} KB/s", bytes as f64 / 1_000.0)
    } else {
        format!("{} B/s", bytes)
    }
}
