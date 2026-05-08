use ratatui::{
    layout::{Constraint, Direction, Layout, Rect},
    style::{Color, Modifier, Style},
    widgets::{Block, Borders, Gauge, Paragraph, Row, Sparkline, Table, Tabs, Wrap},
    Frame,
};

use crate::app::{App, CurrentScreen, ProcessSort};

const BYTES_PER_GB: f64 = 1_073_741_824.0;
const BYTES_PER_MB: f64 = 1_000_000.0;

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
        "[1] Geral",
        "[2] Processos",
        "[3] Hardware-Z",
        "[4] Rede",
        "[?] Ajuda",
    ];
    let index = match app.current_screen {
        CurrentScreen::Overview => 0,
        CurrentScreen::Processes => 1,
        CurrentScreen::SystemInfo => 2,
        CurrentScreen::Network => 3,
        CurrentScreen::Help => 4,
    };
    f.render_widget(
        Tabs::new(titles)
            .block(Block::default().title(" TeuPC Professional ").borders(Borders::ALL))
            .select(index)
            .style(Style::default().fg(Color::Cyan))
            .highlight_style(Style::default().add_modifier(Modifier::BOLD).bg(Color::DarkGray)),
        header_chunks[0],
    );

    let hostname = app.monitor.get_hostname();
    let pause_status = if app.paused { "PAUSADO" } else { "LIVE" };
    f.render_widget(
        Paragraph::new(format!(
            " {} | Host: {} | Up: {}h ",
            pause_status,
            hostname,
            app.monitor.get_uptime() / 3600
        ))
        .block(Block::default().borders(Borders::ALL))
        .alignment(ratatui::layout::Alignment::Right),
        header_chunks[1],
    );

    match app.current_screen {
        CurrentScreen::Overview => render_overview(f, app, chunks[1]),
        CurrentScreen::Processes => render_processes(f, app, chunks[1]),
        CurrentScreen::SystemInfo => render_hardware_z(f, app, chunks[1]),
        CurrentScreen::Network => render_network(f, app, chunks[1]),
        CurrentScreen::Help => render_help(f, chunks[1]),
    }

    let footer = " q sair | Tab/setas trocar tela | espaco pausar | ? ajuda";
    f.render_widget(Paragraph::new(footer), chunks[2]);
}

