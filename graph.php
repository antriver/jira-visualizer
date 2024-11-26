<?php

require 'vendor/autoload.php'; // Make sure to include Guzzle via Composer

use GuzzleHttp\Client;

class JiraMermaidGraph
{
    private Client $client;

    // Constructor to initialize the Jira connection details
    public function __construct(
        string $jiraUrl,
        string $jiraUsername,
        string $jiraApiToken
    ) {
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

    // Method to fetch tasks from Jira based on the Epic key
    public function getTasksFromEpic($epicKey)
    {
        $query = urlencode('"Epic Link" = '.$epicKey);
        $fields = urlencode('key,summary,status,issueLinks'); // Task fields to retrieve
        $url = "rest/api/3/search?jql=$query&fields=$fields";

        try {
            $response = $this->client->request('GET', $url);
            $data = json_decode($response->getBody(), true);

            if (isset($data['issues'])) {
                return $data['issues'];
            }
        } catch (\Exception $e) {
            echo "Error fetching tasks for Epic $epicKey: ".$e->getMessage();
        }

        return [];
    }

    // Method to fetch issue links for a task
    public function getIssueLinks($taskKey)
    {
        $url = "rest/api/3/issue/$taskKey"; // Get issue details for a specific task

        try {
            $response = $this->client->request('GET', $url);
            $data = json_decode($response->getBody(), true);

            // Return the issue links if available
            return $data['fields']['issuelinks'] ?? [];
        } catch (\Exception $e) {
            echo "Error fetching issue links for task $taskKey: ".$e->getMessage();
        }

        return [];
    }

    public function generateMermaidGraph($epicKeys)
    {
        $mermaidGraph = "graph TD\n";

        // Epic name for the feature branches (without adding a separate block for Epic)
        $epicName = implode('', $epicKeys);  // Using the epic key as the name for the branch
        $epicLabel = implode(', ', $epicKeys); // Use the Epic key as the label

        // Define APP and EPOS feature branches with Epic name
        $mermaidGraph .= "  {$epicName}App[\"{$epicLabel} app feature branch\"]\n";
        $mermaidGraph .= "  {$epicName}Epos[\"{$epicLabel} epos feature branch\"]\n";
        $mermaidGraph .= "  class {$epicName}App fill:#c1d3f5,stroke:#333,stroke-width:2px\n";
        $mermaidGraph .= "  class {$epicName}Epos fill:#e3f1f5,stroke:#333,stroke-width:2px\n";

        $tasksWithLinks = [];
        $tasksWithoutDependencies = [];

        // Loop through each Epic to fetch tasks and generate Mermaid nodes
        foreach ($epicKeys as $epicKey) {
            $tasks = $this->getTasksFromEpic($epicKey);

            foreach ($tasks as $task) {
                echo "{$task['key']} - {$task['fields']['summary']} ({$task['fields']['status']['name']})" . PHP_EOL;

                // Skip tasks with a "Closed" or "Done" status
                $status = $task['fields']['status']['name'];
                if ($this->isClosedStatus($status)) {
                    continue; // Skip closed tasks
                }

                $taskKey = $task['key'];
                // Use the task key directly for Mermaid compatibility
                $mermaidTaskKey = $taskKey;

                $summary = $task['fields']['summary'];
                $summary = str_replace('"', "'", $summary); // Replace double quotes with single quotes

                // Check for APP/EPOS label based on the task summary
                $label = $this->getAppEposLabel($summary);

                // Assign color based on the status
                $color = $this->getStatusColor($status);

                // Add task to the Mermaid graph with the task key, summary, and status
                $mermaidGraph .= "  {$mermaidTaskKey}[\"$taskKey<br/>$label<br/>$summary<br/><b>$status</b>\"]\n";
                $mermaidGraph .= "  class $mermaidTaskKey fill:$color,stroke:#333,stroke-width:2px\n";

                // Store tasks that are not blocked by anything else (for linking to feature branches)
                $isBlocked = false;

                // Fetch issue links separately for each task
                $taskLinks = $this->getIssueLinks($taskKey);
                $tasksWithLinks[$taskKey] = $taskLinks;

                // Check if task is blocked by any other task
                foreach ($taskLinks as $link) {
                    if (isset($link['type']['name']) && $link['type']['name'] === 'Blocked by') {
                        $isBlocked = true;
                        break;
                    }
                }

                // If not blocked by anything, add task to the corresponding feature branch
                if (!$isBlocked) {
                    if ($label === 'APP') {
                        $mermaidGraph .= "  {$epicName}App --> {$mermaidTaskKey}\n";
                    } else {
                        $mermaidGraph .= "  {$epicName}Epos --> {$mermaidTaskKey}\n";
                    }
                }

                // Store task links for later processing
                $tasksWithoutDependencies[$taskKey] = $taskLinks;
            }
        }

        // Add edges for the links between tasks (e.g., blocked by / blocks)
        foreach ($tasksWithLinks as $taskKey => $taskLinks) {
            // Use the task key directly for Mermaid compatibility
            $mermaidTaskKey = $taskKey;

            foreach ($taskLinks as $link) {
                // Determine the link type (blocks)
                if (isset($link['type']['name'])) {
                    $linkType = $link['type']['name'];
                    $linkedIssueKey = $link['outwardIssue']['key'] ?? $link['inwardIssue']['key'];
                    $mermaidLinkedIssueKey = $linkedIssueKey;

                    // Get the labels (APP/EPOS) for both tasks involved in the link
                    $taskLabel = $this->getAppEposLabel($task['fields']['summary']);
                    $linkedTaskLabel = $this->getAppEposLabel($link['outwardIssue']['fields']['summary'] ?? $link['inwardIssue']['fields']['summary']);

                    // Determine whether to use solid or dashed line based on task types
                    if ($linkType === 'Blocks' && isset($link['outwardIssue'])) {
                        if ($taskLabel === $linkedTaskLabel) {
                            // Solid line
                            $mermaidGraph .= "  {$mermaidTaskKey} --> {$mermaidLinkedIssueKey}\n";
                        } else {
                            // Dashed line
                            $mermaidGraph .= "  {$mermaidTaskKey} -.-> {$mermaidLinkedIssueKey}\n";
                        }
                    }
                }
            }
        }

        return $mermaidGraph;
    }

    // Helper method to get APP/EPOS label based on task summary
    private function getAppEposLabel($summary): string
    {
        // Check if the task title starts with [API] or [BO] for APP, otherwise it's EPOS
        if (preg_match('/\[(APP|API|BO)]/', $summary)) {
            return "APP";
        }

        return "EPOS";
    }

    // Helper method to determine the color based on task status
    private function getStatusColor($status): string
    {
        return match ($status) {
            'To Do' => "#A0C8D4",
            'In Progress' => "#A8D0E6",
            'In Review' => "#F7A7C5",
            'Done' => "#F06C9B",
            default => "#FFFFFF",
        };
    }

    // Helper method to check if a task is in a "closed" or completed status
    private function isClosedStatus($status): bool
    {
        // Define which statuses are considered closed (Done, Closed, etc.)
        $closedStatuses = ['Done', 'Closed', 'Resolved']; // Add more statuses as needed

        return in_array($status, $closedStatuses);
    }
}

$config = require 'config.php';

// Example usage
$epicKeys = ['PROP-292'];

$jiraGraph = new JiraMermaidGraph(
    $config['jiraUrl'],
    $config['jiraUser'],
    $config['jiraApiToken']
);
$mermaidCode = $jiraGraph->generateMermaidGraph($epicKeys);

// Output Mermaid.js graph code
echo PHP_EOL;
echo $mermaidCode;
echo PHP_EOL;
