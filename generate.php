<?php

use JiraVisualizer\JiraMermaidGraph;

require __DIR__.'/vendor/autoload.php';

$config = require __DIR__.'/config.php';

// Get the epic key from the command line arguments:
if ($argc < 2) {
    echo "Usage: php generate.php <epicKey>\n";
    exit(1);
}
$epicKey = $argv[1];

$jiraGraph = new JiraMermaidGraph(
    $config['jiraUrl'],
    $config['jiraUser'],
    $config['jiraApiToken']
);
$mermaidCode = $jiraGraph->generateMermaidGraph($epicKey);

$html = <<<HTML
<!DOCTYPE html>
<body>
<pre class="mermaid">
{$mermaidCode}
</pre>
<script type="module">
import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
mermaid.initialize({ startOnLoad: true });
</script>
</body>
HTML;

// Use the epic key as the filename:
$outputFilename = __DIR__."/output/{$epicKey}.html";
file_put_contents($outputFilename, $html);
echo "Wrote output to {$outputFilename}\n";
