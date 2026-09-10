<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Migration;

final class DatabaseMigrator
{
    public const PROTOCOL = 'wpsdb-sql-v1';
    public const CHUNK_SIZE = 100;

    private const MAX_CHUNK_BYTES = 1048576;
    private const MAX_DECODE_BYTES = 26214400;
    private const MAX_STATEMENT_BYTES = 50000;
    private const TEMP_PREFIX = '_mig_';

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
        if (!$this->sourceTableExists($sourceTable)) {
            return new \WP_Error('syncport_invalid_table', __('The selected source table does not exist.', 'syncport'));
        }

        $description = $wpdb->get_results('DESCRIBE `' . $this->identifier($sourceTable) . '`', ARRAY_A);
        if (!is_array($description) || $description === []) {
            return new \WP_Error('syncport_table_schema', __('The source table structure could not be read.', 'syncport'));
        }

        $cursor = (array) ($context['cursor'] ?? []);
        $primaryColumns = $this->integerPrimaryColumns($description);
        $targetTable = $this->targetTable($sourceTable, $sourcePrefix, $targetPrefix);
        $stagingTable = $this->originalStagingTable($targetTable);
        $firstChunk = $offset === 0 && $cursor === [];
        $sql = '';
        $deferredAlter = '';
        if ($firstChunk) {
            $createRow = $wpdb->get_row('SHOW CREATE TABLE `' . $this->identifier($sourceTable) . '`', ARRAY_N);
            if (!is_array($createRow) || empty($createRow[1])) {
                return new \WP_Error('syncport_table_schema', __('The source table schema could not be read.', 'syncport'));
            }
            $mappedSchema = $this->originalSchema(
                (string) $createRow[1],
                $sourcePrefix,
                $targetPrefix,
                $targetTable,
                $stagingTable
            );
            if (is_wp_error($mappedSchema)) {
                return $mappedSchema;
            }
            $sql = 'DROP TABLE IF EXISTS `' . $this->identifier($stagingTable) . "`;\n"
                . rtrim($mappedSchema['create'], ";\n") . ";\n";
            $deferredAlter = $mappedSchema['alter'];
        }

        $processed = 0;
        $nextOffset = $offset;
        $nextCursor = $cursor;
        $done = false;
        do {
            $rows = $this->sourceRows($sourceTable, $sourcePrefix, $nextOffset, $nextCursor, $primaryColumns);
            if (is_wp_error($rows)) {
                return $rows;
            }
            if ($rows === []) {
                $done = true;
                break;
            }

            $rewrittenRows = [];
            foreach ($rows as $row) {
                $rewrittenRows[] = $this->rewriteRowOriginal(
                    $row,
                    $sourceTable,
                    $sourcePrefix,
                    $targetPrefix,
                    (array) ($context['replace'] ?? [])
                );
            }
            $statements = $this->originalInsertStatements($stagingTable, $rewrittenRows, $description, strlen($sql));
            if (is_wp_error($statements)) {
                return $statements;
            }
            $batchProcessed = $statements['processed'];
            $sql .= $statements['sql'];
            $processed += $batchProcessed;
            $nextOffset += $batchProcessed;

            $lastRow = $rows[$batchProcessed - 1] ?? null;
            if (is_array($lastRow)) {
                foreach ($primaryColumns as $column) {
                    $nextCursor[$column] = $lastRow[$column] ?? null;
                }
            }
            if ($batchProcessed < count($rows)) {
                break;
            }
            if (count($rows) < self::CHUNK_SIZE) {
                $done = true;
                break;
            }
        } while (strlen($sql) < self::MAX_CHUNK_BYTES);

        if ($primaryColumns === []) {
            $nextCursor = [];
        }
        $encoded = $this->encodeSql($sql);
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
            'next_offset' => $nextOffset,
            'next_cursor' => $nextCursor,
            'processed' => $processed,
            'done' => $done,
            'deferred_alter' => $deferredAlter,
            'alter_sha256' => hash('sha256', $deferredAlter),
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
        $expectedStaging = $this->originalStagingTable($targetTable);
        if ($operation === '' || $targetTable === '' || !hash_equals($expectedStaging, $stagingTable)) {
            return new \WP_Error('syncport_sql_target', __('The database SQL chunk target is invalid.', 'syncport'));
        }
        $deferredAlter = (string) ($chunk['deferred_alter'] ?? '');
        if (!hash_equals((string) ($chunk['alter_sha256'] ?? ''), hash('sha256', $deferredAlter))) {
            return new \WP_Error('syncport_sql_checksum', __('The database SQL chunk checksum is invalid.', 'syncport'));
        }

