# TeuPC

TeuPC is a Linux terminal dashboard for quick system inspection. It shows CPU, memory, processes, hardware, GPU metrics, storage and network activity using a Ratatui interface.

## Requirements

- Rust stable
- Linux
- Optional: NVIDIA drivers/NVML for NVIDIA GPU metrics
- Optional: `lspci`, `xrandr` and `intel_gpu_top` for richer hardware details

## Run

```bash
cargo run
```

## Controls

| Key | Action |
| --- | --- |
| `1` | Overview |
| `2` | Processes |
| `3` | Hardware-Z |
| `4` | Network |
| `?` or `h` | Help |
| `Tab` / Right | Next screen |
| `Shift+Tab` / Left | Previous screen |
| Space | Pause/resume refresh |
| `q` | Quit |
| `c` | Sort processes by CPU |
| `m` | Sort processes by memory |
| `p` | Sort processes by PID |
| `j` / `k` or Up / Down | Move process selection |

## Notes

The Network screen shows RX/TX sparklines for active interfaces plus current and total traffic counters.

The app avoids privileged prompts while running. Intel GPU metrics are collected only when `intel_gpu_top` can run directly in the current environment; otherwise TeuPC keeps running and reports why usage is unavailable.

On some systems, Intel GPU usage requires `CAP_PERFMON`. If the Hardware-Z screen reports that permission is missing, grant it outside the app with your normal admin workflow, for example:

```bash
sudo setcap cap_perfmon+ep /usr/bin/intel_gpu_top
```
