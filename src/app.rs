use crate::monitor::Monitor;
use std::collections::{HashMap, VecDeque};

const HISTORY_LIMIT: usize = 120;

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
    pub network_history: HashMap<String, NetworkHistory>,
}

pub struct NetworkHistory {
    pub rx: VecDeque<u64>,
    pub tx: VecDeque<u64>,
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
            network_history: HashMap::new(),
        }
    }

    pub fn on_tick(&mut self) {
        if self.paused {
            return;
        }

        self.monitor.refresh();
        let cpu_usage = self.monitor.get_global_cpu_usage().clamp(0.0, 100.0) as u64;
        self.cpu_history.push_back(cpu_usage);
        if self.cpu_history.len() > HISTORY_LIMIT {
            self.cpu_history.pop_front();
        }
        self.update_network_history();
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

    fn update_network_history(&mut self) {
        for network in self.monitor.get_networks_info() {
            let history =
                self.network_history
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
            .retain(|name, _| self.monitor.get_networks_info().iter().any(|n| n.name == *name));
    }
}
