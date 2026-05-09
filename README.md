# TeuPC Professional

TeuPC is a high-performance, asynchronous system monitoring and hardware diagnostic tool for Linux. Designed with a high-density professional HUD, it provides deep insights into system vitals, hardware topology, and real-time performance metrics without blocking the user interface.

## Key Features

- **Asynchronous Architecture:** Built on a multi-actor model using Tokio. Monitoring tasks (CPU, GPU, Network, Sensors, Bus) run in independent background loops, ensuring a smooth 60 FPS UI.
- **Deep Hardware Diagnostics (AIDA64 Style):**
    - **CPU:** Real-time per-core frequency (GHz) and instruction set identification (AVX, AVX-512, AES, etc.).
    - **Storage:** Real-time I/O throughput (KB/s) and physical device model tracking.
    - **Sensors:** Comprehensive telemetry including Voltages (VCore, 12V, 5V), Fan speeds (RPM), and thermal data.
    - **Bus Inventory:** Real-time PCI and USB device scanning.
- **Extreme Networking:** Public IP identification, geolocation (City/Country), and continuous latency (ping) monitoring.
- **Professional HUD:** A high-density dashboard summarizing core vitals, neural pulses (history graphs), and electrical telemetry.
- **Integrated Benchmarking:** Multi-threaded stress test and scoring engine to measure processor throughput.
- **Reporting:** Instant JSON snapshots of the entire system state for auditing and logging.

## Installation

### Prerequisites
- Rust (Stable)
- Linux
- Optional: `intel_gpu_top` (for Intel GPU metrics), `nvml` (for NVIDIA), `xrandr` (for display info).

### Build from source
```bash
git clone https://github.com/marciolopes/TeuPC.git
cd TeuPC
cargo build --release
./target/release/teupc
```

## Navigation & Controls
- **[1-7]**: Switch between tabs (Overview, Processes, Hardware, Network, Sensors, Bus, Bench).
- **Tab / Arrows**: Navigate screens.
- **Space**: Pause/Resume UI refresh.
- **s**: Export technical JSON snapshot.
- **b**: Start CPU stress test.
- **q**: Quit.

## License
Distributed under the MIT License. See `LICENSE` for more information.
