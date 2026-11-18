<?php

namespace TeuPC\Central;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config.php';
            $dbPath = $config['db_path'];

            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            try {
                self::$instance = new PDO("sqlite:" . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                // Vários agentes escrevendo enquanto o dashboard lê
                self::$instance->exec('PRAGMA journal_mode = WAL');
                self::$instance->exec('PRAGMA busy_timeout = 5000');
                self::initSchema(self::$instance);
            } catch (PDOException $e) {
                die("Erro ao conectar com o banco SQLite: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    private static function initSchema(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS machines (
                machine_id TEXT PRIMARY KEY,
                hostname TEXT,
                os TEXT,
                kernel TEXT,
                cpu_brand TEXT,
                cpu_cores INTEGER,
                mem_total INTEGER,
                ip_public TEXT,
                ip_local TEXT,
                virtualization TEXT,
                status TEXT DEFAULT 'online',
                last_seen DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS telemetry_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                machine_id TEXT,
                cpu_usage REAL,
                mem_used INTEGER,
                mem_total INTEGER,
                swap_used INTEGER,
                swap_total INTEGER,
                gpu_usage REAL,
                disk_read_kbs REAL,
                disk_write_kbs REAL,
                net_rx_kbs REAL,
                net_tx_kbs REAL,
                cpu_temp REAL,
                rapl_watts REAL,
                load_1m REAL,
                payload_json TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(machine_id) REFERENCES machines(machine_id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS events_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                machine_id TEXT,
                severity TEXT,
                category TEXT,
                title TEXT,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(machine_id) REFERENCES machines(machine_id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                machine_id TEXT,
                snapshot_json TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(machine_id) REFERENCES machines(machine_id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS dashboard_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_telemetry_machine ON telemetry_logs(machine_id, created_at);
            CREATE INDEX IF NOT EXISTS idx_events_machine ON events_log(machine_id, created_at);
        ");
    }
}
