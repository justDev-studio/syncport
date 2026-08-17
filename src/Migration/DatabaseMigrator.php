<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class DatabaseMigrator
{
    public const CHUNK_SIZE = 100;

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
        $limit = max(1, min(500, $limit));
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

        $count = count($rows);
        return [
            'table' => $table,
            'create_sql' => (string) $createRow[1],
            'offset' => $offset,
            'next_offset' => $offset + $count,
            'rows' => $rows,
            'done' => $count < $limit,
        ];
    }

    /** @param array<string, mixed> $table @param array<string, mixed> $chunk @param array<string, string> $replace @return array<string, mixed>|\WP_Error */
    public function applyChunk(
        string $operation,
        array $table,
        array $chunk,
        string $mode,
        string $sourcePrefix,
        array $replace = []
    ): array|\WP_Error {
        global $wpdb;

        $sourceTable = (string) ($table['name'] ?? '');
        if ($sourceTable === '' || $sourceTable !== (string) ($chunk['table'] ?? '')) {
            return new \WP_Error('syncport_table_mismatch', __('The database chunk does not match the selected table.', 'syncport'));
        }
        if (!in_array($mode, ['replace', 'merge'], true)) {
            return new \WP_Error('syncport_table_mode', __('The selected database merge mode is invalid.', 'syncport'));
        }

        $targetTable = $this->targetTable($sourceTable, $sourcePrefix, (string) $wpdb->prefix);
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
            $prepared = $this->prepareTarget($operation, $targetTable, (string) ($chunk['create_sql'] ?? ''), $mode);
            if (is_wp_error($prepared)) {
                return $prepared;
            }
        } elseif (!$this->tableExists($targetTable)) {
            return new \WP_Error('syncport_target_table_missing', __('The target table is missing while applying a database chunk.', 'syncport'));
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            return new \WP_Error('syncport_table_transaction', $wpdb->last_error ?: __('The database chunk transaction could not be started.', 'syncport'));
        }

        $written = 0;
        foreach ((array) ($chunk['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->preservesTargetRow($targetTable, $row)) {
                continue;
            }
            $row = $this->rewriteRow($row, $sourceTable, $sourcePrefix, (string) $wpdb->prefix, $replace);
            if ($wpdb->replace($targetTable, $row) === false) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error(
                    'syncport_table_write',
                    $wpdb->last_error ?: __('A database row could not be written to the target table.', 'syncport')
                );
            }
            $written++;
        }

        $result = [
            'table' => $targetTable,
            'written' => $written,
            'next_offset' => max($offset, (int) ($chunk['next_offset'] ?? $offset + $written)),
            'done' => !empty($chunk['done']),
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

    /** @return true|\WP_Error */
    private function prepareTarget(string $operation, string $targetTable, string $createSql, string $mode): true|\WP_Error
    {
        global $wpdb;

        if ($createSql === '') {
            return new \WP_Error('syncport_table_schema', __('The database chunk does not include a source table schema.', 'syncport'));
        }

        $targetExists = $this->tableExists($targetTable);
        $preservedRows = $mode === 'replace' && $targetExists ? $this->preservedRows($targetTable) : [];
        if ($targetExists) {
            $backupTable = $this->backupTable($operation, $targetTable, (string) $wpdb->prefix);
            if (!$this->tableExists($backupTable)) {
                if ($wpdb->query("CREATE TABLE `{$this->identifier($backupTable)}` LIKE `{$this->identifier($targetTable)}`") === false
                    || $wpdb->query("INSERT INTO `{$this->identifier($backupTable)}` SELECT * FROM `{$this->identifier($targetTable)}`") === false) {
                    return new \WP_Error(
                        'syncport_table_backup',
                        $wpdb->last_error ?: __('The target table backup could not be created.', 'syncport')
                    );
                }
            }
        }

        if ($mode === 'replace' && $targetExists
            && $wpdb->query("DROP TABLE `{$this->identifier($targetTable)}`") === false) {
            return new \WP_Error('syncport_table_drop', $wpdb->last_error ?: __('The target table could not be replaced.', 'syncport'));
        }

        if ($mode === 'replace' || !$targetExists) {
            $targetCreateSql = preg_replace(
                '/^CREATE TABLE(?: IF NOT EXISTS)?\s+`(?:``|[^`])+`/i',
                'CREATE TABLE `' . $this->identifier($targetTable) . '`',
                $createSql,
                1,
                $replacements
            );
            if ($replacements !== 1 || !is_string($targetCreateSql) || $wpdb->query($targetCreateSql) === false) {
                return new \WP_Error(
                    'syncport_table_create',
                    $wpdb->last_error ?: __('The target table schema could not be created.', 'syncport')
                );
            }
            foreach ($preservedRows as $row) {
                if ($wpdb->replace($targetTable, $row) === false) {
                    return new \WP_Error(
                        'syncport_table_preserve',
                        $wpdb->last_error ?: __('The target operational database rows could not be preserved.', 'syncport')
                    );
                }
            }
        }
        return true;
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

    private function isProtectedTable(string $table, string $prefix): bool
    {
        return in_array($table, [$prefix . 'syncport_operations', $prefix . 'syncport_chunks'], true)
            || str_starts_with($table, $prefix . 'syncport_bak_');
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
        return substr($targetPrefix . $suffix, 0, 64);
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
