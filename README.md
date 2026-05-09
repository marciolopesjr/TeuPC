# TeuPC Professional

O TeuPC é uma ferramenta de diagnóstico de hardware e monitoramento de sistema de alta performance para Linux. Desenvolvido com uma interface HUD (Heads-Up Display) profissional de alta densidade, ele oferece visões profundas sobre os sinais vitais do sistema, topologia de hardware e métricas de desempenho em tempo real, tudo sem bloquear a interface do usuário.

## Principais Funcionalidades

- **Arquitetura Assíncrona:** Construído sobre um modelo multi-ator utilizando Tokio. As tarefas de monitoramento (CPU, GPU, Rede, Sensores, Barramento) rodam em loops independentes em segundo plano, garantindo uma interface fluida a 60 FPS.
- **Diagnóstico Profundo de Hardware (Nível AIDA64):**
    - **CPU:** Monitoramento de frequência por núcleo em tempo real (GHz) e identificação do conjunto de instruções (AVX, AVX-512, AES, etc.).
    - **Armazenamento:** Taxa de transferência de E/S (KB/s) em tempo real e rastreamento de modelos físicos de discos.
    - **Sensores:** Telemetria abrangente incluindo Voltagens (VCore, 12V, 5V), velocidade de Coolers (RPM) e dados térmicos detalhados.
    - **Barramentos:** Varredura em tempo real de dispositivos PCI e USB.
- **Rede Extrema:** Identificação de IP público, geolocalização (Cidade/País) e monitoramento contínuo de latência (ping).
- **HUD Profissional:** Painel principal de alta densidade resumindo sinais vitais, pulsos neurais (gráficos de histórico) e telemetria elétrica.
- **Benchmarks Integrados:** Teste de stress multi-thread e motor de pontuação para medir o throughput do processador.
- **Relatórios:** Snapshots JSON instantâneos de todo o estado do sistema para auditoria e logs técnicos.

## Instalação

### Pré-requisitos
- Rust (Stable)
- Linux
- Opcionais: `intel_gpu_top` (para métricas de GPU Intel), `nvml` (para NVIDIA), `xrandr` (para info de displays).

### Compilar da fonte
```bash
git clone https://github.com/marciolopesjr/TeuPC.git
cd TeuPC
cargo build --release
./target/release/teupc
```

## Navegação e Controles
- **[1-7]**: Alternar entre abas (Geral, Processos, Hardware, Rede, Sensores, Barramento, Bench).
- **Tab / Setas**: Navegar pelas telas.
- **Espaço**: Pausar/Retomar a atualização da interface.
- **s**: Exportar snapshot técnico em JSON.
- **b**: Iniciar teste de stress da CPU.
- **q**: Sair.

## Licença
Distribuído sob a Licença MIT. Veja o arquivo `LICENSE` para mais informações.
