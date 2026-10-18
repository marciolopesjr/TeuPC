use std::env;
use std::fs;
use std::io;
use std::path::Path;

use anyhow::Result;
use crossterm::{
    event::{Event, KeyCode},
    execute,
    terminal::{disable_raw_mode, enable_raw_mode, EnterAlternateScreen, LeaveAlternateScreen},
};
use futures_util::StreamExt;
use ratatui::{backend::CrosstermBackend, Terminal};

mod app;
mod daemon;
mod monitor;
mod ui;

use crate::app::{App, CurrentScreen, ProcessSort};

fn print_help() {
    println!("TeuPC - Monitor de Hardware e Telemetria para Linux");
    println!("Uso: teupc [OPÇÕES]\n");
    println!("Opções:");
    println!("  -d, --daemon              Executa em modo daemon (background) enviando telemetria para a Central");
    println!("      --config <PATH>       Caminho para arquivo de configuração JSON (padrão: teupc_agent.json)");
    println!("      --test-connection     Testa a conexão e credenciais com a Central PHP");
    println!("      --push-snapshot       Gera um snapshot completo da máquina e envia à Central, depois finaliza");
    println!("      --generate-service    Exibe o arquivo de unidade systemd gerado para o TeuPC Daemon");
    println!("      --install-service     Instala o daemon como serviço systemd (/etc/systemd/system/teupc.service)");
    println!("  -h, --help                Exibe esta mensagem de ajuda");
    println!("\nSe nenhuma opção for fornecida, o TeuPC iniciará em modo TUI interativo.");
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().collect();
    let mut custom_config_path: Option<&str> = None;
    let mut is_daemon = false;
    let mut is_test_conn = false;
    let mut is_push_snap = false;
    let mut is_gen_service = false;
    let mut is_install_service = false;

    let mut i = 1;
    while i < args.len() {
        match args[i].as_str() {
            "-h" | "--help" => {
                print_help();
                return Ok(());
            }
            "-d" | "--daemon" => {
                is_daemon = true;
            }
            "--test-connection" => {
                is_test_conn = true;
            }
            "--push-snapshot" => {
                is_push_snap = true;
            }
            "--generate-service" => {
                is_gen_service = true;
            }
            "--install-service" => {
                is_install_service = true;
            }
            "--config" => {
                if i + 1 < args.len() {
                    custom_config_path = Some(&args[i + 1]);
                    i += 1;
                } else {
                    eprintln!("Erro: --config requer o caminho do arquivo.");
                    std::process::exit(1);
                }
            }
            other => {
                eprintln!("Argumento desconhecido: {}\n", other);
                print_help();
                std::process::exit(1);
            }
        }
        i += 1;
    }

    // 1. Ações de utilitário do Daemon
    if is_gen_service {
        let exe_path = env::current_exe()?.to_string_lossy().to_string();
        let (_config, config_path) = daemon::load_config(custom_config_path);
        let config_str = config_path.to_string_lossy().to_string();
        let unit = daemon::generate_service_unit(&exe_path, &config_str);
        println!("{}", unit);
        return Ok(());
    }

    if is_install_service {
        let exe_path = env::current_exe()?.to_string_lossy().to_string();
        let service_dir = Path::new("/etc/systemd/system");
        let teupc_cfg_dir = Path::new("/etc/teupc");
        
        println!("[*] Instalando TeuPC Daemon no systemd...");
        
        let target_cfg = if teupc_cfg_dir.exists() || fs::create_dir_all(teupc_cfg_dir).is_ok() {
            "/etc/teupc/agent.json"
        } else {
            "/etc/teupc_agent.json"
        };

        let (config, _) = daemon::load_config(custom_config_path);
        if let Ok(json) = serde_json::to_string_pretty(&config) {
            let _ = fs::write(target_cfg, json);
            println!("[+] Configuração gravada em {}", target_cfg);
        }

        let unit_content = daemon::generate_service_unit(&exe_path, target_cfg);
        let service_path = service_dir.join("teupc.service");

        match fs::write(&service_path, unit_content) {
            Ok(_) => {
                println!("[+] Arquivo de serviço instalado em {}", service_path.display());
                println!("[*] Recarregando systemd e iniciando o serviço...");
                let _ = std::process::Command::new("systemctl").arg("daemon-reload").status();
                let _ = std::process::Command::new("systemctl").arg("enable").arg("--now").arg("teupc").status();
                println!("[✓] TeuPC Daemon instalado e ativado! Verifique o status com: systemctl status teupc");
            }
            Err(e) => {
                eprintln!("[!] Erro ao gravar /etc/systemd/system/teupc.service: {}", e);
                eprintln!("[!] Dica: Execute com privilégios de root (sudo teupc --install-service)");
            }
        }
        return Ok(());
    }

    if is_test_conn {
        let (config, _) = daemon::load_config(custom_config_path);
        daemon::run_test_connection(&config).await?;
        return Ok(());
    }

    if is_push_snap {
        let (config, _) = daemon::load_config(custom_config_path);
        daemon::run_push_snapshot(&config).await?;
        return Ok(());
    }

    if is_daemon {
        let (config, cfg_path) = daemon::load_config(custom_config_path);
        println!("[*] Carregando configuração de: {}", cfg_path.display());
        daemon::run_daemon(config).await?;
        return Ok(());
    }

    // 2. Modo Interativo TUI
    enable_raw_mode()?;
    let mut stdout = io::stdout();
    execute!(stdout, EnterAlternateScreen)?;
    let backend = CrosstermBackend::new(stdout);
    let mut terminal = Terminal::new(backend)?;

    // Create app state
    let mut app = App::new();

    // Start monitors
    let (tx, mut rx) = tokio::sync::mpsc::channel(100);
    app.monitor_tx = Some(tx.clone());
    crate::monitor::spawn_monitors(tx).await;

    let mut reader = crossterm::event::EventStream::new();

    loop {
        terminal.draw(|f| ui::ui(f, &app))?;

        tokio::select! {
            maybe_event = reader.next() => {
                if let Some(Ok(event)) = maybe_event {
                    if let Event::Key(key) = event {
                        match key.code {
                            KeyCode::Char('q') => app.should_quit = true,
                            KeyCode::Char('1') => app.current_screen = CurrentScreen::Overview,
                            KeyCode::Char('2') => app.current_screen = CurrentScreen::Processes,
                            KeyCode::Char('3') => app.current_screen = CurrentScreen::SystemInfo,
                            KeyCode::Char('4') => app.current_screen = CurrentScreen::Network,
                            KeyCode::Char('5') => app.current_screen = CurrentScreen::Sensors,
                            KeyCode::Char('6') => app.current_screen = CurrentScreen::Devices,
                            KeyCode::Char('7') => app.current_screen = CurrentScreen::Benchmarks,
                            KeyCode::Char('s') => {
                                let _ = app.save_snapshot();
                            }
                            KeyCode::Char('b') => {
                                app.start_benchmark();
                            }
                            KeyCode::Char('?') | KeyCode::Char('h') => {
                                app.current_screen = CurrentScreen::Help
                            }
                            KeyCode::Char(' ') => app.paused = !app.paused,
                            KeyCode::Char('c') => app.set_process_sort(ProcessSort::Cpu),
                            KeyCode::Char('m') => app.set_process_sort(ProcessSort::Memory),
                            KeyCode::Char('p') => app.set_process_sort(ProcessSort::Pid),
                            KeyCode::Char('r') => app.set_process_sort(ProcessSort::DiskRead),
                            KeyCode::Char('w') => app.set_process_sort(ProcessSort::DiskWrite),
                            KeyCode::Tab | KeyCode::Right => app.next_screen(),
                            KeyCode::BackTab | KeyCode::Left => app.previous_screen(),
                            KeyCode::Down | KeyCode::Char('j') => app.select_next_process(),
                            KeyCode::Up | KeyCode::Char('k') => app.select_previous_process(),
                            _ => {}
                        }
                    }
                }
            }
            maybe_monitor_event = rx.recv() => {
                if let Some(monitor_event) = maybe_monitor_event {
                    app.on_monitor_event(monitor_event);
                }
            }
        }

        if app.should_quit {
            break;
        }
    }

    // Restore terminal
    disable_raw_mode()?;
    execute!(terminal.backend_mut(), LeaveAlternateScreen)?;
    terminal.show_cursor()?;

    Ok(())
}
