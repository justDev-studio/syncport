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

final class SqlProtocolWpdb
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
        if (str_starts_with($query, 'SHOW KEYS FROM `source_options`')) {
            return [['Column_name' => 'option_id']];
        }
        if (str_starts_with($query, 'SELECT * FROM `source_options`')) {
            $this->selectQueries[] = $query;
            return [
                ['option_id' => '1', 'option_name' => 'source_user_roles', 'option_value' => 'https://source.example/path'],
                ['option_id' => '2', 'option_name' => 'home', 'option_value' => serialize(['url' => 'https://source.example'])],
            ];
        }
        if (str_starts_with($query, 'SELECT * FROM `target_options` WHERE option_name')) {
            return [
                ['option_id' => '99', 'option_name' => 'syncport_connections', 'option_value' => 'local-connections'],
            ];
        }
        return [];
    }

    public function get_row(string $query, string $format): array|null
    {
        if ($query === 'SHOW CREATE TABLE `source_options`') {
            return ['source_options', 'CREATE TABLE `source_options` (`option_id` bigint NOT NULL, `option_name` varchar(191), `option_value` longtext, PRIMARY KEY (`option_id`))'];
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

$GLOBALS['wpdb'] = new SqlProtocolWpdb();

require dirname(__DIR__) . '/src/Migration/DatabaseMigrator.php';

$migrator = new JustDev\SyncPort\Migration\DatabaseMigrator();
$context = [
    'operation' => 'sql-operation',
    'table' => ['name' => 'source_options', 'rows' => 2],
    'offset' => 0,
    'source_prefix' => 'source_',
    'target_prefix' => 'target_',
    'replace' => ['https://source.example' => 'https://target.example'],
    'selected_tables' => [['name' => 'source_options', 'rows' => 2]],
];
$chunk = $migrator->exportSqlChunk($context);
if (is_wp_error($chunk)) {
    fwrite(STDERR, $chunk->get_error_message() . PHP_EOL);
    exit(1);
}
if (($chunk['protocol'] ?? '') !== JustDev\SyncPort\Migration\DatabaseMigrator::PROTOCOL
    || isset($chunk['rows'])
    || !in_array($chunk['encoding'] ?? '', ['gzip-base64', 'base64'], true)
    || ($chunk['processed'] ?? 0) !== 2) {
    fwrite(STDERR, "The source did not export a bounded SQL dump chunk.\n");
    exit(1);
}
$cursorChunk = $migrator->exportSqlChunk(array_replace($context, ['offset' => 2, 'cursor' => ['option_id' => '2']]));
$cursorQuery = (string) end($GLOBALS['wpdb']->selectQueries);
if (is_wp_error($cursorChunk) || !str_contains($cursorQuery, "`option_id` > '2'") || str_contains($cursorQuery, 'OFFSET')) {
    fwrite(STDERR, "SQL dump continuation did not use its primary-key cursor.\n");
    exit(1);
}

$result = $migrator->applySqlChunk($chunk);
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . PHP_EOL);
    exit(1);
}
$queries = implode("\n", $GLOBALS['wpdb']->queries);
if (str_contains($queries, 'DROP TABLE IF EXISTS `target_options`')
    || !str_contains($queries, 'CREATE TABLE `target_syncport_tmp_')
    || !str_contains($queries, 'INSERT INTO `target_syncport_tmp_')
    || !str_contains($queries, 'target_user_roles')
    || !str_contains($queries, 'https://target.example')) {
    fwrite(STDERR, "The SQL dump was not safely mapped into the target staging table.\n");
    exit(1);
}

$queryCount = count($GLOBALS['wpdb']->queries);
$retry = $migrator->applySqlChunk($chunk);
if (is_wp_error($retry) || count($GLOBALS['wpdb']->queries) !== $queryCount) {
    fwrite(STDERR, "Retrying a SQL chunk did not reuse its durable receipt.\n");
    exit(1);
}

$damaged = $chunk;
$damaged['sha256'] = str_repeat('0', 64);
if (!is_wp_error($migrator->applySqlChunk($damaged))) {
    fwrite(STDERR, "A damaged SQL dump checksum was accepted.\n");
    exit(1);
}

$finalized = $migrator->finalizeReplace(
    'sql-operation',
    [['name' => 'source_options', 'rows' => 2]],
    'source_'
);
$queries = implode("\n", $GLOBALS['wpdb']->queries);
if (is_wp_error($finalized)
    || !str_contains($queries, 'SET FOREIGN_KEY_CHECKS=0')
    || !str_contains($queries, 'RENAME TABLE `target_options` TO `target_syncport_bak_')
    || !str_contains($queries, '` TO `target_options`')
    || ($GLOBALS['wpdb']->replaced[0][1]['option_name'] ?? '') !== 'syncport_connections') {
    fwrite(STDERR, "The staged SQL dump was not atomically activated with local operational settings preserved.\n");
    exit(1);
}

unset($GLOBALS['wpdb']->tables['target_options']);
$newContext = array_replace($context, ['operation' => 'new-table-operation']);
$newChunk = $migrator->exportSqlChunk($newContext);
$newApplied = is_wp_error($newChunk) ? $newChunk : $migrator->applySqlChunk($newChunk);
$newFinalized = is_wp_error($newApplied)
    ? $newApplied
    : $migrator->finalizeReplace('new-table-operation', [['name' => 'source_options', 'rows' => 2]], 'source_');
$newFinalizedAgain = is_wp_error($newFinalized)
    ? $newFinalized
    : $migrator->finalizeReplace('new-table-operation', [['name' => 'source_options', 'rows' => 2]], 'source_');
if (is_wp_error($newFinalizedAgain)) {
    fwrite(STDERR, "Retrying activation of a newly created table was not idempotent.\n");
    exit(1);
}

echo "Database Push and Pull share a checksummed SQL dump, durable retries, and atomic activation.\n";
