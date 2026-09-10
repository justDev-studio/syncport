<?php

declare(strict_types=1);

define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

final class WP_Error
{
    public function __construct(private string $code, private string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

final class OriginalEngineWpdb
{
    public string $prefix = 'target_';
    public string $last_error = '';
    public array $queries = [];
    public array $selectQueries = [];
    public array $receipts = [];
    public array $replaced = [];
    public array $tables = ['target_options' => true];

    public function get_results(string $query, string $format): array
    {
        if ($query === 'SHOW FULL TABLES') {
            return [['source_options']];
        }
        if ($query === 'DESCRIBE `source_options`') {
            return [
                ['Field' => 'option_id', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment'],
                ['Field' => 'option_name', 'Type' => 'varchar(191)', 'Null' => 'NO', 'Key' => 'UNI', 'Default' => '', 'Extra' => ''],
                ['Field' => 'option_value', 'Type' => 'longtext', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => ''],
            ];
        }
        if (str_starts_with($query, 'SHOW KEYS FROM `source_options`')) {
            return [['Column_name' => 'option_id']];
        }
        if (str_starts_with($query, 'SELECT * FROM `source_options`')) {
            $this->selectQueries[] = $query;
            preg_match("/`option_id` > '([0-9]+)'/", $query, $matches);
            $first = isset($matches[1]) ? (int) $matches[1] + 1 : 1;
            $rows = [];
            for ($id = $first; $id <= min(250, $first + 99); $id++) {
                $rows[] = match ($id) {
                    1 => ['option_id' => '1', 'option_name' => 'source_user_roles', 'option_value' => 'HTTPS://SOURCE.EXAMPLE/path'],
                    2 => ['option_id' => '2', 'option_name' => 'home', 'option_value' => serialize(['url' => 'HTTPS://SOURCE.EXAMPLE'])],
                    3 => ['option_id' => '3', 'option_name' => 'json_value', 'option_value' => '{"url":"HTTPS://SOURCE.EXAMPLE","path":"/var/www/source"}'],
                    default => ['option_id' => (string) $id, 'option_name' => 'option_' . $id, 'option_value' => 'value_' . $id],
                };
            }
            return $rows;
        }
        if (str_starts_with($query, 'SELECT * FROM `target_options` WHERE option_name')) {
            return [['option_id' => '99', 'option_name' => 'syncport_connections', 'option_value' => 'local']];
        }
        return [];
    }

    public function get_row(string $query, string $format): array|null
    {
        if ($query === 'SHOW CREATE TABLE `source_options`') {
            return [
                'source_options',
                "CREATE TABLE `source_options` (\n"
                    . "  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
                    . "  `option_name` varchar(191) NOT NULL DEFAULT '',\n"
                    . "  `option_value` longtext NOT NULL,\n"
                    . "  PRIMARY KEY (`option_id`),\n"
                    . "  CONSTRAINT `source_options_fk` FOREIGN KEY (`option_id`) REFERENCES `source_other` (`id`)\n"
                    . ') ENGINE=InnoDB',
            ];
        }
        if (str_starts_with($query, 'SELECT chunk_hash, result FROM `target_syncport_chunks`')) {
            foreach (array_reverse($this->receipts) as $receipt) {
                if (str_contains($query, "'{$receipt['operation_uuid']}'")
                    && str_contains($query, "'{$receipt['table_name']}'")
                    && str_ends_with($query, '= ' . $receipt['chunk_offset'])) {
                    return ['chunk_hash' => $receipt['chunk_hash'], 'result' => $receipt['result']];
                }
            }
        }
        return null;
    }

    public function get_var(string $query): string|null
    {
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/", $query, $matches)) {
            return isset($this->tables[$matches[1]]) ? $matches[1] : null;
        }
        if (str_starts_with($query, 'SELECT result FROM `target_syncport_chunks`')) {
            foreach (array_reverse($this->receipts) as $receipt) {
                if (str_contains($query, "'{$receipt['operation_uuid']}'")
                    && str_contains($query, "'{$receipt['table_name']}'")
                    && str_ends_with($query, '= 0')) {
                    return $receipt['result'];
                }
            }
        }
        return null;
    }

    public function prepare(string $query, mixed ...$values): string
    {
        foreach ($values as $value) {
            $replacement = is_int($value) ? (string) $value : "'" . addslashes((string) $value) . "'";
            $query = (string) preg_replace('/%[ds]/', $replacement, $query, 1);
        }
        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $query, $matches)) {
            $this->tables[$matches[1]] = true;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $query, $matches)) {
            unset($this->tables[$matches[1]]);
        }
        if (str_starts_with($query, 'RENAME TABLE ')) {
            preg_match_all('/`([^`]+)` TO `([^`]+)`/', $query, $renames, PREG_SET_ORDER);
            foreach ($renames as $rename) {
                unset($this->tables[$rename[1]]);
                $this->tables[$rename[2]] = true;
            }
        }
        return 1;
    }

    public function insert(string $table, array $row): int|false
    {
        $this->receipts[] = $row;
        return 1;
    }

    public function replace(string $table, array $row): int|false
    {
        $this->replaced[] = [$table, $row];
        return 1;
    }
}

