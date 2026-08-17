<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class DatabaseMigrator
{
    public const CHUNK_SIZE = 500;

    private const MAX_CHUNK_BYTES = 1048576;
    private const MAX_STATEMENT_BYTES = 262144;

    /** @return array<string, mixed>|\WP_Error */
    public function exportChunk(string $table, int $offset, int $limit = self::CHUNK_SIZE): array|\WP_Error
    {
        global $wpdb;

        $allowed = array_map(
            static fn (array $row): string => (string) $row[0],
            array_filter(
                $wpdb->get_results('SHOW FULL TABLES', ARRAY_N),
                static fn (array $row): bool => !isset($row[1]) || strtoupper((string) $row[1]) === 'BASE TABLE'
            )
        );
        if (!in_array($table, $allowed, true) || $this->isProtectedTable($table, (string) $wpdb->prefix)) {
            return new \WP_Error('syncport_invalid_table', __('The selected source table does not exist.', 'syncport'));
        }

        $offset = max(0, $offset);
        $limit = max(1, min(self::CHUNK_SIZE, $limit));
        $identifier = $this->identifier($table);
        $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$identifier}`", ARRAY_N);
        if (!is_array($createRow) || empty($createRow[1])) {
            return new \WP_Error('syncport_table_schema', __('The source table schema could not be read.', 'syncport'));
        }

        $primary = $wpdb->get_results("SHOW KEYS FROM `{$identifier}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        $order = $primary ? ' ORDER BY ' . implode(', ', array_map(
            fn (array $column): string => '`' . $this->identifier((string) $column['Column_name']) . '`',
            $primary
        )) : '';
        $query = $wpdb->prepare("SELECT * FROM `{$identifier}`{$order} LIMIT %d OFFSET %d", $limit, $offset);
        $rows = $wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return new \WP_Error('syncport_table_export', __('The source table rows could not be read.', 'syncport'));
        }

        $limitedRows = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $rowBytes = strlen(serialize($row));
            if ($limitedRows !== [] && $bytes + $rowBytes > self::MAX_CHUNK_BYTES) {
                break;
            }
            $limitedRows[] = $row;
            $bytes += $rowBytes;
        }

        $count = count($limitedRows);
        return [
            'table' => $table,
            'create_sql' => (string) $createRow[1],
            'offset' => $offset,
            'next_offset' => $offset + $count,
            'rows' => $limitedRows,
            'done' => $count === count($rows) && count($rows) < $limit,
        ];
    }

    /** @param array<string, mixed> $table @param array<string, mixed> $chunk @param array<string, string> $replace @param array<int, array<string, mixed>> $selectedTables @return array<string, mixed>|\WP_Error */
    public function applyChunk(
        string $operation,
        array $table,
        array $chunk,
        string $mode,
        string $sourcePrefix,
        array $replace = [],
        array $selectedTables = []
    ): array|\WP_Error {
        global $wpdb;

        $sourceTable = (string) ($table['name'] ?? '');
        if ($sourceTable === '' || $sourceTable !== (string) ($chunk['table'] ?? '')) {
            return new \WP_Error('syncport_table_mismatch', __('The database chunk does not match the selected table.', 'syncport'));
        }
        if (!in_array($mode, ['replace', 'merge'], true)) {
            return new \WP_Error('syncport_table_mode', __('The selected database merge mode is invalid.', 'syncport'));
        }

        $targetPrefix = (string) $wpdb->prefix;
        $targetTable = $this->targetTable($sourceTable, $sourcePrefix, $targetPrefix);
        $writeTable = $mode === 'replace'
            ? $this->stagingTable($operation, $targetTable, $targetPrefix)
            : $targetTable;
        $targetExisted = $this->tableExists($targetTable);
        $offset = max(0, (int) ($chunk['offset'] ?? 0));
        $chunkHash = hash('sha256', (string) wp_json_encode($chunk));
        $receipt = $this->receipt($operation, $targetTable, $offset, $chunkHash);
        if (is_wp_error($receipt)) {
            return $receipt;
        }
        if (is_array($receipt)) {
            return $receipt;
        }

        if ($offset === 0) {
            $prepared = $this->prepareTarget(
                $targetTable,
                $writeTable,
                (string) ($chunk['create_sql'] ?? ''),
                $mode,
                $sourcePrefix,
                $operation,
                $selectedTables
            );
            if (is_wp_error($prepared)) {
                return $prepared;
            }
        } elseif (!$this->tableExists($writeTable)) {
            return new \WP_Error('syncport_target_table_missing', __('The database staging table is missing.', 'syncport'));
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            return new \WP_Error('syncport_table_transaction', $wpdb->last_error ?: __('The database chunk transaction could not be started.', 'syncport'));
        }

        $rows = [];
        foreach ((array) ($chunk['rows'] ?? []) as $row) {
            if (!is_array($row) || $this->preservesTargetRow($targetTable, $row)) {
                continue;
            }
            $rows[] = $this->rewriteRow($row, $sourceTable, $sourcePrefix, $targetPrefix, $replace);
        }
        $written = $this->writeRows($writeTable, $rows);
        if (is_wp_error($written)) {
            $wpdb->query('ROLLBACK');
            return $written;
        }

        $result = [
            'table' => $targetTable,
            'written' => $written,
            'next_offset' => max($offset, (int) ($chunk['next_offset'] ?? $offset + $written)),
            'done' => !empty($chunk['done']),
            'target_existed' => $offset === 0 ? $targetExisted : null,
        ];
        if (!$wpdb->insert($this->receiptTable(), [
            'operation_uuid' => $operation,
            'table_name' => $targetTable,
            'chunk_offset' => $offset,
            'chunk_hash' => $chunkHash,
            'result' => wp_json_encode($result),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('syncport_chunk_receipt', $wpdb->last_error ?: __('The database chunk receipt could not be stored.', 'syncport'));
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('syncport_table_commit', $wpdb->last_error ?: __('The database chunk transaction could not be committed.', 'syncport'));
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $tables @return array<string, int>|\WP_Error */
    public function finalizeReplace(string $operation, array $tables, string $sourcePrefix): array|\WP_Error
    {
        global $wpdb;

        $renames = [];
        foreach ($tables as $table) {
            $sourceTable = (string) ($table['name'] ?? '');
            if ($sourceTable === '') {
                continue;
            }
            $targetPrefix = (string) $wpdb->prefix;
            $targetTable = $this->targetTable($sourceTable, $sourcePrefix, $targetPrefix);
            $stagingTable = $this->stagingTable($operation, $targetTable, $targetPrefix);
            $backupTable = $this->backupTable($operation, $targetTable, $targetPrefix);
            if (!$this->tableExists($stagingTable)) {
                $firstChunk = $this->firstChunkResult($operation, $targetTable);
                $newTableWasFinalized = is_array($firstChunk) && ($firstChunk['target_existed'] ?? null) === false;
                if ($this->tableExists($targetTable) && ($this->tableExists($backupTable) || $newTableWasFinalized)) {
                    continue;
                }
                return new \WP_Error('syncport_staging_table_missing', __('A staged database table is missing; the live database was not changed.', 'syncport'));
            }
            if ($this->tableExists($backupTable)) {
                return new \WP_Error('syncport_backup_table_exists', __('A database backup from this operation already exists; the live database was not changed.', 'syncport'));
            }
            if ($this->tableExists($targetTable)) {
                $renames[] = '`' . $this->identifier($targetTable) . '` TO `' . $this->identifier($backupTable) . '`';
            }
            $renames[] = '`' . $this->identifier($stagingTable) . '` TO `' . $this->identifier($targetTable) . '`';
        }

        if ($renames !== [] && $wpdb->query('RENAME TABLE ' . implode(', ', $renames)) === false) {
            return new \WP_Error(
                'syncport_table_finalize',
                $wpdb->last_error ?: __('The staged database tables could not be activated; the live database was not changed.', 'syncport')
            );
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        return ['tables' => count($tables)];
    }

    /** @return true|\WP_Error */
    private function prepareTarget(
        string $targetTable,
        string $writeTable,
        string $createSql,
        string $mode,
        string $sourcePrefix,
        string $operation,
        array $selectedTables
    ): true|\WP_Error {
        global $wpdb;

        if ($createSql === '') {
            return new \WP_Error('syncport_table_schema', __('The database chunk does not include a source table schema.', 'syncport'));
        }

        $targetExists = $this->tableExists($targetTable);
        $preservedRows = $mode === 'replace' && $targetExists ? $this->preservedRows($targetTable) : [];
        if ($mode === 'replace' && $this->tableExists($writeTable)
            && $wpdb->query("DROP TABLE `{$this->identifier($writeTable)}`") === false) {
            return new \WP_Error('syncport_staging_table_drop', __('The previous database staging table could not be reset.', 'syncport'));
        }

        if ($mode === 'replace' || !$targetExists) {
            $mappedCreateSql = $sourcePrefix === ''
                ? $createSql
                : str_replace('`' . $sourcePrefix, '`' . (string) $wpdb->prefix, $createSql);
            $mappedCreateSql = preg_replace_callback(
                '/\bREFERENCES\s+`([^`]+)`/i',
                function (array $matches) use ($operation, $selectedTables, $sourcePrefix, $wpdb): string {
                    $target = $this->targetTable((string) $matches[1], $sourcePrefix, (string) $wpdb->prefix);
                    $selectedTargets = array_map(
                        fn (string $table): string => $this->targetTable($table, $sourcePrefix, (string) $wpdb->prefix),
                        array_map('strval', array_column($selectedTables, 'name'))
                    );
                    $selected = in_array($target, $selectedTargets, true);
                    $referencedTable = $selected
                        ? $this->stagingTable($operation, $target, (string) $wpdb->prefix)
                        : $target;
                    return 'REFERENCES `' . $this->identifier($referencedTable) . '`';
                },
                $mappedCreateSql
            );
            $constraintIndex = 0;
            $mappedCreateSql = preg_replace_callback(
                '/\bCONSTRAINT\s+`[^`]+`/i',
                function () use ($operation, $writeTable, &$constraintIndex): string {
                    $constraintIndex++;
                    $name = 'syncport_' . substr(hash('sha256', $operation . '|' . $writeTable . '|' . $constraintIndex), 0, 32);
                    return 'CONSTRAINT `' . $name . '`';
                },
                (string) $mappedCreateSql
            );
            $targetCreateSql = preg_replace(
                '/^CREATE TABLE(?: IF NOT EXISTS)?\s+`(?:``|[^`])+`/i',
                'CREATE TABLE `' . $this->identifier($writeTable) . '`',
                $mappedCreateSql,
                1,
                $replacements
            );
            $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
            $created = $replacements === 1 && is_string($targetCreateSql) && $wpdb->query($targetCreateSql) !== false;
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            if (!$created) {
                return new \WP_Error(
                    'syncport_table_create',
                    $wpdb->last_error ?: __('The target table schema could not be created.', 'syncport')
                );
            }
            foreach ($preservedRows as $row) {
                if ($wpdb->replace($writeTable, $row) === false) {
                    return new \WP_Error(
                        'syncport_table_preserve',
                        $wpdb->last_error ?: __('The target operational database rows could not be preserved.', 'syncport')
                    );
                }
            }
        }
        return true;
    }

    /** @param array<int, array<string, mixed>> $rows @return int|\WP_Error */
    private function writeRows(string $table, array $rows): int|\WP_Error
    {
        global $wpdb;
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $prefix = 'REPLACE INTO `' . $this->identifier($table) . '` (' . implode(', ', array_map(
            fn (string $column): string => '`' . $this->identifier($column) . '`',
            $columns
        )) . ') VALUES ';
        $values = [];
        $bytes = strlen($prefix);
        foreach ($rows as $row) {
            $line = '(' . implode(', ', array_map(function (string $column) use ($row, $wpdb): string {
                $value = $row[$column] ?? null;
                return $value === null ? 'NULL' : (string) $wpdb->prepare('%s', (string) $value);
            }, $columns)) . ')';
            if ($values !== [] && $bytes + strlen($line) + 2 > self::MAX_STATEMENT_BYTES) {
                if ($wpdb->query($prefix . implode(', ', $values)) === false) {
                    return new \WP_Error('syncport_table_write', $wpdb->last_error ?: __('A database row batch could not be written.', 'syncport'));
                }
                $values = [];
                $bytes = strlen($prefix);
            }
            $values[] = $line;
            $bytes += strlen($line) + 2;
        }
        if ($values !== [] && $wpdb->query($prefix . implode(', ', $values)) === false) {
            return new \WP_Error('syncport_table_write', $wpdb->last_error ?: __('A database row batch could not be written.', 'syncport'));
        }
        return count($rows);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** @return array<string, mixed>|null|\WP_Error */
    private function receipt(string $operation, string $table, int $offset, string $chunkHash): array|null|\WP_Error
    {
        global $wpdb;
        $receipt = $wpdb->get_row($wpdb->prepare(
            "SELECT chunk_hash, result FROM `{$this->identifier($this->receiptTable())}` WHERE operation_uuid = %s AND table_name = %s AND chunk_offset = %d",
            $operation,
            $table,
            $offset
        ), ARRAY_A);
        if (!is_array($receipt)) {
            return null;
        }
        if (!hash_equals((string) ($receipt['chunk_hash'] ?? ''), $chunkHash)) {
            return new \WP_Error('syncport_chunk_changed', __('The source database chunk changed during migration.', 'syncport'));
        }
        $result = json_decode((string) ($receipt['result'] ?? ''), true);
        return is_array($result) ? $result : null;
    }

    private function receiptTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'syncport_chunks';
    }

    /** @return array<string, mixed>|null */
    private function firstChunkResult(string $operation, string $table): ?array
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT result FROM `{$this->identifier($this->receiptTable())}` WHERE operation_uuid = %s AND table_name = %s AND chunk_offset = 0",
            $operation,
            $table
        ));
        $decoded = is_string($result) ? json_decode($result, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    private function isProtectedTable(string $table, string $prefix): bool
    {
        return in_array($table, [$prefix . 'syncport_operations', $prefix . 'syncport_chunks'], true)
            || str_starts_with($table, $prefix . 'syncport_bak_')
            || str_starts_with($table, $prefix . 'syncport_tmp_');
    }

    /** @return array<int, array<string, mixed>> */
    private function preservedRows(string $targetTable): array
    {
        global $wpdb;
        $identifier = $this->identifier($targetTable);
        if ($targetTable === $wpdb->prefix . 'options') {
            return (array) $wpdb->get_results(
                "SELECT * FROM `{$identifier}` WHERE option_name = 'active_plugins' OR option_name LIKE 'syncport\\_%'",
                ARRAY_A
            );
        }

        $userId = get_current_user_id();
        if ($userId > 0 && $targetTable === $wpdb->prefix . 'users') {
            return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$identifier}` WHERE ID = %d", $userId), ARRAY_A);
        }
        if ($userId > 0 && $targetTable === $wpdb->prefix . 'usermeta') {
            return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$identifier}` WHERE user_id = %d", $userId), ARRAY_A);
        }
        return [];
    }

    /** @param array<string, mixed> $row */
    private function preservesTargetRow(string $targetTable, array $row): bool
    {
        global $wpdb;
        if ($targetTable === $wpdb->prefix . 'options') {
            $name = (string) ($row['option_name'] ?? '');
            return $name === 'active_plugins' || str_starts_with($name, 'syncport_');
        }

        $userId = get_current_user_id();
        if ($userId < 1) {
            return false;
        }
        if ($targetTable === $wpdb->prefix . 'users') {
            return (int) ($row['ID'] ?? 0) === $userId;
        }
        return $targetTable === $wpdb->prefix . 'usermeta' && (int) ($row['user_id'] ?? 0) === $userId;
    }

    private function targetTable(string $sourceTable, string $sourcePrefix, string $targetPrefix): string
    {
        if ($sourcePrefix !== '' && str_starts_with($sourceTable, $sourcePrefix)) {
            return $targetPrefix . substr($sourceTable, strlen($sourcePrefix));
        }
        return $sourceTable;
    }

    private function backupTable(string $operation, string $targetTable, string $targetPrefix): string
    {
        $suffix = 'syncport_bak_' . substr(hash('sha256', $operation . '|' . $targetTable), 0, 16);
        return substr($targetPrefix, 0, 64 - strlen($suffix)) . $suffix;
    }

    private function stagingTable(string $operation, string $targetTable, string $targetPrefix): string
    {
        $suffix = 'syncport_tmp_' . substr(hash('sha256', $operation . '|' . $targetTable), 0, 16);
        return substr($targetPrefix, 0, 64 - strlen($suffix)) . $suffix;
    }

    /** @param array<string, mixed> $row @param array<string, string> $replace @return array<string, mixed> */
    private function rewriteRow(array $row, string $sourceTable, string $sourcePrefix, string $targetPrefix, array $replace): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = $this->replaceValue($value, $replace);
        }

        $suffix = $sourcePrefix !== '' && str_starts_with($sourceTable, $sourcePrefix)
            ? substr($sourceTable, strlen($sourcePrefix))
            : '';
        $prefixColumn = $suffix === 'options' ? 'option_name' : ($suffix === 'usermeta' ? 'meta_key' : '');
        if ($prefixColumn !== '' && isset($row[$prefixColumn]) && is_string($row[$prefixColumn])
            && str_starts_with($row[$prefixColumn], $sourcePrefix)) {
            $row[$prefixColumn] = $targetPrefix . substr($row[$prefixColumn], strlen($sourcePrefix));
        }
        return $row;
    }

    /** @param array<string, string> $replace */
    private function replaceValue(mixed $value, array $replace): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->replaceValue($item, $replace), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        if (is_serialized($value)) {
            return serialize($this->replaceValue(maybe_unserialize($value), $replace));
        }
        return str_replace(array_keys($replace), array_values($replace), $value);
    }

    private function identifier(string $identifier): string
    {
        return str_replace('`', '``', substr($identifier, 0, 64));
    }
}
