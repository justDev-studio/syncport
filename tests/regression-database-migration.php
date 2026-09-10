<?php

declare(strict_types=1);

define('ARRAY_N', 'ARRAY_N');

final class DatabaseScopeWpdb
{
    public string $prefix = 'target_';

    public function get_results(string $query, string $format): array
    {
        return [
            ['target_options'],
            ['target_posts'],
            ['unrelated_table'],
            ['_mig_target_options'],
            ['target_syncport_operations'],
        ];
    }

    public function get_var(string $query): string
    {
        return '1';
    }
}

function sanitize_text_field(mixed $value): string { return (string) $value; }
function esc_sql(string $value): string { return addslashes($value); }

$GLOBALS['wpdb'] = new DatabaseScopeWpdb();

require dirname(__DIR__) . '/src/Migration/ManifestBuilder.php';

$builder = new JustDev\SyncPort\Migration\ManifestBuilder();
$tablesMethod = new ReflectionMethod($builder, 'tables');
$fullDatabase = $tablesMethod->invoke($builder, []);
$selected = $tablesMethod->invoke($builder, ['unrelated_table']);

if (array_column($fullDatabase, 'name') !== ['target_options', 'target_posts']) {
    fwrite(STDERR, "Full database mode must migrate every table with the current WordPress prefix.\n");
    exit(1);
}
if (array_column($selected, 'name') !== ['unrelated_table']) {
    fwrite(STDERR, "Manual database mode must migrate only explicitly selected tables.\n");
    exit(1);
}

echo "Full and manually selected database table scopes match jd-wp-sync-db.\n";