fn render_overview(f: &mut Frame, app: &App, area: Rect) {
    let outer_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(5), Constraint::Min(0)])
        .margin(1)
        .split(area);

    render_summary(f, app, outer_chunks[0]);

    let chunks = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(30), Constraint::Percentage(70)])
        .split(outer_chunks[1]);

    let cpus = app.monitor.get_all_cpus_usage();
    let core_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints(cpus.iter().map(|_| Constraint::Length(1)).collect::<Vec<_>>())
        .split(chunks[0]);
    for (i, (name, usage)) in cpus.iter().enumerate() {
        if i < core_chunks.len() {
            let percent = percent_from_f32(*usage);
            f.render_widget(
                Gauge::default()
                    .block(Block::default().title(format!(" {} ", name)))
                    .gauge_style(Style::default().fg(if percent > 80 {
                        Color::Red
                    } else {
                        Color::Green
                    }))
                    .percent(percent)
                    .label(format!("{:.0}%", usage)),
                core_chunks[i],
            );
        }
    }

    let right_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([
            Constraint::Length(3),
            Constraint::Length(3),
            Constraint::Min(0),
            Constraint::Length(5),
        ])
        .split(chunks[1]);

    f.render_widget(
        Gauge::default()
            .block(Block::default().title(" CPU Global ").borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Green))
            .percent(percent_from_f32(app.monitor.get_global_cpu_usage())),
        right_chunks[0],
    );

    let (used_mem, total_mem, _, _) = app.monitor.get_memory_info();
    let mem_percent = if total_mem == 0 {
        0
    } else {
        percent_from_f64(used_mem as f64 / total_mem as f64 * 100.0)
    };
    f.render_widget(
        Gauge::default()
            .block(Block::default().title(" RAM Usage ").borders(Borders::ALL))
            .gauge_style(Style::default().fg(Color::Yellow))
            .percent(mem_percent)
            .label(format!(
                "{:.1}/{:.1} GB",
                used_mem as f64 / BYTES_PER_GB,
                total_mem as f64 / BYTES_PER_GB
            )),
        right_chunks[1],
    );

    let gpus = app.monitor.get_gpus();
    if !gpus.is_empty() {
        let gpu_constraints = gpus.iter().map(|_| Constraint::Length(4)).collect::<Vec<_>>();
        let gpu_chunks = Layout::default()
            .direction(Direction::Vertical)
            .constraints(gpu_constraints)
            .split(right_chunks[2]);
        for (i, gpu) in gpus.iter().enumerate() {
            if i < gpu_chunks.len() {
                let color = match gpu.vendor.as_str() {
                    "NVIDIA" => Color::Green,
                    "AMD" => Color::Red,
                    _ => Color::Blue,
                };
                let usage = gpu.usage.unwrap_or(0).min(100);
                let usage_label = gpu
                    .usage
                    .map(|value| format!("{}%", value.min(100)))
                    .unwrap_or_else(|| "uso indisponivel".to_string());
                let info = format!(
                    "{} | VRAM: {:.1}/{:.1} GB{}",
                    usage_label,
                    gpu.mem_used as f64 / BYTES_PER_GB,
                    gpu.mem_total as f64 / BYTES_PER_GB,
                    gpu.temp.map(|t| format!(" | {}°C", t)).unwrap_or_default()
                );
                f.render_widget(
                    Gauge::default()
                        .block(
                            Block::default()
                                .title(format!(" {} ", gpu.name))
                                .borders(Borders::ALL),
                        )
                        .gauge_style(Style::default().fg(color))
                        .percent(usage as u16)
                        .label(info),
                    gpu_chunks[i],
                );
            }
        }
    } else {
        f.render_widget(
            Paragraph::new("Nenhuma GPU dedicada detectada ou driver sem metricas expostas.")
                .block(Block::default().title(" GPU ").borders(Borders::ALL))
                .wrap(Wrap { trim: true }),
            right_chunks[2],
        );
    }

    let cpu_history: Vec<u64> = app.cpu_history.iter().copied().collect();
    f.render_widget(
        Sparkline::default()
            .block(Block::default().title(" CPU History ").borders(Borders::ALL))
            .style(Style::default().fg(Color::Green))
            .data(&cpu_history),
        right_chunks[3],
    );
}

fn render_summary(f: &mut Frame, app: &App, area: Rect) {
    let cards = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([
            Constraint::Percentage(25),
            Constraint::Percentage(25),
            Constraint::Percentage(25),
            Constraint::Percentage(25),
        ])
        .split(area);

    let cpu = percent_from_f32(app.monitor.get_global_cpu_usage());
    let (used_mem, total_mem, _, _) = app.monitor.get_memory_info();
    let mem_percent = if total_mem == 0 {
        0
    } else {
        percent_from_f64(used_mem as f64 / total_mem as f64 * 100.0)
    };
    let process_count = app.monitor.get_processes().len();
    let gpu_count = app.monitor.get_gpus().len();

    let items = [
        ("CPU", format!("{}%", cpu), Color::Green),
        ("RAM", format!("{}%", mem_percent), Color::Yellow),
        ("Processos", process_count.to_string(), Color::Cyan),
        ("GPUs", gpu_count.to_string(), Color::Magenta),
    ];

    for (i, (title, value, color)) in items.iter().enumerate() {
        f.render_widget(
            Paragraph::new(value.as_str())
                .style(Style::default().fg(*color).add_modifier(Modifier::BOLD))
                .block(Block::default().title(format!(" {} ", title)).borders(Borders::ALL))
                .alignment(ratatui::layout::Alignment::Center),
            cards[i],
        );
    }
}

