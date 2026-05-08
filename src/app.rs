use crate::monitor::Monitor;
use std::collections::VecDeque;

#[derive(Clone, Copy)]
pub enum CurrentScreen {
    Overview,
    Processes,
    SystemInfo,
    Network,
    Help,
}

#[derive(Clone, Copy)]
pub enum ProcessSort {
    Cpu,
    Memory,
    Pid,
}

pub struct App {
    pub monitor: Monitor,
    pub current_screen: CurrentScreen,
    pub should_quit: bool,
    pub paused: bool,
    pub process_sort: ProcessSort,
    pub selected_process: usize,
    pub cpu_history: VecDeque<u64>,
}

impl App {
    pub fn new() -> Self {
        Self {
            monitor: Monitor::new(),
            current_screen: CurrentScreen::Overview,
            should_quit: false,
            paused: false,
            process_sort: ProcessSort::Cpu,
            selected_process: 0,
            cpu_history: VecDeque::with_capacity(100),
        }
    }

    pub fn on_tick(&mut self) {
        if self.paused {
            return;
        }

        self.monitor.refresh();
        let cpu_usage = self.monitor.get_global_cpu_usage().clamp(0.0, 100.0) as u64;
        self.cpu_history.push_back(cpu_usage);
        if self.cpu_history.len() > 100 {
            self.cpu_history.pop_front();
        }
    }

    pub fn next_screen(&mut self) {
        self.current_screen = match self.current_screen {
            CurrentScreen::Overview => CurrentScreen::Processes,
            CurrentScreen::Processes => CurrentScreen::SystemInfo,
            CurrentScreen::SystemInfo => CurrentScreen::Network,
            CurrentScreen::Network => CurrentScreen::Help,
            CurrentScreen::Help => CurrentScreen::Overview,
        };
    }

    pub fn previous_screen(&mut self) {
        self.current_screen = match self.current_screen {
            CurrentScreen::Overview => CurrentScreen::Help,
            CurrentScreen::Processes => CurrentScreen::Overview,
            CurrentScreen::SystemInfo => CurrentScreen::Processes,
            CurrentScreen::Network => CurrentScreen::SystemInfo,
            CurrentScreen::Help => CurrentScreen::Network,
        };
    }

    pub fn select_next_process(&mut self) {
        let len = self.monitor.get_processes().len();
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
}
