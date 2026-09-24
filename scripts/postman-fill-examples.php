<?php

/**
 * Fills the Postman collection's saved response examples with the real
 * bodies captured in a newman JSON report (plan §14.4.10).
 *
 * Usage:
 *   npx newman run postman/GCMC-API.postman_collection.json \
 *     -e postman/GCMC-Local.postman_environment.json --working-dir postman \
 *     --env-var ci=true --reporters json --reporter-json-export /tmp/newman-report.json
 *   php scripts/postman-fill-examples.php /tmp/newman-report.json
 */
$reportPath = $argv[1] ?? '/tmp/newman-report.json';
$collectionPath = 'postman/GCMC-API.postman_collection.json';

$collection = json_decode(file_get_contents($collectionPath), true, 512, JSON_THROW_ON_ERROR);
$report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

$decode = function ($stream): string {
    if (is_array($stream) && ($stream['type'] ?? '') === 'Buffer') {
        return implode(array_map('chr', $stream['data']));
    }

    return is_string($stream) ? $stream : (string) json_encode($stream);
};

// First execution per item name wins (pm.sendRequest replays come later).
$executions = [];
foreach ($report['run']['executions'] as $execution) {
    $name = $execution['item']['name'] ?? null;
    if ($name !== null && ! isset($executions[$name])) {
        $executions[$name] = $execution;
    }
}

$filled = 0;
$walk = function (array &$items) use (&$walk, &$filled, $executions, $decode): void {
    foreach ($items as &$item) {
        if (isset($item['item'])) {
            $walk($item['item']);

            continue;
        }
        $name = $item['name'] ?? null;
        $execution = $name !== null ? ($executions[$name] ?? null) : null;
        if ($execution === null) {
            continue; // manual-only request: no saved example
        }

        $body = $decode($execution['response']['stream'] ?? '');
        $isJson = json_decode($body) !== null || $body === '';

        $item['response'] = [[
            'name' => $name.' — example',
            'originalRequest' => $item['request'],
            'status' => $execution['response']['status'] ?? 'OK',
            'code' => $execution['response']['code'] ?? 200,
            '_postman_previewlanguage' => $isJson ? 'json' : null,
            'header' => array_map(
                fn (array $h) => ['key' => $h['key'], 'value' => $h['value']],
                $execution['response']['header'] ?? [],
            ),
            'cookie' => [],
            'body' => $body,
        ]];
        $filled++;
    }
};
$walk($collection['item']);

file_put_contents(
    $collectionPath,
    json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

echo "Filled {$filled} saved examples into {$collectionPath}\n";
