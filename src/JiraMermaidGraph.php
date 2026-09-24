<?php

namespace JiraVisualizer;

use GuzzleHttp\Client;

class JiraMermaidGraph
{
    // How long a cached Jira response stays usable.
    private const CACHE_TTL_SECONDS = 900;

    private Client $client;

    // Directory holding the cached Jira responses, one JSON file per request.
    private string $cacheDir;

    // Non-epic nodes to render, keyed by issue key.
    private array $nodes = [];

    // Deduped edges, keyed by "from<sep>to" => ['from','to','kind' => 'block'|'child','head'].
    private array $edges = [];

    // The epic at the root of the graph. Rendered specially, never as a normal node.
    private string $epicKey = '';

    // BFS tree parent of each node (childKey => parentKey), used by the renderer to nest children.
    private array $treeParent = [];

    // Constructor to initialize the Jira connection details
    public function __construct(
        private string $jiraUrl,
        string $jiraUsername,
        string $jiraApiToken,
        private bool $useCache = false
    ) {
        $this->cacheDir = dirname(__DIR__).'/cache';

        $authHeader = base64_encode("$jiraUsername:$jiraApiToken");

        // Create Guzzle client
        $this->client = new Client([
            'base_uri' => $jiraUrl,
            'headers' => [
                'Authorization' => 'Basic '.$authHeader,
                'Accept' => 'application/json',
            ],
        ]);
    }

    // Path of the JSON file holding one cached response.
    private function cacheFile(string $name): string
    {
        // Issue keys are alphanumeric, but sanitise anyway so a key can never escape the cache dir.
        return $this->cacheDir.'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $name).'.json';
    }

    // Read a cached response, or null when caching is off, the file is missing, or it has expired.
    private function cacheGet(string $name): ?array
    {
        if (!$this->useCache) {
            return null;
        }

        $file = $this->cacheFile($name);
        if (!is_file($file) || filemtime($file) < time() - self::CACHE_TTL_SECONDS) {
            return null;
        }

        $cached = json_decode(file_get_contents($file), true);

        return is_array($cached) ? $cached : null;
    }

    // Store a response for later runs. This writes even when reads are disabled (the default), so
    // the cache is kept fresh and ready for whenever --cache is used.
    private function cachePut(string $name, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }

