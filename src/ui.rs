use ratatui::{
    layout::{Constraint, Direction, Layout, Rect},
    style::{Color, Modifier, Style},
    widgets::{Block, Borders, Gauge, Paragraph, Row, Sparkline, Table, Tabs, Wrap, LineGauge},
    Frame,
};

use crate::app::{App, CurrentScreen, ProcessSort};
use crate::monitor::SensorCategory;

const BYTES_PER_GB: f64 = 1_073_741_824.0;

pub fn ui(f: &mut Frame, app: &App) {
    let size = f.size();
    let chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(3), Constraint::Min(0), Constraint::Length(1)])
        .split(size);

    let header_chunks = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(70), Constraint::Percentage(30)])
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
            .block(Block::default().title(" TeuPC Professional | Monitor de Sistema ").borders(Borders::ALL))
            .select(index)
            .style(Style::default().fg(Color::Gray))
            .highlight_style(Style::default().add_modifier(Modifier::BOLD).fg(Color::Cyan)),
        header_chunks[0],
    );

    let status = if app.paused { "[PAUSADO]" } else { "[AO VIVO]" };
    let info_str = if let Some(meta) = &app.metadata {
        format!(" {} | {} | {} ", status, meta.os, meta.uptime)
    } else {
        format!(" {} | INICIALIZANDO... ", status)
    };
    f.render_widget(
        Paragraph::new(info_str)
        .block(Block::default().borders(Borders::ALL))
        .alignment(ratatui::layout::Alignment::Right),
        header_chunks[1],
    );

    match app.current_screen {
        CurrentScreen::Overview => render_overview(f, app, chunks[1]),
        CurrentScreen::Processes => render_processes(f, app, chunks[1]),
        CurrentScreen::SystemInfo => render_hardware_z(f, app, chunks[1]),
        CurrentScreen::Network => render_network(f, app, chunks[1]),
        CurrentScreen::Sensors => render_sensors(f, app, chunks[1]),
        CurrentScreen::Devices => render_devices(f, app, chunks[1]),
        CurrentScreen::Benchmarks => render_benchmarks(f, app, chunks[1]),
        CurrentScreen::Help => render_help(f, chunks[1]),
    }

    let footer = " q: sair | s: snapshot | b: benchmark | Tab: trocar tela";
    f.render_widget(Paragraph::new(footer).style(Style::default().fg(Color::DarkGray)), chunks[2]);
}