fn render_processes(f: &mut Frame, app: &App, area: Rect) {
    let mut processes = app.monitor.get_processes();
    match app.process_sort {
        ProcessSort::Cpu => {
            processes.sort_by(|a, b| b.2.partial_cmp(&a.2).unwrap_or(std::cmp::Ordering::Equal))
        }
        ProcessSort::Memory => processes.sort_by(|a, b| b.3.cmp(&a.3)),
        ProcessSort::Pid => processes.sort_by(|a, b| a.0.cmp(&b.0)),
    }
    let sort_label = match app.process_sort {
        ProcessSort::Cpu => "CPU",
        ProcessSort::Memory => "RAM",
        ProcessSort::Pid => "PID",
    };
    let selected_pid = processes.get(app.selected_process).map(|p| p.0);
    let rows: Vec<Row> = processes
        .iter()
        .take(area.height.saturating_sub(3) as usize)
        .map(|(pid, name, cpu, mem, status)| {
            let marker = if Some(*pid) == selected_pid { ">" } else { " " };
            let style = if Some(*pid) == selected_pid {
                Style::default().fg(Color::Black).bg(Color::Cyan)
            } else {
                Style::default()
            };
            Row::new(vec![
                marker.to_string(),
                pid.to_string(),
                name.clone(),
                status.clone(),
                format!("{:.1}%", cpu),
                format!("{} MB", mem / 1024 / 1024),
            ])
            .style(style)
        })
        .collect();
    let title = format!(
        " Processos | ordenado por {} | c CPU, m RAM, p PID | j/k selecionar ",
        sort_label
    );
    f.render_widget(
        Table::new(
            rows,
            [
                Constraint::Length(2),
                Constraint::Length(8),
                Constraint::Min(20),
                Constraint::Length(10),
                Constraint::Length(8),
                Constraint::Length(12),
            ],
        )
            .header(
                Row::new(vec!["", "PID", "Nome", "Status", "CPU", "Memory"])
                    .style(Style::default().add_modifier(Modifier::BOLD))
                    .bottom_margin(1),
            )
            .block(Block::default().title(title).borders(Borders::ALL)),
        area,
    );
}

fn render_hardware_z(f: &mut Frame, app: &App, area: Rect) {
    let chunks = Layout::default()
        .direction(Direction::Horizontal)
        .constraints([Constraint::Percentage(40), Constraint::Percentage(60)])
        .margin(1)
        .split(area);

    let meta = app.monitor.get_metadata();
    let sys_specs = vec![
        "  [OS]".to_string(),
        format!("  Distro:   {}", meta.os),
        format!("  Kernel:   {}", meta.kernel),
        format!("  Uptime:   {}", meta.uptime),
        String::new(),
        "  [USER SPACE]".to_string(),
        format!("  Shell:    {}", meta.shell),
        format!("  DE:       {}", meta.de),
        format!("  WM:       {}", meta.wm),
        format!("  Display:  {}", meta.resolution),
    ];
    f.render_widget(
        Paragraph::new(sys_specs.join("\n")).block(
            Block::default()
                .title(" Screenfetch Style Info ")
                .borders(Borders::ALL),
        ),
        chunks[0],
    );

    let right_chunks = Layout::default()
        .direction(Direction::Vertical)
        .constraints([Constraint::Length(8), Constraint::Min(0)])
        .split(chunks[1]);

    let cpu_specs = vec![
        "  [CPU]".to_string(),
        format!("  Model:    {}", app.monitor.get_cpu_brand()),
        format!("  Cores:    {} Logical", app.monitor.get_all_cpus_usage().len()),
    ];
    f.render_widget(
        Paragraph::new(cpu_specs.join("\n")).block(
            Block::default()
                .title(" Processor ")
                .borders(Borders::ALL),
        ),
        right_chunks[0],
    );

    let gpus = app.monitor.get_gpus();
    let mut gpu_specs = vec!["  [GPU ANALYTICS]".to_string()];
    if gpus.is_empty() {
        gpu_specs.push("  Nenhuma GPU com metricas disponiveis.".to_string());
    }
    for gpu in gpus {
        gpu_specs.push(String::new());
        gpu_specs.push(format!("  Name:    {}", gpu.name));
        gpu_specs.push(format!("  Driver:  {}", gpu.driver));

        if let Some(intel) = &gpu.intel_details {
            if let Some(r) = &intel.render {
                gpu_specs.push(format!("  Render:  {:.1}%", r.busy));
            }
            if let Some(v) = &intel.video {
                gpu_specs.push(format!("  Video:   {:.1}%", v.busy));
            }
            if let Some(v) = &intel.video_enhance {
                gpu_specs.push(format!("  VEnhance:{:.1}%", v.busy));
            }
            if let Some(b) = &intel.blitter {
                gpu_specs.push(format!("  Blitter: {:.1}%", b.busy));
            }
            if let Some(freq) = gpu.intel_frequency_mhz {
                gpu_specs.push(format!("  Clock:   {:.0} MHz", freq));
            }
            if let Some(power) = gpu.intel_power_w {
                gpu_specs.push(format!("  GPU W:   {:.1} W", power));
            }
            if let Some(power) = gpu.intel_package_power_w {
                gpu_specs.push(format!("  Pkg W:   {:.1} W", power));
            }
            if let Some(rc6) = gpu.intel_rc6 {
                gpu_specs.push(format!("  RC6:     {:.1}%", rc6));
            }
        } else {
            if let Some(c) = gpu.clock_mhz {
                gpu_specs.push(format!("  Clock:   {} MHz", c));
            }
            if let Some(p) = gpu.power_w {
                gpu_specs.push(format!("  Power:   {} W", p));
            }
        }
        if let Some(status) = &gpu.status {
            gpu_specs.push(format!("  Status:  {}", status));
        }
        gpu_specs.push(format!(
            "  VRAM:    {:.1} GB Total",
            gpu.mem_total as f64 / BYTES_PER_GB
        ));
    }

    let disks = app.monitor.get_disks_info();
    gpu_specs.push(String::new());
    gpu_specs.push("  [STORAGE]".to_string());
    for (mount, _, total) in disks {
        gpu_specs.push(format!(
            "  {} - {:.1} GB",
            mount,
            total as f64 / BYTES_PER_GB
        ));
    }

    f.render_widget(
        Paragraph::new(gpu_specs.join("\n")).block(
            Block::default()
                .title(" Hardware Analytics ")
                .borders(Borders::ALL)
                .border_style(Style::default().fg(Color::Yellow)),
        ),
        right_chunks[1],
    );
}

