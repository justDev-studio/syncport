<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class DatabaseMigrator
{
    public const PROTOCOL = 'sql-dump-v1';
    public const CHUNK_SIZE = 500;

    private const MAX_CHUNK_BYTES = 1048576;
    private const MAX_DECODE_BYTES = 4194304;
    private const MAX_STATEMENT_BYTES = 50000;

    /** @return array<string, mixed>|\WP_Error */
    public function exportChunk(string $table, int $offset, int $limit = self::CHUNK_SIZE, array $cursor = []): array|\WP_Error
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

        $primary = $wpdb->get_results("SHOW KEYS FROM `{$identifier}` WHERE Key_name = 'PRIMARY' ORDER BY Seq_in_index", ARRAY_A);
        $primaryColumns = array_values(array_filter(array_map(
            static fn (array $column): string => (string) ($column['Column_name'] ?? ''),
            is_array($primary) ? $primary : []
        )));
        $order = $primary ? ' ORDER BY ' . implode(', ', array_map(
            fn (array $column): string => '`' . $this->identifier((string) $column['Column_name']) . '`',
            $primary
        )) : '';
        $where = $this->cursorWhere($primaryColumns, $cursor);
        $query = $where === ''
            ? $wpdb->prepare("SELECT * FROM `{$identifier}`{$order} LIMIT %d OFFSET %d", $limit, $offset)
            : $wpdb->prepare("SELECT * FROM `{$identifier}` WHERE {$where}{$order} LIMIT %d", $limit);
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
        $nextCursor = [];
        if ($primaryColumns !== [] && $limitedRows !== []) {
            $lastRow = $limitedRows[array_key_last($limitedRows)];
            foreach ($primaryColumns as $column) {
                $nextCursor[$column] = $lastRow[$column] ?? null;
            }
        }
        return [
            'table' => $table,
            'create_sql' => (string) $createRow[1],
            'offset' => $offset,
            'next_offset' => $offset + $count,
            'next_cursor' => $nextCursor,
            'primary_columns' => $primaryColumns,
            'rows' => $limitedRows,
            'done' => $count === count($rows) && count($rows) < $limit,
        ];
    }

    /** @param array<string, mixed> $context @return array<string, mixed>|\WP_Error */
    public function exportSqlChunk(array $context): array|\WP_Error
    {
        global $wpdb;

        $operation = sanitize_text_field((string) ($context['operation'] ?? ''));
        $table = (array) ($context['table'] ?? []);
        $sourceTable = (string) ($table['name'] ?? '');
        $sourcePrefix = (string) ($context['source_prefix'] ?? $wpdb->prefix);
        $targetPrefix = (string) ($context['target_prefix'] ?? '');
        $offset = max(0, (int) ($context['offset'] ?? 0));
        if ($operation === '' || $sourceTable === '' || $targetPrefix === '') {
            return new \WP_Error('syncport_sql_context', __('The database SQL export context is incomplete.', 'syncport'));
        }

        $chunk = $this->exportChunk($sourceTable, $offset, self::CHUNK_SIZE, (array) ($context['cursor'] ?? []));
        if (is_wp_error($chunk)) {
            return $chunk;
        }

        $targetTable = $this->targetTable($sourceTable, $sourcePrefix, $targetPrefix);
        $stagingTable = $this->stagingTable($operation, $targetTable, $targetPrefix);
        $queries = [];
        if ($offset === 0) {
            $createSql = $this->createSqlForDestination(
                (string) ($chunk['create_sql'] ?? ''),
                $stagingTable,
                $operation,
                $sourcePrefix,
                $targetPrefix,
                (array) ($context['selected_tables'] ?? [])
            );
            if (is_wp_error($createSql)) {
                return $createSql;
            }
            $queries[] = 'DROP TABLE IF EXISTS `' . $this->identifier($stagingTable) . '`';
            $queries[] = $createSql;
        }

        $rows = [];
        foreach ((array) ($chunk['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $this->rewriteRow(
                $row,
                $sourceTable,
                $sourcePrefix,
                $targetPrefix,
                (array) ($context['replace'] ?? [])
            );
        }
        $availableCount = count($rows);
        $rows = $this->fitRowsToSqlChunk($stagingTable, $rows, $queries);
        $processed = count($rows);
        $nextCursor = [];
        if ($processed > 0) {
            $lastRow = $rows[array_key_last($rows)];
            foreach ((array) ($chunk['primary_columns'] ?? []) as $column) {
                $column = (string) $column;
                $nextCursor[$column] = $lastRow[$column] ?? null;
            }
        }
        $queries = array_merge($queries, $this->insertQueries($stagingTable, $rows));
        $encoded = $this->encodeQueries($queries);
        if (is_wp_error($encoded)) {
            return $encoded;
        }

        return [
            'protocol' => self::PROTOCOL,
            'operation' => $operation,
            'source_table' => $sourceTable,
            'target_table' => $targetTable,
            'staging_table' => $stagingTable,
            'offset' => $offset,
            'next_offset' => $offset + $processed,
            'next_cursor' => $nextCursor,
            'processed' => $processed,
            'done' => $processed === $availableCount && !empty($chunk['done']),
            'encoding' => $encoded['encoding'],
            'data' => $encoded['data'],
            'sha256' => $encoded['sha256'],
        ];
    }

    /** @param array<string, mixed> $chunk @return array<string, mixed>|\WP_Error */
    public function applySqlChunk(array $chunk): array|\WP_Error
    {
        global $wpdb;

        if (($chunk['protocol'] ?? '') !== self::PROTOCOL) {
            return new \WP_Error('syncport_sql_protocol', __('The database SQL chunk protocol is not supported.', 'syncport'));
        }
        $operation = sanitize_text_field((string) ($chunk['operation'] ?? ''));
        $targetTable = (string) ($chunk['target_table'] ?? '');
        $stagingTable = (string) ($chunk['staging_table'] ?? '');
        $offset = max(0, (int) ($chunk['offset'] ?? 0));
        $expectedStaging = $this->stagingTable($operation, $targetTable, (string) $wpdb->prefix);
        if ($operation === '' || $targetTable === '' || !hash_equals($expectedStaging, $stagingTable)) {
            return new \WP_Error('syncport_sql_target', __('The database SQL chunk target is invalid.', 'syncport'));
        }

        $queries = $this->decodeQueries($chunk);
        if (is_wp_error($queries)) {
            return $queries;
        }
        $chunkHash = hash('sha256', (string) wp_json_encode([
            $chunk['protocol'] ?? '',
            $operation,
            $targetTable,
            $offset,
            $chunk['sha256'] ?? '',
        ]));
        $receipt = $this->receipt($operation, $targetTable, $offset, $chunkHash);
        if (is_wp_error($receipt)) {
            return $receipt;
        }
        if (is_array($receipt)) {
            return $receipt;
        }

        $targetExisted = $offset === 0 ? $this->tableExists($targetTable) : null;

        if ($wpdb->query('SET FOREIGN_KEY_CHECKS=0') === false) {
            return new \WP_Error('syncport_sql_session', $wpdb->last_error ?: __('The database SQL session could not be prepared.', 'syncport'));
        }
        if ($wpdb->query("SET sql_mode='NO_AUTO_VALUE_ON_ZERO'") === false) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            return new \WP_Error('syncport_sql_session', $wpdb->last_error ?: __('The database SQL session could not be prepared.', 'syncport'));
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            return new \WP_Error('syncport_sql_transaction', $wpdb->last_error ?: __('The database SQL transaction could not be started.', 'syncport'));
        }
        foreach ($queries as $index => $query) {
            if (!is_string($query) || $query === '' || $wpdb->query($query) === false) {
                $wpdb->query('ROLLBACK');
                $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
                return new \WP_Error(
                    'syncport_sql_apply',
                    sprintf(__('Database SQL statement %d could not be applied: %s', 'syncport'), $index + 1, $wpdb->last_error ?: __('unknown database error', 'syncport'))
                );
            }
        }

        $result = [
            'table' => $targetTable,
            'written' => (int) ($chunk['processed'] ?? 0),
            'next_offset' => (int) ($chunk['next_offset'] ?? $offset),
            'next_cursor' => (array) ($chunk['next_cursor'] ?? []),
            'done' => !empty($chunk['done']),
            'target_existed' => $targetExisted,
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
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            return new \WP_Error('syncport_sql_receipt', $wpdb->last_error ?: __('The database SQL chunk receipt could not be stored.', 'syncport'));
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            return new \WP_Error('syncport_sql_commit', $wpdb->last_error ?: __('The database SQL chunk could not be committed.', 'syncport'));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        return $result;
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
            $preserved = $this->preserveOperationalRows($targetTable, $stagingTable);
            if (is_wp_error($preserved)) {
                return $preserved;
            }
            if ($this->tableExists($targetTable)) {
                $renames[] = '`' . $this->identifier($targetTable) . '` TO `' . $this->identifier($backupTable) . '`';
            }
            $renames[] = '`' . $this->identifier($stagingTable) . '` TO `' . $this->identifier($targetTable) . '`';
        }

        if ($renames !== [] && $wpdb->query('SET FOREIGN_KEY_CHECKS=0') === false) {
            return new \WP_Error('syncport_table_finalize', $wpdb->last_error ?: __('Foreign-key checks could not be suspended.', 'syncport'));
        }
        if ($renames !== [] && $wpdb->query('RENAME TABLE ' . implode(', ', $renames)) === false) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
            return new \WP_Error(
                'syncport_table_finalize',
                $wpdb->last_error ?: __('The staged database tables could not be activated; the live database was not changed.', 'syncport')
            );
        }
        if ($renames !== []) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        return ['tables' => count($tables)];
    }

    /** @return bool|\WP_Error */
    private function prepareTarget(
        string $targetTable,
        string $writeTable,
        string $createSql,
        string $mode,
        string $sourcePrefix,
        string $operation,
        array $selectedTables
    ): bool|\WP_Error {
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

    /** @param array<int, array<string, mixed>> $selectedTables @return string|\WP_Error */
    private function createSqlForDestination(
        string $createSql,
        string $destination,
        string $operation,
        string $sourcePrefix,
        string $targetPrefix,
        array $selectedTables
    ): string|\WP_Error {
        if ($createSql === '') {
            return new \WP_Error('syncport_sql_schema', __('The database table schema is missing.', 'syncport'));
        }

        $mapped = $sourcePrefix === ''
            ? $createSql
            : str_replace('`' . $sourcePrefix, '`' . $targetPrefix, $createSql);
        $selectedTargets = array_map(
            fn (string $table): string => $this->targetTable($table, $sourcePrefix, $targetPrefix),
            array_map('strval', array_column($selectedTables, 'name'))
        );
        $mapped = preg_replace_callback(
            '/\bREFERENCES\s+`([^`]+)`/i',
            function (array $matches) use ($operation, $selectedTargets, $sourcePrefix, $targetPrefix): string {
                $target = $this->targetTable((string) $matches[1], $sourcePrefix, $targetPrefix);
                $reference = in_array($target, $selectedTargets, true)
                    ? $this->stagingTable($operation, $target, $targetPrefix)
                    : $target;
                return 'REFERENCES `' . $this->identifier($reference) . '`';
            },
            $mapped
        );
        $constraintIndex = 0;
        $mapped = preg_replace_callback(
            '/\bCONSTRAINT\s+`[^`]+`/i',
            function () use ($operation, $destination, &$constraintIndex): string {
                $constraintIndex++;
                $name = 'syncport_' . substr(hash('sha256', $operation . '|' . $destination . '|' . $constraintIndex), 0, 32);
                return 'CONSTRAINT `' . $name . '`';
            },
            (string) $mapped
        );
        $mapped = preg_replace(
            '/^CREATE TABLE(?: IF NOT EXISTS)?\s+`(?:``|[^`])+`/i',
            'CREATE TABLE `' . $this->identifier($destination) . '`',
            (string) $mapped,
            1,
            $replacements
        );
        if ($replacements !== 1 || !is_string($mapped)) {
            return new \WP_Error('syncport_sql_schema', __('The database table schema could not be mapped.', 'syncport'));
        }
        return str_replace('TYPE=', 'ENGINE=', $mapped);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, string> */
    private function insertQueries(string $table, array $rows): array
    {
        global $wpdb;
        if ($rows === []) {
            return [];
        }

        $columns = array_keys($rows[0]);
        $prefix = 'INSERT INTO `' . $this->identifier($table) . '` (' . implode(', ', array_map(
            fn (string $column): string => '`' . $this->identifier($column) . '`',
            $columns
        )) . ') VALUES ';
        $queries = [];
        $values = [];
        $bytes = strlen($prefix);
        foreach ($rows as $row) {
            $line = '(' . implode(', ', array_map(function (string $column) use ($row, $wpdb): string {
                $value = $row[$column] ?? null;
                return $value === null ? 'NULL' : (string) $wpdb->prepare('%s', (string) $value);
            }, $columns)) . ')';
            if ($values !== [] && $bytes + strlen($line) + 2 > self::MAX_STATEMENT_BYTES) {
                $queries[] = $prefix . implode(', ', $values);
                $values = [];
                $bytes = strlen($prefix);
            }
            $values[] = $line;
            $bytes += strlen($line) + 2;
        }
        if ($values !== []) {
            $queries[] = $prefix . implode(', ', $values);
        }
        return $queries;
    }

    /** @param array<int, array<string, mixed>> $rows @param array<int, string> $baseQueries @return array<int, array<string, mixed>> */
    private function fitRowsToSqlChunk(string $table, array $rows, array $baseQueries): array
    {
        if ($rows === []) {
            return [];
        }

        $fits = function (int $count) use ($table, $rows, $baseQueries): bool {
            $queries = array_merge($baseQueries, $this->insertQueries($table, array_slice($rows, 0, $count)));
            $json = wp_json_encode($queries);
            return is_string($json) && strlen($json) <= self::MAX_CHUNK_BYTES;
        };
        if ($fits(count($rows))) {
            return $rows;
        }

        $minimum = 1;
        $maximum = count($rows);
        while ($minimum < $maximum) {
            $middle = (int) ceil(($minimum + $maximum) / 2);
            if ($fits($middle)) {
                $minimum = $middle;
            } else {
                $maximum = $middle - 1;
            }
        }
        return array_slice($rows, 0, max(1, $minimum));
    }

    /** @param array<int, string> $queries @return array{encoding: string, data: string, sha256: string}|\WP_Error */
    private function encodeQueries(array $queries): array|\WP_Error
    {
        $json = wp_json_encode($queries);
        if (!is_string($json)) {
            return new \WP_Error('syncport_sql_encode', __('The database SQL chunk could not be encoded.', 'syncport'));
        }
        $compressed = function_exists('gzencode') ? gzencode($json, 6) : false;
        return [
            'encoding' => is_string($compressed) ? 'gzip-base64' : 'base64',
            'data' => base64_encode(is_string($compressed) ? $compressed : $json),
            'sha256' => hash('sha256', $json),
        ];
    }

    /** @param array<string, mixed> $chunk @return array<int, string>|\WP_Error */
    private function decodeQueries(array $chunk): array|\WP_Error
    {
        $binary = base64_decode((string) ($chunk['data'] ?? ''), true);
        if (!is_string($binary)) {
            return new \WP_Error('syncport_sql_decode', __('The database SQL chunk encoding is invalid.', 'syncport'));
        }
        $encoding = (string) ($chunk['encoding'] ?? '');
        if ($encoding === 'gzip-base64') {
            $json = function_exists('gzdecode') ? gzdecode($binary, self::MAX_DECODE_BYTES) : false;
        } elseif ($encoding === 'base64') {
            $json = $binary;
        } else {
            $json = false;
        }
        if (!is_string($json) || strlen($json) > self::MAX_DECODE_BYTES
            || !hash_equals((string) ($chunk['sha256'] ?? ''), hash('sha256', $json))) {
            return new \WP_Error('syncport_sql_checksum', __('The database SQL chunk checksum is invalid.', 'syncport'));
        }
        $queries = json_decode($json, true);
        if (!is_array($queries) || array_filter($queries, 'is_string') !== $queries) {
            return new \WP_Error('syncport_sql_payload', __('The database SQL chunk payload is invalid.', 'syncport'));
        }
        return array_values($queries);
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** @param array<int, string> $columns @param array<string, mixed> $cursor */
    private function cursorWhere(array $columns, array $cursor): string
    {
        global $wpdb;
        if ($columns === [] || array_diff($columns, array_keys($cursor)) !== []) {
            return '';
        }

        $clauses = [];
        foreach ($columns as $index => $column) {
            $parts = [];
            for ($equal = 0; $equal < $index; $equal++) {
                $equalColumn = $columns[$equal];
                $parts[] = '`' . $this->identifier($equalColumn) . '` = ' . $wpdb->prepare('%s', (string) $cursor[$equalColumn]);
            }
            $parts[] = '`' . $this->identifier($column) . '` > ' . $wpdb->prepare('%s', (string) $cursor[$column]);
            $clauses[] = '(' . implode(' AND ', $parts) . ')';
        }
        return '(' . implode(' OR ', $clauses) . ')';
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

    /** @return bool|\WP_Error */
    private function preserveOperationalRows(string $targetTable, string $stagingTable): bool|\WP_Error
    {
        global $wpdb;
        if (!$this->tableExists($targetTable)) {
            return true;
        }

        $staging = $this->identifier($stagingTable);
        $userId = get_current_user_id();
        if ($targetTable === $wpdb->prefix . 'options') {
            $deleted = $wpdb->query(
                "DELETE FROM `{$staging}` WHERE option_name = 'active_plugins' OR option_name LIKE 'syncport\\_%'"
            );
        } elseif ($userId > 0 && $targetTable === $wpdb->prefix . 'users') {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM `{$staging}` WHERE ID = %d", $userId));
        } elseif ($userId > 0 && $targetTable === $wpdb->prefix . 'usermeta') {
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM `{$staging}` WHERE user_id = %d", $userId));
        } else {
            return true;
        }
        if ($deleted === false) {
            return new \WP_Error('syncport_table_preserve', $wpdb->last_error ?: __('Operational rows could not be prepared in the staging table.', 'syncport'));
        }
        foreach ($this->preservedRows($targetTable) as $row) {
            if ($wpdb->replace($stagingTable, $row) === false) {
                return new \WP_Error('syncport_table_preserve', $wpdb->last_error ?: __('Operational rows could not be copied to the staging table.', 'syncport'));
            }
        }
        return true;
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