fn render_overview(f: &mut Frame, app: &App, area: Rect) {
    let main_layout = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([
            Constraint::Percentage(25), // CPU Cores
            Constraint::Min(0),          // Main Gauges & History
            Constraint::Percentage(25), // Telemetry
        ])
        .split(area);

    // --- LEFT COLUMN: CORE VITALS ---
    let core_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints(app.cpus.iter().map(|_| Constraint::Length(1)).collect::<Vec<_>>())
        .split(main_layout[0]);
    
    for (i, (name, usage, freq)) in app.cpus.iter().enumerate() {
        if i < core_chunks.len() {
            let color = if *usage > 80.0 { Color::Red } else if *usage > 50.0 { Color::Yellow } else { Color::Green };
            f.render_widget(
                LineGauge::default()
                    .block(Block::default().title(format!(" {} ({:.1}GHz)", name, freq / 1000.0)).title_style(Style::default().fg(Color::DarkGray)))
                    .gauge_style(Style::default().fg(color))
                    .ratio(*usage as f64 / 100.0),
                core_chunks[i],
            );
        }
    }

    // --- CENTER COLUMN: PERFORMANCE HUB ---
    let center_layout = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(8), // Global Gauges
            Constraint::Min(0),    // CPU History
            Constraint::Length(10), // IO Activity
        ])
        .split(main_layout[1]);

    let gauge_row = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(50), Constraint::Percentage(50)])
        .split(center_layout[0]);

    // CPU Global
    f.render_widget(
        Gauge::default()
            .block(Block::default().title(" [ CARGA CPU GLOBAL ] ").borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Cyan))
            .percent(percent_from_f32(app.global_cpu))
            .label(format!("{:.1}%", app.global_cpu)),
        gauge_row[0],
    );

    // RAM Global
    let mem_percent = if app.mem_total == 0 { 0 } else { percent_from_f64(app.mem_used as f64 / app.mem_total as f64 * 100.0) };
    f.render_widget(
        Gauge::default()
            .block(Block::default().title(" [ MEMORIA RAM ] ").borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Yellow))
            .percent(mem_percent)
            .label(format!("{:.1}/{:.1} GB", app.mem_used as f64 / BYTES_PER_GB, app.mem_total as f64 / BYTES_PER_GB)),
        gauge_row[1],
    );

    // Main History
    let history: Vec<u64> = app.cpu_history.iter().copied().collect();
    f.render_widget(
        Sparkline::default()
            .block(Block::default().title(" [ HISTORICO DE CARGA CPU ] ").borders(Borders::LEFT | Borders::RIGHT))
            .style(Style::default().fg(Color::Cyan))
            .data(&history),
        center_layout[1],
    );

    // IO Activity
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
            .block(Block::default().title(" [ ATIVIDADE DE REDE ] ").borders(Borders::ALL))
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
            .block(Block::default().title(" [ ATIVIDADE DE DISCO ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Yellow))
            .data(&disk_io_pulse),
        heartbeat_row[1],
    );

    // --- RIGHT COLUMN: TELEMETRY ---
    let right_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(8), // Thermals
            Constraint::Length(8), // Power
            Constraint::Min(0),    // External
        ])
        .split(main_layout[2]);

    let temp_data: Vec<String> = app.sensors.iter()
        .filter(|s| s.category == SensorCategory::Temperature)
        .take(5)
        .map(|s| format!(" {:<15} {:.0}°C", s.label, s.value))
        .collect();
    f.render_widget(
        Paragraph::new(temp_data.join("\n"))
            .block(Block::default().title(" [ TEMPERATURAS ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Red)),
        right_chunks[0],
    );

    let mut power_info = vec![
        format!(" Governor:  {}", app.power.current_governor),
    ];
    if let Some(pl1) = app.power.pl1_w { power_info.push(format!(" PL1 Limit: {:.1}W", pl1)); }
    for volt in app.sensors.iter().filter(|s| s.category == SensorCategory::Voltage).take(3) {
        power_info.push(format!(" {:<10} {:.3}V", volt.label, volt.value));
    }
    f.render_widget(
        Paragraph::new(power_info.join("\n"))
            .block(Block::default().title(" [ ENERGIA / TDP ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Yellow)),
        right_chunks[1],
    );

    let mut cloud_info = vec![
        format!(" Latencia: {}ms", app.latency_ms.unwrap_or(0)),
    ];
    if let Some(ip) = &app.public_ip {
        cloud_info.push(format!(" IP: {}", ip.ip));
        cloud_info.push(format!(" Loc: {}, {}", ip.city, ip.country));
    }
    f.render_widget(
        Paragraph::new(cloud_info.join("\n"))
            .block(Block::default().title(" [ REDE EXTERNA ] ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Blue)),
        right_chunks[2],
    );
}

fn render_processes(f: &mut Frame, app: &App, area: Rect) {
    let mut processes = app.processes.clone();
    match app.process_sort {
        ProcessSort::Cpu => processes.sort_by(|a, b| b.2.partial_cmp(&a.2).unwrap_or(std::cmp::Ordering::Equal)),
        ProcessSort::Memory => processes.sort_by(|a, b| b.3.cmp(&a.3)),
        ProcessSort::Pid => processes.sort_by(|a, b| a.0.cmp(&b.0)),
    }
    let selected_pid = processes.get(app.selected_process).map(|p| p.0);
    let rows: Vec<Row> = processes.iter().take(area.height.saturating_sub(3) as usize).map(|(pid, name, cpu, mem, status)| {
        let style = if Some(*pid) == selected_pid { Style::default().fg(Color::Black).bg(Color::Cyan) } else { Style::default() };
        Row::new(vec![pid.to_string(), name.clone(), status.clone(), format!("{:.1}%", cpu), format!("{} MB", mem / 1024 / 1024)]).style(style)
    }).collect();
    f.render_widget(Table::new(rows, [Constraint::Length(8), Constraint::Min(20), Constraint::Length(10), Constraint::Length(8), Constraint::Length(12)])
        .header(Row::new(vec!["PID", "NOME", "STATUS", "CPU%", "RAM"]).style(Style::default().add_modifier(Modifier::BOLD)))
        .block(Block::default().title(" PROCESSOS DO SISTEMA ").borders(Borders::ALL)), area);
}

fn render_hardware_z(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default().direction(Direction::Horizontal).constraints([Constraint::Percentage(40), Constraint::Percentage(60)]).margin(1).split(area);
    let mut sys_specs = vec![" [DADOS DO SISTEMA]".to_string()];
    if let Some(meta) = &app.metadata {
        sys_specs.push(format!(" OS:    {}", meta.os));
        sys_specs.push(format!(" KERN:  {}", meta.kernel));
    }
    sys_specs.push(format!(" MB:    {}", app.motherboard.name));
    sys_specs.push(format!(" BIOS:  {}", app.motherboard.bios_version));
    
    f.render_widget(Paragraph::new(sys_specs.join("\n")).block(Block::default().title(" DADOS DE HARDWARE ").borders(Borders::ALL)), chunks[0]);

    let right_chunks = Layout::default().direction(Direction::Vertical).constraints([Constraint::Length(10), Constraint::Min(0)]).split(chunks[1]);
    let cpu_specs = format!(" MODELO: {}\n CORES: {}\n CACHE: L1:{} L2:{} L3:{}", app.cpu_brand, app.cpus.len(), app.cpu_cache.l1d, app.cpu_cache.l2, app.cpu_cache.l3);
    f.render_widget(Paragraph::new(cpu_specs).block(Block::default().title(" PROCESSADOR ").borders(Borders::ALL)), right_chunks[0]);

    let mut drives = vec![" [DISPOSITIVOS FISICOS]".to_string()];
    for disk in &app.disk_stats { drives.push(format!(" {}: {} [L:{:.1} KB/s]", disk.name, disk.model, disk.read_kb_s)); }
    f.render_widget(Paragraph::new(drives.join("\n")).block(Block::default().title(" ARMAZENAMENTO ").borders(Borders::ALL)), right_chunks[1]);
}

fn render_network(f: &mut Frame, app: &App, area: Rect) {
    let rows: Vec<Row> = app.networks.iter().map(|n| {
        Row::new(vec![n.name.clone(), n.ipv4.clone(), format!("{:.1}GB", n.rx_total as f64 / BYTES_PER_GB), format_bytes_per_sec(n.rx_per_sec)])
    }).collect();
    f.render_widget(Table::new(rows, [Constraint::Length(10), Constraint::Length(15), Constraint::Length(10), Constraint::Length(15)])
        .header(Row::new(vec!["IFACE", "IP", "TOTAL", "TAXA"]).style(Style::default().add_modifier(Modifier::BOLD)))
        .block(Block::default().title(" INTERFACES DE REDE ").borders(Borders::ALL)), area);
}

fn render_sensors(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default().direction(Direction::Horizontal).constraints([Constraint::Percentage(33), Constraint::Percentage(33), Constraint::Percentage(34)]).margin(1).split(area);
    let temps: Vec<Row> = app.sensors.iter().filter(|s| s.category == SensorCategory::Temperature).map(|s| Row::new(vec![s.label.clone(), format!("{:.1}°C", s.value)])).collect();
    f.render_widget(Table::new(temps, [Constraint::Min(20), Constraint::Length(10)]).block(Block::default().title(" TERMICO ").borders(Borders::ALL)), chunks[0]);
    
    let volts: Vec<Row> = app.sensors.iter().filter(|s| s.category == SensorCategory::Voltage).map(|s| Row::new(vec![s.label.clone(), format!("{:.3}V", s.value)])).collect();
    f.render_widget(Table::new(volts, [Constraint::Min(20), Constraint::Length(10)]).block(Block::default().title(" VOLTAGENS ").borders(Borders::ALL)), chunks[1]);

    let fans: Vec<Row> = app.sensors.iter().filter(|s| s.category == SensorCategory::Fan).map(|s| Row::new(vec![s.label.clone(), format!("{:.0}RPM", s.value)])).collect();
    f.render_widget(Table::new(fans, [Constraint::Min(20), Constraint::Length(10)]).block(Block::default().title(" COOLERS ").borders(Borders::ALL)), chunks[2]);
}

fn render_devices(f: &mut Frame, app: &App, area: Rect) {
    let pci: Vec<Row> = app.pci_devices.iter().map(|d| Row::new(vec![d.slot.clone(), d.vendor.clone(), d.device.clone()])).collect();
    f.render_widget(Table::new(pci, [Constraint::Length(8), Constraint::Percentage(30), Constraint::Percentage(60)]).header(Row::new(vec!["SLOT", "FABRICANTE", "DISPOSITIVO"])).block(Block::default().title(" BARRAMENTO PCI ").borders(Borders::ALL)), area);
}

fn render_benchmarks(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default().direction(Direction::Vertical).constraints([Constraint::Length(3), Constraint::Min(0)]).margin(1).split(area);
    f.render_widget(Paragraph::new(format!(" STATUS: {}", if app.benchmark.is_running { "TESTE EM EXECUCAO..." } else { "PRONTO" })).block(Block::default().title(" CONTROLE DE BENCHMARK ").borders(Borders::ALL)), chunks[0]);
    f.render_widget(LineGauge::default().block(Block::default().title(" PROGRESSO ")).gauge_style(Style::default().fg(Color::Yellow)).ratio(app.benchmark.progress as f64 / 100.0), chunks[1]);
}

fn render_help(f: &mut Frame, area: Rect) {
    let text = ["TEUPC PROFESSIONAL DIAGNOSTIC", "", "1 GERAL      (Painel HUD)", "2 PROCESSOS  (Lista de tarefas)", "3 HARDWARE   (Dados tecnicos)", "4 REDE       (Tráfego e IP)", "5 SENSORES   (Temp/Volt/RPM)", "6 BARRAMENTO (PCI/USB)", "7 BENCHMARK  (Stress Test)", "", "s: snapshot | b: benchmark | q: sair"];
    f.render_widget(Paragraph::new(text.join("\n")).block(Block::default().title(" AJUDA ").borders(Borders::ALL)), area);
}

fn percent_from_f32(value: f32) -> u16 { value.clamp(0.0, 100.0) as u16 }
fn percent_from_f64(value: f64) -> u16 { value.clamp(0.0, 100.0) as u16 }
fn format_bytes_per_sec(bytes: u64) -> String {
    if bytes >= 1_000_000 { format!("{:.1}MB/s", bytes as f64 / 1_000_000.0) }
    else if bytes >= 1_000 { format!("{:.1}KB/s", bytes as f64 / 1_000.0) }
    else { format!("{}B/s", bytes) }
}