function __(string $message): string { return $message; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function is_serialized(mixed $value): bool { return is_string($value) && @unserialize($value) !== false; }
function maybe_unserialize(mixed $value): mixed { return is_serialized($value) ? unserialize($value) : $value; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function get_current_user_id(): int { return 0; }
function sanitize_text_field(mixed $value): string { return (string) $value; }

$GLOBALS['wpdb'] = new OriginalEngineWpdb();

require dirname(__DIR__) . '/src/Migration/DatabaseMigrator.php';

$migrator = new JustDev\SyncPort\Migration\DatabaseMigrator();
$chunk = $migrator->exportSqlChunk([
    'operation' => 'original-engine',
    'table' => ['name' => 'source_options', 'rows' => 250],
    'offset' => 0,
    'source_prefix' => 'source_',
    'target_prefix' => 'target_',
    'replace' => [
        'https://source.example' => 'https://target.example',
        '/var/www/source' => '/srv/www/target',
    ],
    'selected_tables' => [['name' => 'source_options', 'rows' => 250]],
]);
if (is_wp_error($chunk)) {
    fwrite(STDERR, $chunk->get_error_message() . PHP_EOL);
    exit(1);
}

$binary = base64_decode((string) ($chunk['data'] ?? ''), true);
$sql = ($chunk['encoding'] ?? '') === 'deflate-base64' && is_string($binary) ? gzuncompress($binary) : $binary;
$select = implode("\n", $GLOBALS['wpdb']->selectQueries);
if (JustDev\SyncPort\Migration\DatabaseMigrator::CHUNK_SIZE !== 100
    || ($chunk['protocol'] ?? '') !== 'wpsdb-sql-v1'
    || ($chunk['processed'] ?? 0) !== 250
    || empty($chunk['done'])
    || count($GLOBALS['wpdb']->selectQueries) !== 3
    || !is_string($sql)
    || !str_contains($sql, 'DROP TABLE IF EXISTS `_mig_target_options`;')
    || !str_contains($sql, 'CREATE TABLE `_mig_target_options`')
    || str_contains($sql, 'CONSTRAINT `source_options_fk`')
    || !str_contains((string) ($chunk['deferred_alter'] ?? ''), 'ALTER TABLE `target_options`')
    || !str_contains($sql, "(1, 'target_user_roles'")
    || substr_count($sql, 'target.example') < 3
    || str_contains($sql, 'SOURCE.EXAMPLE')
    || !str_contains($sql, 'srv')
    || str_contains($sql, 'var/www/source')
    || !str_contains($sql, 's:22:"https://target.example";')
    || !str_contains($select, "option_name NOT LIKE '\\_transient\\_%'")) {
    fwrite(STDERR, "Full database export does not match the jd-wp-sync-db SQL engine.\n");
    exit(1);
}

$applied = $migrator->applySqlChunk($chunk);
if (is_wp_error($applied)) {
    fwrite(STDERR, $applied->get_error_message() . PHP_EOL);
    exit(1);
}
$finalized = $migrator->finalizeReplace(
    'original-engine',
    [['name' => 'source_options', 'rows' => 250]],
    'source_'
);
$queries = implode("\n", $GLOBALS['wpdb']->queries);
if (is_wp_error($finalized)
    || !str_contains($queries, 'RENAME TABLE `_mig_target_options`')
    || !str_contains($queries, 'ALTER TABLE `target_options`')
    || ($GLOBALS['wpdb']->replaced[0][1]['option_name'] ?? '') !== 'syncport_connections') {
    fwrite(STDERR, "Full database import does not finalize like jd-wp-sync-db.\n");
    exit(1);
}

echo "Full database migration follows the jd-wp-sync-db SQL engine.\n";
