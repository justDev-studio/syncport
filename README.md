# SyncPort — Migration & Sync

SyncPort migrates selected WordPress data between separately installed WordPress sites. It is a modern, modular successor to the bundled `jd-wp-sync-db-master` and `jd-wp-sync-db-media-files-master` references.

## Requirements

- WordPress 6.4 through 7.x
- PHP 8.1 through 8.4
- MySQL 5.7+ or MariaDB 10.4+
- HTTPS for production connections
- The same major SyncPort protocol version on connected sites

There is no frontend build step.

## Composer installation

The package uses Composer's `wordpress-plugin` installer type and is installed into the WordPress plugins directory through `composer/installers`:

```bash
composer require justdev/syncport
```

For a private VCS package, add its repository to the root project before running the command. The installer directory name is fixed to `syncport`.

## Product rules

- Every migration has one source and one or more independently analyzed targets.
- Push may target multiple sites. Pull has one source per operation.
- A preflight is required before writes.
- Existing entities are matched by SyncPort UUID, then offered as a possible match by post type and slug.
- A changed match is a conflict. The user must replace, skip, or create a duplicate.
- A selected entity includes its post fields, post meta, ACF values, terms, WPML language, and directly used media.
- Relationship and Post Object fields are not recursively migrated.
- Media is matched by UUID and then SHA-256. Unknown object-storage integrations use download-and-import fallback.
- ACF Options migration transfers values only. Field registration must already exist on the target.
- Full database migration supports replace or merge semantics and optional media mirroring.
- Destructive work requires a backup. Entity backups are retained for 7 days and logs for 30 days.
- The plugin never automatically deletes S3 objects.

## Current implementation status

Version `0.3.0` includes the migration foundation and staged database transfer:

- Tools → SyncPort administration screen
- stored connections and per-connection push/pull policy
- HMAC-SHA256 signed REST transport
- timestamp and one-time nonce replay protection
- compatibility handshake
- content, ACF Option, and table-summary manifests
- UUID assignment and slug fallback matching
- preflight conflict analysis
- entity import with exact meta and taxonomy replacement
- author matching by email and login
- WPML language assignment
- direct media discovery, transfer, UUID/SHA-256 deduplication, and ID remapping
- URL replacement inside nested and serialized values
- chunked, size-limited database table transfer in Replace and Merge modes
- staging tables, atomic Replace activation, prefix mapping, durable chunk receipts, and determinate progress
- operation history
- separate `manage_syncport` and `manage_syncport_tables` capabilities

The following modules are deliberately blocked rather than pretending to be safe before their transaction model is complete:

- multi-target batch coordinator
- durable background continuation and cancellation
- downloadable backups and rollback UI
- media-library mirroring/deletion quarantine
- WPML translation-group reconstruction across multiple selected languages
- interactive mapping for missing authors and non-recursive post relationships
- private S3 provider adapters

Database migrations export up to 500 rows and 1 MB per request, then apply rows in multi-row SQL statements. An empty table selection migrates every table with the current WordPress prefix; selecting tables limits the migration to those tables. Replace mode builds operation-specific staging tables and activates all of them with one atomic `RENAME TABLE` only after every chunk succeeds. Existing live tables become operation-specific backups during that final rename. Merge mode preserves the target table and upserts source rows directly. SyncPort connection/authentication options, the active plugin list, and the current local administrator are copied into staging so the chunk runner cannot lock itself out during a migration.

## Structure

```text
syncport.php
src/
  Admin/             WordPress Admin interface and orchestration
  Http/              signed client and remote REST controller
  Infrastructure/    installation and persistence
  Migration/         manifests, conflicts, entities, and media
  Security/          request signing and authentication
templates/           PHP admin templates
assets/               build-free JavaScript and CSS
```

## Connection setup

1. Install and activate the same major SyncPort version on both sites.
2. On the receiving site, open Tools → SyncPort → Settings.
3. Enable the required incoming direction and copy the complete Connection info value.
4. On the initiating site, paste that value under Connections.
5. Test the connection before running a preflight.

Regenerating the API key immediately revokes connections that use the previous key.

## Security notes

Remote routes intentionally use a public WordPress REST permission callback because connected sites do not share WordPress users. Every route authenticates the raw request independently using HMAC, a five-minute timestamp window, and a single-use nonce. Apply repeats compatibility and conflict validation on the receiving site.
