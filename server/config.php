<?php
/**
 * TeuPC Central - Configuração Geral
 */

return [
    // Chave de API esperada dos clientes/daemons TeuPC. Obrigatória: sem ela o hub recusa
    // toda requisição (fail closed). Gere com: openssl rand -hex 32
    'api_key' => getenv('TEUPC_API_KEY') ?: '',

    // Caminho do banco de dados SQLite
    'db_path' => __DIR__ . '/database/teupc_central.sqlite',

    // Tempo em segundos para considerar uma máquina offline
    'offline_threshold_secs' => 15,

    // Limite de retenção do histórico de telemetria (ex: manter últimos 10.000 registros por máquina)
    'telemetry_retention_limit' => 5000,
];
