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
    public array $tables = ['target_options' => true];
    public array $shownTables = [['source_options']];

    public function get_results(string $query, string $format): array
    {
        if ($query === 'SHOW FULL TABLES') {
            return $this->shownTables;
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
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/", $query, $matches)) {
            return isset($this->tables[$matches[1]]) ? $matches[1] : null;
        }
        if (str_starts_with($query, 'SELECT COUNT(*) FROM `')) {
            return '1';
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
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $query, $matches)) {
            $this->tables[$matches[1]] = true;
        }
        if (preg_match('/^DROP TABLE `([^`]+)`/', $query, $matches)) {
            unset($this->tables[$matches[1]]);
        }
        if (str_starts_with($query, 'RENAME TABLE ')) {
            preg_match_all('/`([^`]+)` TO `([^`]+)`/', $query, $matches, PREG_SET_ORDER);
            foreach ($matches as $rename) {
                unset($this->tables[$rename[1]]);
                $this->tables[$rename[2]] = true;
            }
        }
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
function sanitize_text_field(mixed $value): string { return (string) $value; }
function esc_sql(string $value): string { return addslashes($value); }

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
if (str_contains($queries, 'DROP TABLE `target_options`')) {
    fwrite(STDERR, "Replace mode changed the live table before all chunks were staged.\n");
    exit(1);
}
if (!str_contains($queries, 'CREATE TABLE `target_syncport_tmp_')) {
    fwrite(STDERR, "Replace mode did not create a staging table.\n");
    exit(1);
}
if (!str_starts_with((string) ($GLOBALS['wpdb']->replaced[0][0] ?? ''), 'target_syncport_tmp_')
    || ($GLOBALS['wpdb']->replaced[0][1]['option_name'] ?? '') !== 'syncport_connections') {
    fwrite(STDERR, "The local SyncPort connection was not preserved in the staging table.\n");
    exit(1);
}
if (!str_contains($queries, "'target_user_roles'")) {
    fwrite(STDERR, "The WordPress prefix inside the options row was not mapped.\n");
    exit(1);
}
if (!str_contains($queries, 'https://target.example')) {
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
if (is_wp_error($merge) || str_contains($mergeQueries, 'DROP TABLE') || !str_contains($mergeQueries, 'REPLACE INTO `target_options`')) {
    fwrite(STDERR, "Merge mode must preserve the target table and upsert source rows.\n");
    exit(1);
}

$protectedChunk = $chunk;
$protectedChunk['rows'][] = ['option_id' => '3', 'option_name' => 'syncport_api_key', 'option_value' => 'source-key'];
$queryCount = count($GLOBALS['wpdb']->queries);
$protected = $migrator->applyChunk(
    'protected-operation',
    ['name' => 'source_options', 'rows' => 3],
    $protectedChunk,
    'merge',
    'source_',
    ['https://source.example' => 'https://target.example']
);
$protectedQueries = implode("\n", array_slice($GLOBALS['wpdb']->queries, $queryCount));
if (is_wp_error($protected) || str_contains($protectedQueries, 'source-key')) {
    fwrite(STDERR, "Target SyncPort operational options must survive database migration.\n");
    exit(1);
}

$finalized = $migrator->finalizeReplace('operation-uuid', [['name' => 'source_options', 'rows' => 2]], 'source_');
$queries = implode("\n", $GLOBALS['wpdb']->queries);
if (is_wp_error($finalized)
    || !str_contains($queries, 'RENAME TABLE `target_options` TO `target_syncport_bak_')
    || !str_contains($queries, '`target_syncport_tmp_')
    || !str_contains($queries, '` TO `target_options`')) {
    fwrite(STDERR, "Replace mode did not atomically activate the staged table.\n");
    exit(1);
}
$finalizedAgain = $migrator->finalizeReplace('operation-uuid', [['name' => 'source_options', 'rows' => 2]], 'source_');
if (is_wp_error($finalizedAgain)) {
    fwrite(STDERR, "Retrying finalization must recognize already activated tables.\n");
    exit(1);
}

$foreignChunk = $chunk;
$foreignChunk['create_sql'] = 'CREATE TABLE `source_options` (`option_id` bigint NOT NULL, CONSTRAINT `source_options_fk` FOREIGN KEY (`option_id`) REFERENCES `source_other` (`id`))';
$queryCount = count($GLOBALS['wpdb']->queries);
$foreignResult = $migrator->applyChunk(
    'foreign-operation',
    ['name' => 'source_options', 'rows' => 2],
    $foreignChunk,
    'replace',
    'source_',
    [],
    [['name' => 'source_options'], ['name' => 'source_other']]
);
$foreignQueries = implode("\n", array_slice($GLOBALS['wpdb']->queries, $queryCount));
if (is_wp_error($foreignResult)
    || !str_contains($foreignQueries, 'CONSTRAINT `syncport_')
    || !str_contains($foreignQueries, 'REFERENCES `target_syncport_tmp_')) {
    fwrite(STDERR, "Foreign keys between selected tables must point to operation staging tables.\n");
    exit(1);
}

$queryCount = count($GLOBALS['wpdb']->queries);
$partialForeignResult = $migrator->applyChunk(
    'partial-foreign-operation',
    ['name' => 'source_options', 'rows' => 2],
    $foreignChunk,
    'replace',
    'source_',
    [],
    [['name' => 'source_options']]
);
$partialForeignQueries = implode("\n", array_slice($GLOBALS['wpdb']->queries, $queryCount));
if (is_wp_error($partialForeignResult) || !str_contains($partialForeignQueries, 'REFERENCES `target_other`')) {
    fwrite(STDERR, "Foreign keys to unselected tables must keep pointing to the live target table.\n");
    exit(1);
}

$GLOBALS['wpdb']->shownTables = [
    ['target_options'],
    ['unrelated_table'],
    ['target_syncport_operations'],
    ['target_syncport_tmp_deadbeef'],
];
require dirname(__DIR__) . '/src/Migration/ManifestBuilder.php';
$builder = new JustDev\SyncPort\Migration\ManifestBuilder();
$tablesMethod = new ReflectionMethod($builder, 'tables');
$fullDatabase = $tablesMethod->invoke($builder, []);
if (array_column($fullDatabase, 'name') !== ['target_options']) {
    fwrite(STDERR, "An empty selection must migrate only tables with the current WordPress prefix.\n");
    exit(1);
}

echo "Database chunks stage, preserve, atomically activate, and scope full-database tables successfully.\n";
