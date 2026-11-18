<?php

namespace TeuPC\Central;

/**
 * Configs do dashboard, persistidas no SQLite (sobrevivem a browser/cache).
 * Chaves conhecidas: interval_ms (int), cpu (int %), ram_gb (int).
 */

require_once __DIR__ . '/Database.php';

class Settings
{
    public const DEFAULTS = [
        'interval_ms' => 2000,
        'cpu' => 85,
        'ram_gb' => 4,
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $db = Database::getConnection();
        $rows = $db->query("SELECT key, value FROM dashboard_settings")->fetchAll();
        $settings = self::DEFAULTS;
        foreach ($rows as $row) {
            if (array_key_exists($row['key'], $settings)) {
                $settings[$row['key']] = is_numeric($row['value']) ? (int) $row['value'] : $row['value'];
            }
        }
        return self::$cache = $settings;
    }

    public static function get(string $key, int $default = 0): int
    {
        $all = self::all();
        return (int) ($all[$key] ?? $default);
    }

    public static function set(string $key, int $value): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO dashboard_settings (key, value) VALUES (:key, :value)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ");
        $stmt->execute([':key' => $key, ':value' => (string) $value]);
        self::$cache = null;
    }
}