        file_put_contents(
            $this->cacheFile($name),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    // Fetch the direct children of an issue (issues whose parent is $parentKey). Works at any
    // level of the hierarchy: epic -> story/task, and story/task -> sub-task.
    public function getChildIssues(string $parentKey): array
    {
        // is_array, not truthiness: most tasks have no children, and that empty list is worth
        // serving from the cache rather than asking Jira again.
        if (is_array($cached = $this->cacheGet("children-$parentKey"))) {
            return $cached;
        }

        $query = urlencode("parent = $parentKey");
        $fields = urlencode('key,summary,status,issueLinks,parent,assignee,issuetype,labels');

        // The search/jql endpoint returns at most 100 issues per page and a nextPageToken to fetch
        // the rest, so walk every page. Without this an epic with more children than fit on one page
        // would silently drop the overflow.
        $issues = [];
        $pageToken = null;

        try {
            do {
                $url = "rest/api/3/search/jql?jql=$query&fields=$fields&maxResults=100";
                if ($pageToken) {
                    $url .= '&nextPageToken='.urlencode($pageToken);
                }

                $response = $this->client->request('GET', $url);
                $data = json_decode($response->getBody(), true);

                foreach ($data['issues'] ?? [] as $issue) {
                    $issues[] = $issue;
                }

                $pageToken = $data['nextPageToken'] ?? null;
            } while ($pageToken);

            // Only cached once every page came back, so a half-walked list is never stored.
            $this->cachePut("children-$parentKey", $issues);
        } catch (\Exception $e) {
            echo "Error fetching child issues for $parentKey: ".$e->getMessage();
        }

        return $issues;
    }

    // All direct children of $key: classic sub-tasks (from the subtasks field on $data) merged
    // with parent-linked children (parent = $key), deduped by key. An issue may have both — an
    // epic's child stories/tasks, or a task's sub-tasks — so both sources are combined.
    private function allChildren(string $key, array $data): array
    {
        $children = [];
        foreach ($data['fields']['subtasks'] ?? [] as $sub) {
            if (!empty($sub['key'])) {
                $children[$sub['key']] = $sub;
            }
        }
        foreach ($this->getChildIssues($key) as $child) {
            if (!empty($child['key'])) {
                $children[$child['key']] = $child;
            }
        }

        return $children;
    }

    public function getJiraIssueData(string $taskKey): array
    {
        if (is_array($cached = $this->cacheGet("issue-$taskKey"))) {
            echo "Using cached data for issue $taskKey".PHP_EOL;

            return $cached;
        }

        echo "Fetching data for issue $taskKey".PHP_EOL;

        $url = "rest/api/3/issue/$taskKey?fields=issuelinks,assignee,parent,issuetype,labels,summary,status,subtasks";

        try {
            $response = $this->client->request('GET', $url);
            $data = json_decode($response->getBody(), true);

            // The method is typed to return an array; a non-JSON body would otherwise break that.
            if (!is_array($data) || !$data) {
                return [];
            }

            $this->cachePut("issue-$taskKey", $data);

            return $data;
        } catch (\Exception $e) {
            echo "Error fetching issue links for task $taskKey: ".$e->getMessage();
        }

        return [];
    }

    public function generateGraphData(string $epicKey): array
    {
        $this->epicKey = $epicKey;

        // Fetch the epic for its summary, status, and its own links.
        $epicData = $this->getJiraIssueData($epicKey);
        $epicSummary = str_replace('"', "'", $epicData['fields']['summary'] ?? $epicKey);
        $epicStatus = $epicData['fields']['status']['name'] ?? 'Unknown';

        echo PHP_EOL."Generating graph for {$epicKey}: {$epicSummary}".PHP_EOL.PHP_EOL;

        // Breadth-first walk outward from the epic. Each relationship is drawn once and oriented
        // away from the epic (the node closer to the epic sits above), so the result is a clean
        // acyclic top-down tree: the epic at the very top with everything it needs below it.
        $depth = [$epicKey => 0];
        $queue = [[$epicKey, $epicData]];
        $seenPair = [];

        while ($queue) {
            [$key, $data] = array_shift($queue);
            if ($data === null) {
                $data = $this->getJiraIssueData($key);
            }
            if (empty($data['fields'])) {
                continue;
            }
            // Link refs carry no assignee, so enrich the node from the full fetch.
            $this->addNode($data);
            $dx = $depth[$key];

            // Collect related issues as [ref, kind, headKey] where headKey is the blocked /
            // dependent end (the node the arrowhead should point at).
            $related = [];
            foreach ($data['fields']['issuelinks'] ?? [] as $link) {
                $linkName = $link['type']['name'] ?? null;
                if ($linkName === 'Blocks') {
                    $kind = 'block';
                } elseif ($linkName === 'Problem/Incident' || $linkName === 'Causes') {
                    // Jira models the "causes / is caused by" relationship as "Problem/Incident".
                    $kind = 'cause';
                } else {
                    continue;
                }
                // For both Blocks and Causes the head (arrowhead) is the dependent end: the issue
                // that is blocked, or the issue that is caused.
                if (!empty($link['inwardIssue'])) {
                    // inwardIssue blocks / causes $key
                    $related[] = [$link['inwardIssue'], $kind, $key];
                }
                if (!empty($link['outwardIssue'])) {
                    // $key blocks / causes outwardIssue
                    $related[] = [$link['outwardIssue'], $kind, $link['outwardIssue']['key']];
                }
            }
            foreach ($this->allChildren($key, $data) as $child) {
                // a child / sub-task blocks its parent ($key)
                $related[] = [$child, 'child', $key];
            }

            foreach ($related as [$ref, $kind, $headKey]) {
                $rk = $ref['key'] ?? null;
                if ($rk === null || $rk === $key) {
                    continue;
                }
                if ($this->isClosedStatus($ref['fields']['status']['name'] ?? 'Unknown')) {
                    continue;
                }

                // A child / sub-task link is the authoritative hierarchy parent — it wins over a
                // node that merely discovered this one first via a block link.
                if ($kind === 'child') {
                    $this->treeParent[$rk] = $key;
                }

                $pair = $key < $rk ? "{$key}\x1f{$rk}" : "{$rk}\x1f{$key}";
                if (isset($seenPair[$pair])) {
                    continue;
                }
                $seenPair[$pair] = true;

                $this->addNode($ref);
                if (!isset($depth[$rk])) {
                    $depth[$rk] = $dx + 1;
                    if (!isset($this->treeParent[$rk])) {
                        $this->treeParent[$rk] = $key;
                    }
                    $queue[] = [$rk, null];
                }

                // Orient the edge from the node closer to the epic (smaller depth) downward.
                $dr = $depth[$rk];
                if ($dr > $dx) {
                    $from = $key;
                    $to = $rk;
                } elseif ($dr < $dx) {
                    $from = $rk;
                    $to = $key;
                } else {
                    $from = $key < $rk ? $key : $rk;
                    $to = $key < $rk ? $rk : $key;
                }
                $this->addEdge($from, $to, $kind, $headKey);
            }
        }

        return $this->buildGraphData($epicKey, $epicSummary, $epicStatus);
    }

    // Record a non-epic node, keyed by issue key. Accepts either a full issue response
    // (from getJiraIssueData) or a bare link/subtask ref (e.g. issuelinks[n]['inwardIssue']);
    // both carry 'key' and 'fields' at the top level. Enriches an existing record with an
    // assignee when a fuller fetch provides one (link/subtask refs don't include the assignee).
    private function addNode(array $issue): void
    {
        $key = $issue['key'] ?? null;
        if ($key === null || $key === $this->epicKey) {
            return;
        }

        $summary = $issue['fields']['summary'] ?? $key;
        $assignee = $issue['fields']['assignee']['displayName'] ?? null;

        if (!isset($this->nodes[$key])) {
            $this->nodes[$key] = [
                'key' => $key,
                'summary' => $summary,
                'status' => $issue['fields']['status']['name'] ?? 'Unknown',
                'assignee' => $assignee,
                'type' => $issue['fields']['issuetype']['name'] ?? 'Task',
            ];

            return;
        }

        if ($assignee !== null) {
            $this->nodes[$key]['assignee'] = $assignee;
        }
    }

    private function addEdge(string $from, string $to, string $kind, string $head): void
    {
        // Keyed by endpoints so duplicate links between the same pair collapse to one edge.
        $this->edges["{$from}\x1f{$to}"] = ['from' => $from, 'to' => $to, 'kind' => $kind, 'head' => $head];
    }

    // Assemble the node + edge data the front-end renderer draws from.
    private function buildGraphData(string $epicKey, string $epicSummary, string $epicStatus): array
    {
        $nodes = [];

        // Epic centre node (collected separately from $this->nodes, which never holds the epic).
        $nodes[] = [
            'key' => $epicKey,
            'summary' => $epicSummary,
            'status' => $epicStatus,
            'assignee' => null,
            'type' => 'Epic',
            'isEpic' => true,
            'parent' => null,
            'url' => "{$this->jiraUrl}/browse/{$epicKey}",
        ];

        foreach ($this->nodes as $key => $node) {
            $nodes[] = [
                'key' => $key,
                'summary' => $node['summary'],
                'status' => $node['status'],
                'assignee' => $node['assignee'],
                'type' => $node['type'] ?? 'Task',
                'isEpic' => false,
                'parent' => $this->treeParent[$key] ?? $epicKey,
                'url' => "{$this->jiraUrl}/browse/{$key}",
            ];
        }

        return [
            'epic' => [
                'key' => $epicKey,
                'summary' => $epicSummary,
                'status' => $epicStatus,
            ],
            'nodes' => $nodes,
            'edges' => array_values($this->edges),
        ];
    }

    // Helper method to check if a task is in a "closed" or completed status
    private function isClosedStatus($status): bool
    {
        return in_array(
            $status,
            [
                'Done',
                'Closed',
                'Resolved',
            ]
        );
    }
}
