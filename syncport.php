<?php
/**
 * Plugin Name:       SyncPort — Migration & Sync
 * Plugin URI:        https://justdev.org
 * Description:       Selectively migrate WordPress content, settings, media, and database tables between connected sites.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Author:            justDev
 * Author URI:        https://justdev.org
 * Text Domain:       syncport
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SYNCPORT_VERSION', '0.1.0');
define('SYNCPORT_FILE', __FILE__);
define('SYNCPORT_PATH', plugin_dir_path(__FILE__));
define('SYNCPORT_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'JustDev\\SyncPort\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = SYNCPORT_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
);

register_activation_hook(SYNCPORT_FILE, [JustDev\SyncPort\Infrastructure\Installer::class, 'activate']);

add_action(
    'plugins_loaded',
    static function (): void {
        JustDev\SyncPort\Plugin::instance()->boot();
    }
);