        $sql = $this->decodeSql($chunk);
        if (is_wp_error($sql)) {
            return $sql;
        }
        $chunkHash = hash('sha256', (string) wp_json_encode([
            $chunk['protocol'] ?? '',
            $operation,
            $targetTable,
            $offset,
            $chunk['sha256'] ?? '',
            $chunk['alter_sha256'] ?? '',
        ]));
        $receipt = $this->receipt($operation, $targetTable, $offset, $chunkHash);
        if (is_wp_error($receipt)) {
            return $receipt;
        }
        if (is_array($receipt)) {
            return $receipt;
        }

        $targetExisted = $offset === 0 ? $this->tableExists($targetTable) : null;

        if ($wpdb->query("SET sql_mode='NO_AUTO_VALUE_ON_ZERO'") === false) {
            return new \WP_Error('syncport_sql_session', $wpdb->last_error ?: __('The database SQL session could not be prepared.', 'syncport'));
        }
        if ($wpdb->query('START TRANSACTION') === false) {
            return new \WP_Error('syncport_sql_transaction', $wpdb->last_error ?: __('The database SQL transaction could not be started.', 'syncport'));
        }
        $queries = array_values(array_filter(array_map('trim', explode(";\n", $sql)), static fn (string $query): bool => $query !== ''));
        foreach ($queries as $index => $query) {
            if ($wpdb->query($query) === false) {
                $wpdb->query('ROLLBACK');
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
            'deferred_alter' => $deferredAlter,
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
            return new \WP_Error('syncport_sql_receipt', $wpdb->last_error ?: __('The database SQL chunk receipt could not be stored.', 'syncport'));
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('syncport_sql_commit', $wpdb->last_error ?: __('The database SQL chunk could not be committed.', 'syncport'));
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $tables @return array<string, int>|\WP_Error */
    public function finalizeReplace(string $operation, array $tables, string $sourcePrefix): array|\WP_Error
    {
        global $wpdb;

        $activations = [];
        foreach ($tables as $table) {
            $sourceTable = (string) ($table['name'] ?? '');
            if ($sourceTable === '') {
                continue;
            }
            $targetPrefix = (string) $wpdb->prefix;
            $targetTable = $this->targetTable($sourceTable, $sourcePrefix, $targetPrefix);
            $stagingTable = $this->originalStagingTable($targetTable);
            if (!$this->tableExists($stagingTable)) {
                if ($this->tableExists($targetTable)) {
                    continue;
                }
                return new \WP_Error('syncport_staging_table_missing', __('A staged database table is missing; the live database was not changed.', 'syncport'));
            }
            $preserved = $this->preserveOperationalRows($targetTable, $stagingTable);
            if (is_wp_error($preserved)) {
                return $preserved;
            }
            $firstChunk = $this->firstChunkResult($operation, $targetTable);
            $activations[] = [
                'target' => $targetTable,
                'staging' => $stagingTable,
                'alter' => (string) ($firstChunk['deferred_alter'] ?? ''),
            ];
        }

        if ($activations !== [] && $wpdb->query('SET FOREIGN_KEY_CHECKS=0') === false) {
            return new \WP_Error('syncport_table_finalize', $wpdb->last_error ?: __('Foreign-key checks could not be suspended.', 'syncport'));
        }
        foreach ($activations as $activation) {
            $target = $this->identifier((string) $activation['target']);
            $staging = $this->identifier((string) $activation['staging']);
            if ($wpdb->query("DROP TABLE IF EXISTS `{$target}`") === false
                || $wpdb->query("RENAME TABLE `{$staging}` TO `{$target}`") === false) {
                $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
                return new \WP_Error(
                    'syncport_table_finalize',
                    $wpdb->last_error ?: __('The staged database tables could not be activated.', 'syncport')
                );
            }
        }
        foreach ($activations as $activation) {
            $alterQueries = array_values(array_filter(
                array_map('trim', explode(";\n", (string) $activation['alter'])),
                static fn (string $query): bool => $query !== ''
            ));
            foreach ($alterQueries as $alterQuery) {
                if ($wpdb->query($alterQuery) === false) {
                    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
                    return new \WP_Error(
                        'syncport_table_constraints',
                        $wpdb->last_error ?: __('Database constraints could not be restored.', 'syncport')
                    );
                }
            }
        }
        if ($activations !== []) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(true);
        }
        return ['tables' => count($tables)];
    }

    private function sourceTableExists(string $table): bool
    {
        global $wpdb;
        $tables = array_map(
            static fn (array $row): string => (string) ($row[0] ?? ''),
            array_filter(
                (array) $wpdb->get_results('SHOW FULL TABLES', ARRAY_N),
                static fn (array $row): bool => !isset($row[1]) || strtoupper((string) $row[1]) === 'BASE TABLE'
            )
        );
        return in_array($table, $tables, true) && !$this->isProtectedTable($table, (string) $wpdb->prefix);
    }

    /** @param array<int, array<string, mixed>> $description @return array<int, string> */
    private function integerPrimaryColumns(array $description): array
    {
        $primary = [];
        foreach ($description as $column) {
            if (($column['Key'] ?? '') !== 'PRI') {
                continue;
            }
            if (!preg_match('/^(?:tiny|small|medium|big)?int/i', (string) ($column['Type'] ?? ''))) {
                return [];
            }
            $primary[] = (string) ($column['Field'] ?? '');
        }
        return array_values(array_filter($primary));
    }

    /** @param array<string, mixed> $cursor @param array<int, string> $primaryColumns @return array<int, array<string, mixed>>|\WP_Error */
    private function sourceRows(
        string $table,
        string $sourcePrefix,
        int $offset,
        array $cursor,
        array $primaryColumns
    ): array|\WP_Error {
        global $wpdb;

        $where = ['1=1'];
        $suffix = str_starts_with($table, $sourcePrefix) ? substr($table, strlen($sourcePrefix)) : '';
        if ($suffix === 'options') {
            $where[] = "option_name NOT LIKE '\\_transient\\_%'";
            $where[] = "option_name NOT LIKE '\\_site\\_transient\\_%'";
        } elseif ($suffix === 'sitemeta') {
            $where[] = "meta_key NOT LIKE '\\_transient\\_%'";
            $where[] = "meta_key NOT LIKE '\\_site\\_transient\\_%'";
        }

        $cursorWhere = $this->cursorWhere($primaryColumns, $cursor);
        if ($cursorWhere !== '') {
            $where[] = $cursorWhere;
        }
        $order = $primaryColumns === [] ? '' : ' ORDER BY ' . implode(', ', array_map(
            fn (string $column): string => '`' . $this->identifier($column) . '`',
            $primaryColumns
        ));
        $limit = $primaryColumns === []
            ? $wpdb->prepare(' LIMIT %d OFFSET %d', self::CHUNK_SIZE, $offset)
            : $wpdb->prepare(' LIMIT %d', self::CHUNK_SIZE);
        $query = 'SELECT * FROM `' . $this->identifier($table) . '` WHERE ' . implode(' AND ', $where) . $order . $limit;
        $rows = $wpdb->get_results($query, ARRAY_A);
        return is_array($rows)
            ? $rows
            : new \WP_Error('syncport_table_export', __('The source table rows could not be read.', 'syncport'));
    }

    /** @return array{create: string, alter: string}|\WP_Error */
    private function originalSchema(
        string $createSql,
        string $sourcePrefix,
        string $targetPrefix,
        string $targetTable,
        string $stagingTable
    ): array|\WP_Error {
        $mapped = $sourcePrefix === ''
            ? $createSql
            : str_replace('`' . $sourcePrefix, '`' . $targetPrefix, $createSql);
        $mapped = str_replace('TYPE=', 'ENGINE=', $mapped);
        $mapped = preg_replace(
            '/^CREATE TABLE(?: IF NOT EXISTS)?\s+`(?:``|[^`])+`/i',
            'CREATE TABLE `' . $this->identifier($stagingTable) . '`',
            $mapped,
            1,
            $replacements
        );
        if ($replacements !== 1 || !is_string($mapped)) {
            return new \WP_Error('syncport_sql_schema', __('The database table schema could not be mapped.', 'syncport'));
        }

        $lines = preg_split('/\r\n|\r|\n/', $mapped) ?: [];
        $kept = [];
        $constraints = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:CONSTRAINT|FOREIGN\s+KEY)\b/i', $line)) {
                $constraint = rtrim(trim($line), ',');
                $constraints[] = preg_replace('/^(CONSTRAINT|FOREIGN\s+KEY)\b/i', 'ADD $1', $constraint) ?: $constraint;
                continue;
            }
            $kept[] = $line;
        }
        if ($constraints !== [] && count($kept) > 1) {
            $previous = count($kept) - 2;
            $kept[$previous] = rtrim($kept[$previous], ',');
        }
        $alter = $constraints === []
            ? ''
            : 'ALTER TABLE `' . $this->identifier($targetTable) . "`\n  " . implode(",\n  ", $constraints) . ";\n";
        return ['create' => implode("\n", $kept), 'alter' => $alter];
    }

    /** @param array<string, string> $replace @return array<string, mixed> */
    private function rewriteRowOriginal(
        array $row,
        string $sourceTable,
        string $sourcePrefix,
        string $targetPrefix,
        array $replace
    ): array {
        foreach ($row as $column => $value) {
            $row[$column] = $this->originalReplaceValue($value, $replace);
        }

        $suffix = str_starts_with($sourceTable, $sourcePrefix) ? substr($sourceTable, strlen($sourcePrefix)) : '';
        $prefixColumn = $suffix === 'options' ? 'option_name' : ($suffix === 'usermeta' ? 'meta_key' : '');
        if ($prefixColumn !== '' && isset($row[$prefixColumn]) && is_string($row[$prefixColumn])
            && str_starts_with($row[$prefixColumn], $sourcePrefix)) {
            $row[$prefixColumn] = $targetPrefix . substr($row[$prefixColumn], strlen($sourcePrefix));
        }
        return $row;
    }

    /** @param array<string, string> $replace */
    private function originalReplaceValue(mixed $data, array $replace, bool $serialized = false): mixed
    {
        try {
            if (is_string($data) && is_serialized($data)) {
                $unserialized = maybe_unserialize($data);
                if ($unserialized instanceof \DateInterval || $unserialized instanceof \DatePeriod) {
                    return $data;
                }
                return serialize($this->originalReplaceValue($unserialized, $replace, true));
            }
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $data[$key] = $this->originalReplaceValue($value, $replace, $serialized);
                }
                return $data;
            }
            if (is_object($data)) {
                $copy = clone $data;
                foreach ($copy as $key => $value) {
                    $copy->{$key} = $this->originalReplaceValue($value, $replace, $serialized);
                }
                return $copy;
            }
            if (!is_string($data)) {
                return $data;
            }

            $json = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                $replaced = $this->originalReplaceValue($json, $replace, $serialized);
                $encoded = wp_json_encode($replaced);
                return is_string($encoded) ? $encoded : $data;
            }
            return str_ireplace(array_keys($replace), array_values($replace), $data);
        } catch (\Throwable) {
            return $data;
        }
    }

    /** @param array<int, array<string, mixed>> $rows @param array<int, array<string, mixed>> $description @return array{sql: string, processed: int}|\WP_Error */
    private function originalInsertStatements(string $table, array $rows, array $description, int $baseBytes): array|\WP_Error
    {
        if ($rows === []) {
            return ['sql' => '', 'processed' => 0];
        }

        $fields = [];
        $integers = [];
        $defaults = [];
        foreach ($description as $column) {
            $field = (string) ($column['Field'] ?? '');
            if ($field === '') {
                continue;
            }
            $fields[] = $field;
            if (preg_match('/^(?:tiny|small|medium|big)?int/i', (string) ($column['Type'] ?? ''))) {
                $integers[$field] = true;
                $defaults[$field] = $column['Default'] ?? null;
            }
        }
        if ($fields === []) {
            return new \WP_Error('syncport_table_schema', __('The source table columns could not be read.', 'syncport'));
        }

        $template = 'INSERT INTO `' . $this->identifier($table) . '` ( '
            . implode(', ', array_map(fn (string $field): string => '`' . $this->identifier($field) . '`', $fields))
            . " ) VALUES\n";
        $sql = '';
        $lines = [];
        $processed = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? null;
                if (isset($integers[$field])) {
                    if ($value === null || $value === '') {
                        $value = $defaults[$field];
                    }
                    $values[] = $value === null ? 'NULL' : ($value === '' ? "''" : (string) $value);
                    continue;
                }
                $values[] = $value === null ? 'NULL' : "'" . $this->originalSqlEscape((string) $value) . "'";
            }
            $line = '(' . implode(', ', $values) . ')';
            $candidateLines = array_merge($lines, [$line]);
            $candidate = $template . implode(",\n", $candidateLines) . ";\n";
            if ($lines !== [] && strlen($candidate) > self::MAX_STATEMENT_BYTES) {
                $sql .= $template . implode(",\n", $lines) . ";\n";
                $lines = [];
                $candidateLines = [$line];
                $candidate = $template . $line . ";\n";
            }
            if ($processed > 0 && $baseBytes + strlen($sql) + strlen($candidate) > self::MAX_CHUNK_BYTES) {
                break;
            }
            $lines = $candidateLines;
            $processed++;
        }
        if ($lines !== []) {
            $sql .= $template . implode(",\n", $lines) . ";\n";
        }
        return ['sql' => $sql, 'processed' => $processed];
    }

    private function originalSqlEscape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("'", "\\'", $value);
        return str_replace(["\0", "\n", "\r", "\x1a"], ['\\0', '\\n', '\\r', '\\Z'], $value);
    }

    /** @return array{encoding: string, data: string, sha256: string}|\WP_Error */
    private function encodeSql(string $sql): array|\WP_Error
    {
        $compressed = function_exists('gzcompress') ? gzcompress($sql, 6) : false;
        return [
            'encoding' => is_string($compressed) ? 'deflate-base64' : 'base64',
            'data' => base64_encode(is_string($compressed) ? $compressed : $sql),
            'sha256' => hash('sha256', $sql),
        ];
    }

    /** @param array<string, mixed> $chunk @return string|\WP_Error */
    private function decodeSql(array $chunk): string|\WP_Error
    {
        $binary = base64_decode((string) ($chunk['data'] ?? ''), true);
        if (!is_string($binary)) {
            return new \WP_Error('syncport_sql_decode', __('The database SQL chunk encoding is invalid.', 'syncport'));
        }
        $encoding = (string) ($chunk['encoding'] ?? '');
        if ($encoding === 'deflate-base64') {
            $sql = function_exists('gzuncompress') ? gzuncompress($binary, self::MAX_DECODE_BYTES) : false;
        } elseif ($encoding === 'base64') {
            $sql = $binary;
        } else {
            $sql = false;
        }
        if (!is_string($sql) || strlen($sql) > self::MAX_DECODE_BYTES
            || !hash_equals((string) ($chunk['sha256'] ?? ''), hash('sha256', $sql))) {
            return new \WP_Error('syncport_sql_checksum', __('The database SQL chunk checksum is invalid.', 'syncport'));
        }
        return $sql;
    }

    private function originalStagingTable(string $targetTable): string
    {
        return self::TEMP_PREFIX . $targetTable;
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
            || str_starts_with($table, self::TEMP_PREFIX)
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

        $userId = $this->protectedUserId();
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
        $userId = $this->protectedUserId();
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

    private function protectedUserId(): int
    {
        $userId = get_current_user_id();
        if ($userId > 0 || !function_exists('get_users')) {
            return $userId;
        }

        $administrators = get_users([
            'role' => 'administrator',
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
            'fields' => 'ID',
        ]);
        return isset($administrators[0]) ? (int) $administrators[0] : 0;
    }

    private function targetTable(string $sourceTable, string $sourcePrefix, string $targetPrefix): string
    {
        if ($sourcePrefix !== '' && str_starts_with($sourceTable, $sourcePrefix)) {
            return $targetPrefix . substr($sourceTable, strlen($sourcePrefix));
        }
        return $sourceTable;
    }

    private function identifier(string $identifier): string
    {
        return str_replace('`', '``', substr($identifier, 0, 64));
    }
}
