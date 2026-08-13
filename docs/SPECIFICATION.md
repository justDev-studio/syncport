# SyncPort specification

## Supported scopes

### Content entities

- Pages, Posts, and ordinary custom post types
- One entity, selected entities, or all entities of selected post types
- Exact source status and dates
- Direct post meta and ACF values
- Assigned taxonomy terms
- Directly used attachments
- Optional Post comments in a later minor release
- No revisions, autosaves, trash, users, menus, WooCommerce orders, or recursive relationship migration

### ACF Options

- Values only
- Selected option names
- Target ACF field structure must exist and pass preflight

### Database

- All prefixed tables or selected tables
- Replace and Merge modes
- SQL export
- Critical-table confirmation
- Backup before apply

## Entity identity

WordPress IDs are local identifiers and are never used as cross-site identity. SyncPort assigns `_syncport_uuid`. On the first migration, `post_type + post_name` is offered as a candidate match and never silently linked when content differs.

## Conflicts

A blocking conflict must be resolved per target. Entity actions are Replace, Skip, and Create duplicate. Missing authors additionally require mapping to an existing target user. Missing non-recursive post relationships require mapping, selecting the related entity for migration, clearing the field, or cancelling the selected entity.

## Media

- Local → Local, Local → object storage, object storage → Local, and object storage → object storage use one storage interface.
- UUID is the primary identity and SHA-256 is the fallback identity.
- Matching names or URLs are not evidence of identity.
- Shared buckets may reuse an object only through a compatible storage adapter.
- Unknown integrations fall back to downloading the source object and importing it through WordPress.
- Mirror mode is opt-in. Local deletions are quarantined for 7 days. S3 objects are never automatically deleted.

## WPML

The user may select one translation or the entire translation group. A single translation is attached to an existing target group or creates a new group after conflict resolution. Polylang and Multisite are outside the first version.

## Operations

1. Discover compatibility.
2. Build an immutable source manifest.
3. Run target-specific preflight.
4. Resolve every blocking conflict.
5. Create a target backup and restore journal.
6. Apply idempotent chunks.
7. Finalize references and WPML groups.
8. Verify checksums.
9. Commit or compensate from the restore journal.

Failure rolls back only the affected target. Other targets continue independently. Closing the browser must not stop a running server-side operation.

## Retention

- Backups: 7 days
- Operation logs: 30 days
- Manual download and deletion
- WP-Cron cleanup

## Deferred ideas

- WP-CLI commands for profiles, status, and rollback
- Polylang adapter
- WooCommerce-specific modules
- WordPress Multisite
- scheduled migrations
