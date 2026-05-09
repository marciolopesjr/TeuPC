use std::io;

use anyhow::Result;
use crossterm::{
    event::{Event, KeyCode},
    execute,
    terminal::{disable_raw_mode, enable_raw_mode, EnterAlternateScreen, LeaveAlternateScreen},
};
use futures_util::StreamExt;
use ratatui::{backend::CrosstermBackend, Terminal};

mod app;
mod monitor;
mod ui;

use crate::app::{App, CurrentScreen, ProcessSort};

#[tokio::main]
async fn main() -> Result<()> {
    // Setup terminal
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