fn render_network(f: &mut Frame, app: &App, area: Rect) {
    let networks = app.monitor.get_networks_info();
    let rows: Vec<Row> = networks
        .iter()
        .map(|network| {
            Row::new(vec![
                network.name.clone(),
                format_bytes_per_sec(network.rx_per_sec),
                format_bytes_per_sec(network.tx_per_sec),
                format!("{:.1} MB", network.rx_total as f64 / BYTES_PER_MB),
                format!("{:.1} MB", network.tx_total as f64 / BYTES_PER_MB),
            ])
        })
        .collect();
    if rows.is_empty() {
        f.render_widget(
            Paragraph::new("Nenhuma interface de rede detectada.")
                .block(Block::default().title(" Network Activity ").borders(Borders::ALL)),
            area,
        );
        return;
    }

    f.render_widget(
        Table::new(
            rows,
            [
                Constraint::Min(20),
                Constraint::Length(14),
                Constraint::Length(14),
                Constraint::Length(16),
                Constraint::Length(16),
            ],
        )
            .header(
                Row::new(vec!["Interface", "RX/s", "TX/s", "Total RX", "Total TX"])
                    .style(Style::default().add_modifier(Modifier::BOLD))
                    .bottom_margin(1),
            )
            .block(Block::default().title(" Network Activity ").borders(Borders::ALL)),
        area,
    );
}

fn render_help(f: &mut Frame, area: Rect) {
    let text = [
        "TeuPC monitora CPU, memoria, processos, hardware, GPU e rede em tempo real.",
        "",
        "Navegacao",
        "  1 Geral    2 Processos    3 Hardware-Z    4 Rede    ? Ajuda",
        "  Tab / seta direita: proxima tela",
        "  Shift+Tab / seta esquerda: tela anterior",
        "",
        "Controles",
        "  espaco: pausar ou retomar refresh",
        "  q: sair",
        "",
        "Processos",
        "  c: ordenar por CPU",
        "  m: ordenar por RAM",
        "  p: ordenar por PID",
        "  j/k ou setas cima/baixo: mover selecao",
        "",
        "Notas",
        "  NVIDIA usa NVML quando disponivel.",
        "  AMD/Intel usam metricas expostas pelo kernel e ferramentas locais quando permitidas.",
    ];

    f.render_widget(
        Paragraph::new(text.join("\n"))
            .block(Block::default().title(" Ajuda ").borders(Borders::ALL))
            .wrap(Wrap { trim: false }),
        area,
    );
}

fn percent_from_f32(value: f32) -> u16 {
    percent_from_f64(value as f64)
}

fn percent_from_f64(value: f64) -> u16 {
    value.clamp(0.0, 100.0).round() as u16
}

fn format_bytes_per_sec(bytes: u64) -> String {
    if bytes >= 1_000_000 {
        format!("{:.1} MB/s", bytes as f64 / 1_000_000.0)
    } else if bytes >= 1_000 {
        format!("{:.1} KB/s", bytes as f64 / 1_000.0)
    } else {
        format!("{} B/s", bytes)
    }
}
