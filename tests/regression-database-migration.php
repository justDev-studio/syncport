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

final class FakeWpdb
{
    public string $prefix = 'target_';
    public string $last_error = '';
    public array $queries = [];
    public array $replaced = [];
    public array $inserted = [];

    public function get_results(string $query, string $format): array
    {
        if ($query === 'SHOW FULL TABLES') {
            return [['source_options']];
        }
        if (str_starts_with($query, 'SHOW KEYS')) {
            return [['Column_name' => 'option_id']];
        }
        if (str_starts_with($query, 'SELECT * FROM `source_options`')) {
            return [
                ['option_id' => '1', 'option_name' => 'source_user_roles', 'option_value' => 'https://source.example/path'],
                ['option_id' => '2', 'option_name' => 'home', 'option_value' => serialize(['url' => 'https://source.example'])],
            ];
        }
        return [];
    }

    public function get_row(string $query, string $format): array|null
    {
        if ($query === 'SHOW CREATE TABLE `source_options`') {
            return ['source_options', 'CREATE TABLE `source_options` (`option_id` bigint NOT NULL, PRIMARY KEY (`option_id`))'];
        }
        if (str_starts_with($query, 'SELECT chunk_hash, result FROM `target_syncport_chunks`') && $this->inserted !== []) {
            foreach (array_reverse($this->inserted) as [, $receipt]) {
                if (str_contains($query, "'" . $receipt['operation_uuid'] . "'")
                    && str_contains($query, "'" . $receipt['table_name'] . "'")
                    && str_ends_with($query, '= ' . $receipt['chunk_offset'])) {
                    return ['chunk_hash' => $receipt['chunk_hash'], 'result' => $receipt['result']];
                }
            }
        }
        return null;
    }

    public function get_var(string $query): string|null
    {
        if ($query === "SHOW TABLES LIKE 'target_options'") {
            return 'target_options';
        }
        return null;
    }

    public function prepare(string $query, mixed ...$values): string
    {
        foreach ($values as $value) {
            $query = preg_replace('/%[ds]/', is_int($value) ? (string) $value : "'" . addslashes((string) $value) . "'", $query, 1);
        }
        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        return 1;
    }

    public function replace(string $table, array $row): int|false
    {
        $this->replaced[] = [$table, $row];
        return 1;
    }

    public function insert(string $table, array $row): int|false
    {
        $this->inserted[] = [$table, $row];
        return 1;
    }
}

function __(string $message): string { return $message; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function is_serialized(mixed $value): bool { return is_string($value) && @unserialize($value) !== false; }
function maybe_unserialize(mixed $value): mixed { return is_serialized($value) ? unserialize($value) : $value; }
function maybe_serialize(mixed $value): mixed { return is_array($value) || is_object($value) ? serialize($value) : $value; }
function wp_json_encode(mixed $value): string|false { return json_encode($value); }
function get_current_user_id(): int { return 0; }

$GLOBALS['wpdb'] = new FakeWpdb();

require dirname(__DIR__) . '/src/Migration/DatabaseMigrator.php';

$migrator = new JustDev\SyncPort\Migration\DatabaseMigrator();
$chunk = $migrator->exportChunk('source_options', 0, 100);
if (is_wp_error($chunk)) {
    fwrite(STDERR, $chunk->get_error_message() . PHP_EOL);
    exit(1);
}
if (($chunk['rows'][1]['option_name'] ?? '') !== 'home' || ($chunk['done'] ?? false) !== true) {
    fwrite(STDERR, "The source table chunk was not exported correctly.\n");
    exit(1);
}

$result = $migrator->applyChunk(
    'operation-uuid',
    ['name' => 'source_options', 'rows' => 2],
    $chunk,
    'replace',
    'source_',
    ['https://source.example' => 'https://target.example']
);
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . PHP_EOL);
    exit(1);
}

$queries = implode("\n", $GLOBALS['wpdb']->queries);
if (!str_contains($queries, 'CREATE TABLE `target_syncport_bak_') || !str_contains($queries, 'DROP TABLE `target_options`')) {
    fwrite(STDERR, "Replace mode did not back up and replace the target table.\n");
    exit(1);
}
if (!str_contains($queries, 'CREATE TABLE `target_options`')) {
    fwrite(STDERR, "The source schema was not mapped to the target prefix.\n");
    exit(1);
}
if (($GLOBALS['wpdb']->replaced[0][0] ?? '') !== 'target_options') {
    fwrite(STDERR, "Rows were not written to the mapped target table.\n");
    exit(1);
}
if (($GLOBALS['wpdb']->replaced[0][1]['option_name'] ?? '') !== 'target_user_roles') {
    fwrite(STDERR, "The WordPress prefix inside the options row was not mapped.\n");
    exit(1);
}
$serialized = maybe_unserialize($GLOBALS['wpdb']->replaced[1][1]['option_value'] ?? '');
if (($serialized['url'] ?? '') !== 'https://target.example') {
    fwrite(STDERR, "Serialized URLs were not replaced safely.\n");
    exit(1);
}

$queryCount = count($GLOBALS['wpdb']->queries);
$rowCount = count($GLOBALS['wpdb']->replaced);
$retry = $migrator->applyChunk(
    'operation-uuid',
    ['name' => 'source_options', 'rows' => 2],
    $chunk,
    'replace',
    'source_',
    ['https://source.example' => 'https://target.example']
);
if (is_wp_error($retry) || count($GLOBALS['wpdb']->queries) !== $queryCount || count($GLOBALS['wpdb']->replaced) !== $rowCount) {
    fwrite(STDERR, "Retrying an applied chunk must reuse its durable receipt.\n");
    exit(1);
}

$queryCount = count($GLOBALS['wpdb']->queries);
$rowCount = count($GLOBALS['wpdb']->replaced);
$merge = $migrator->applyChunk(
    'merge-operation',
    ['name' => 'source_options', 'rows' => 2],
    $chunk,
    'merge',
    'source_',
    ['https://source.example' => 'https://target.example']
);
$mergeQueries = implode("\n", array_slice($GLOBALS['wpdb']->queries, $queryCount));
if (is_wp_error($merge) || str_contains($mergeQueries, 'DROP TABLE') || count($GLOBALS['wpdb']->replaced) !== $rowCount + 2) {
    fwrite(STDERR, "Merge mode must preserve the target table and upsert source rows.\n");
    exit(1);
}

$protectedChunk = $chunk;
$protectedChunk['rows'][] = ['option_id' => '3', 'option_name' => 'syncport_api_key', 'option_value' => 'source-key'];
$rowCount = count($GLOBALS['wpdb']->replaced);
$protected = $migrator->applyChunk(
    'protected-operation',
    ['name' => 'source_options', 'rows' => 3],
    $protectedChunk,
    'merge',
    'source_',
    ['https://source.example' => 'https://target.example']
);
if (is_wp_error($protected) || count($GLOBALS['wpdb']->replaced) !== $rowCount + 2) {
    fwrite(STDERR, "Target SyncPort operational options must survive database migration.\n");
    exit(1);
}

echo "Database chunks export, back up, map, and apply successfully.\n";
