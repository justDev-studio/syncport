<?php

declare(strict_types=1);

function get_user_by(string $field, string $value): object|false
{
    if ($field === 'email' && $value === 'existing@example.test') {
        return (object) ['ID' => 7];
    }
    return false;
}

function get_users(array $arguments): array
{
    if (($arguments['role'] ?? '') !== 'administrator'
        || ($arguments['orderby'] ?? '') !== 'ID'
        || ($arguments['order'] ?? '') !== 'ASC'
        || ($arguments['number'] ?? 0) !== 1
        || ($arguments['fields'] ?? '') !== 'ids') {
        return [];
    }
    return [1];
}

require dirname(__DIR__) . '/src/Migration/PostImporter.php';

$reflection = new ReflectionClass(JustDev\SyncPort\Migration\PostImporter::class);
$importer = $reflection->newInstanceWithoutConstructor();
$authorMethod = $reflection->getMethod('authorId');
$matchedAuthorId = $authorMethod->invoke($importer, [
    'email' => 'existing@example.test',
    'login' => 'existing-author',
]);
$fallbackAuthorId = $authorMethod->invoke($importer, [
    'email' => 'missing@example.test',
    'login' => 'missing-author',
]);

if ($matchedAuthorId !== 7) {
    fwrite(STDERR, "An existing target author was not matched before using the fallback.\n");
    exit(1);
}
if ($fallbackAuthorId !== 1) {
    fwrite(STDERR, "A missing source author was not assigned to the target site's primary administrator.\n");
    exit(1);
}

echo "Missing source authors use the target site's primary administrator.\n";
