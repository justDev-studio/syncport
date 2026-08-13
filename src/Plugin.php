<?php

declare(strict_types=1);

namespace JustDev\SyncPort;

use JustDev\SyncPort\Admin\AdminPage;
use JustDev\SyncPort\Http\RemoteController;
use JustDev\SyncPort\Infrastructure\ConnectionRepository;
use JustDev\SyncPort\Infrastructure\Installer;
use JustDev\SyncPort\Infrastructure\OperationRepository;
use JustDev\SyncPort\Migration\ConflictAnalyzer;
use JustDev\SyncPort\Migration\ManifestBuilder;
use JustDev\SyncPort\Migration\PostImporter;
use JustDev\SyncPort\Security\RequestAuthenticator;
use JustDev\SyncPort\Security\RequestSigner;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        if (get_option('syncport_db_version') !== '1') {
            Installer::activate();
        }
        load_plugin_textdomain('syncport', false, dirname(plugin_basename(SYNCPORT_FILE)) . '/languages');

        $connections = new ConnectionRepository();
        $operations = new OperationRepository();
        $builder = new ManifestBuilder();
        $analyzer = new ConflictAnalyzer();
        $importer = new PostImporter();
        $signer = new RequestSigner();
        $authenticator = new RequestAuthenticator();

        (new RemoteController($authenticator, $builder, $analyzer, $importer))->register();

        if (is_admin()) {
            (new AdminPage($connections, $operations, $builder, $analyzer, $importer, $signer))->register();
        }
    }

    private function __construct()
    {
    }
}
