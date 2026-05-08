use std::{
    io,
    time::{Duration, Instant},
};

use anyhow::Result;
use crossterm::{
    event::{self, Event, KeyCode},
    execute,
    terminal::{disable_raw_mode, enable_raw_mode, EnterAlternateScreen, LeaveAlternateScreen},
};
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
    let tick_rate = Duration::from_millis(500);
    let mut last_tick = Instant::now();

    loop {
        terminal.draw(|f| ui::ui(f, &app))?;

        let timeout = tick_rate
            .checked_sub(last_tick.elapsed())
            .unwrap_or_else(|| Duration::from_secs(0));

        if event::poll(timeout)? {
            if let Event::Key(key) = event::read()? {
                match key.code {
                    KeyCode::Char('q') => app.should_quit = true,
                    KeyCode::Char('1') => app.current_screen = CurrentScreen::Overview,
                    KeyCode::Char('2') => app.current_screen = CurrentScreen::Processes,
                    KeyCode::Char('3') => app.current_screen = CurrentScreen::SystemInfo,
                    KeyCode::Char('4') => app.current_screen = CurrentScreen::Network,
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

        if last_tick.elapsed() >= tick_rate {
            app.on_tick();
            last_tick = Instant::now();
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
