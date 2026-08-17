<?php

declare(strict_types=1);

namespace JustDev\SyncPort\Infrastructure;

final class Installer
{
    public static function activate(): void
    {
        global $wpdb;

        if (!get_option('syncport_api_key')) {
            add_option('syncport_api_key', wp_generate_password(64, false, false), '', false);
        }

        $table = $wpdb->prefix . 'syncport_operations';
        $chunksTable = $wpdb->prefix . 'syncport_chunks';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_uuid char(36) NOT NULL,
            direction varchar(8) NOT NULL,
            scope varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            connection_id varchar(36) NOT NULL DEFAULT '',
            request longtext NOT NULL,
            manifest longtext NULL,
            result longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY operation_uuid (operation_uuid),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";
        $chunksSql = "CREATE TABLE {$chunksTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation_uuid char(36) NOT NULL,
            table_name varchar(64) NOT NULL,
            chunk_offset bigint(20) unsigned NOT NULL,
            chunk_hash char(64) NOT NULL,
            result longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY operation_chunk (operation_uuid, table_name, chunk_offset),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($chunksSql);

        self::addCapabilities();
        update_option('syncport_db_version', '2', false);
    }

    private static function addCapabilities(): void
    {
        $role = get_role('administrator');
        if (!$role) {
            return;
        }

        $role->add_cap('manage_syncport');
        $role->add_cap('manage_syncport_tables');
    }
}
