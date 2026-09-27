<?php

/**
 * Generates the "Appendix C — Complete API reference" section of
 * docs/FRONTEND_IMPLEMENTATION_PLAN.md from the Postman collection, so the
 * frontend plan is self-contained. Re-run after changing the collection:
 *
 *   php scripts/postman-api-reference.php
 *
 * The generated markdown replaces the block between
 * <!-- API-REFERENCE:START --> and <!-- API-REFERENCE:END -->.
 */

$root = dirname(__DIR__);
$collectionPath = $root.'/postman/GCMC-API.postman_collection.json';
$docPath = $root.'/docs/FRONTEND_IMPLEMENTATION_PLAN.md';

$collection = json_decode(file_get_contents($collectionPath), true, 512, JSON_THROW_ON_ERROR);
$doc = file_get_contents($docPath);

/** Shrink arrays to their first item so examples stay compact. */
function shrink(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value) && count($value) > 1) {
        return [shrink($value[0]), '… ('.(count($value) - 1).' more)'];
    }

    return array_map('shrink', $value);
}

function exampleBody(array $item, int $maxLines = 55): ?string
{
    foreach ($item['response'] ?? [] as $example) {
        $code = (int) ($example['code'] ?? 0);
        $body = (string) ($example['body'] ?? '');
        if ($code < 200 || $code >= 300 || $body === '') {
            continue;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $pretty = json_encode(shrink($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            $pretty = mb_substr($body, 0, 1500);
        }

        $lines = explode("\n", $pretty);
        if (count($lines) > $maxLines) {
            $pretty = implode("\n", array_slice($lines, 0, $maxLines))."\n  …";
        }

        return $pretty;
    }

    return null;
}

function authLabel(?array $auth): string
{
    if (! $auth || ($auth['type'] ?? 'noauth') === 'noauth') {
        return '— (public)';
    }

    if ($auth['type'] === 'bearer') {
        $token = $auth['bearer'][0]['value'] ?? '';

        return match (true) {
            str_contains($token, 'consultant_token') => 'Consultant token (admin guard)',
            str_contains($token, 'admin_token') => 'Admin token',
            str_contains($token, 'client_token') => 'Client token',
            default => 'Bearer token',
        };
    }

    return $auth['type'];
}

function requestMarkdown(array $item, ?array $folderAuth): string
{
    $request = $item['request'];
    $method = $request['method'] ?? 'GET';
    $path = implode('/', $request['url']['path'] ?? []);
    $auth = authLabel($request['auth'] ?? $folderAuth);

    $md = "#### `{$method} /{$path}` — {$item['name']}\n\n";
    $md .= "**Auth:** {$auth}\n\n";

    $description = trim((string) ($request['description'] ?? ''));
    if ($description !== '') {
        $md .= $description."\n\n";
    }

    $query = $request['url']['query'] ?? [];
    if ($query !== []) {
        $md .= "| Query param | Notes |\n|---|---|\n";
        foreach ($query as $param) {
            $optional = ($param['disabled'] ?? false) ? ' *(optional)*' : '';
            $desc = trim((string) ($param['description'] ?? ''));
            $value = trim((string) ($param['value'] ?? ''));
            $notes = trim(($desc !== '' ? $desc : '').($value !== '' && ! str_starts_with($value, '{{') ? " e.g. `{$value}`" : ''));
            $md .= "| `{$param['key']}`{$optional} | ".($notes !== '' ? $notes : '—')." |\n";
        }
        $md .= "\n";
    }

    $body = $request['body'] ?? [];
    if (($body['mode'] ?? '') === 'raw' && trim((string) $body['raw']) !== '') {
        $md .= "Body:\n\n```json\n".trim($body['raw'])."\n```\n\n";
    } elseif (($body['mode'] ?? '') === 'formdata') {
        $md .= "Body (multipart/form-data):\n\n| Field | Type | Example |\n|---|---|---|\n";
        foreach ($body['formdata'] as $field) {
            $value = $field['type'] === 'file' ? '(file)' : ($field['value'] ?? '');
            $md .= "| `{$field['key']}` | {$field['type']} | {$value} |\n";
        }
        $md .= "\n";
    }

    $example = exampleBody($item);
    if ($example !== null) {
        $md .= "Example response:\n\n```json\n{$example}\n```\n\n";
    }

    return $md;
}

function folderMarkdown(array $folder, ?array $inheritedAuth): string
{
    $auth = $folder['auth'] ?? $inheritedAuth;
    $md = '';
    $leaves = array_filter($folder['item'] ?? [], fn ($i) => isset($i['request']));
    $subfolders = array_filter($folder['item'] ?? [], fn ($i) => ! isset($i['request']));

    if ($leaves !== []) {
        $md .= "### {$folder['name']}\n\n";
        foreach ($leaves as $leaf) {
            $md .= requestMarkdown($leaf, $auth);
        }
    }

    foreach ($subfolders as $sub) {
        $md .= folderMarkdown($sub, $auth);
    }

    return $md;
}

$reference = '';
$count = 0;
foreach ($collection['item'] as $folder) {
    $reference .= folderMarkdown($folder, $collection['auth'] ?? null);
    $count += countLeaves($folder);
}

function countLeaves(array $folder): int
{
    $n = 0;
    foreach ($folder['item'] ?? [] as $item) {
        $n += isset($item['request']) ? 1 : countLeaves($item);
    }

    return $n;
}

$start = '<!-- API-REFERENCE:START -->';
$end = '<!-- API-REFERENCE:END -->';
$pattern = '/'.preg_quote($start, '/').'.*'.preg_quote($end, '/').'/s';

if (! preg_match($pattern, $doc)) {
    fwrite(STDERR, "Markers not found in {$docPath}\n");
    exit(1);
}

$replacement = $start."\n\n".$reference.$end;
$doc = preg_replace($pattern, $replacement, $doc);
file_put_contents($docPath, $doc);

echo "API reference written: {$count} requests into {$docPath}\n";
