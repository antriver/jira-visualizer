<?php

namespace JiraVisualizer;

class GraphPage
{
    // Build the self-contained HTML page for a graph payload from JiraMermaidGraph::generateGraphData().
    // $statuses maps a status name to ['color' => '#hex', 'emoji' => '...'], $issueTypes maps a type name to an emoji.
    public static function render(array $data, array $statuses = [], array $issueTypes = []): string
    {
        // Escape angle brackets/ampersands/quotes so the JSON is safe to embed inside a <script> tag.
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

        // Cast to object so an empty map still encodes as {} rather than [].
        return strtr(file_get_contents(__DIR__.'/page.html'), [
            '__GRAPH_JSON__' => json_encode($data, $flags),
            '__STATUSES_JSON__' => json_encode((object) $statuses, $flags),
            '__TYPES_JSON__' => json_encode((object) $issueTypes, $flags),
            '__EPIC_KEY__' => htmlspecialchars($data['epic']['key'], ENT_QUOTES, 'UTF-8'),
        ]);
    }
}
