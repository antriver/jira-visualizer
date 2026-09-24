<?php

use JiraVisualizer\GraphPage;
use JiraVisualizer\JiraMermaidGraph;

require __DIR__.'/vendor/autoload.php';

$config = require __DIR__.'/config.php';

// Get the epic key from the command line arguments. Flags are pulled out first so they can sit on
// either side of the key.
$args = array_slice($argv, 1);
$useCache = in_array('--cache', $args, true);
$args = array_values(array_filter($args, fn ($arg) => !str_starts_with($arg, '--')));

if (!$args) {
    echo "Usage: php generate.php <epicKey> [--cache]\n";
    exit(1);
}
$epicKey = strtoupper($args[0]);

// The key ends up in a file path and the publish command, so only accept a real issue key.
if (!preg_match('/^[A-Z][A-Z0-9_]*-\d+$/', $epicKey)) {
    echo "Invalid issue key: {$args[0]}\n";
    exit(1);
}

$jiraGraph = new JiraMermaidGraph(
    $config['jiraUrl'],
    $config['jiraUser'],
    $config['jiraApiToken'],
    $useCache
);

$data = $jiraGraph->generateGraphData($epicKey);
$html = GraphPage::render($data, $config['statuses'] ?? [], $config['issueTypes'] ?? []);

// Use the epic key as the filename:
$outputFilename = __DIR__."/output/{$epicKey}.html";
file_put_contents($outputFilename, $html);
echo "Wrote output to {$outputFilename}\n";

// Optionally hand the file to a user-defined command, e.g. to copy it to a web server.
if (!empty($config['publishCommand'])) {
    $publishCmd = strtr($config['publishCommand'], [
        '{file}' => escapeshellarg($outputFilename),
        '{key}' => $epicKey,
    ]);
    echo "Running publish command: {$publishCmd}\n";
    passthru($publishCmd.' 2>&1', $publishCode);
    if ($publishCode === 0) {
        echo "Published {$epicKey}\n";
    } else {
        echo "WARNING: publish command failed (exit {$publishCode})".PHP_EOL;
    }
} else {
    echo "No publishCommand in config.php, skipping publish\n";
}
