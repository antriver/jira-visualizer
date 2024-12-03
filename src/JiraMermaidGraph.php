<?php

namespace JiraVisualizer;

use GuzzleHttp\Client;

class JiraMermaidGraph
{
    private Client $client;

    private array $tasks = [];

    private array $taskLinks = [];

    // Constructor to initialize the Jira connection details
    public function __construct(
        private string $jiraUrl,
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
        $fields = urlencode('key,summary,status,issueLinks,parent,assignee'); // Task fields to retrieve
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

    public function getJiraIssueData(string $taskKey): array
    {
        echo "Fetching data for issue $taskKey".PHP_EOL;

        $url = "rest/api/3/issue/$taskKey?fields=issuelinks,assignee,parent"; // Get issue details for a specific task

        try {
            $response = $this->client->request('GET', $url);
            $data = json_decode($response->getBody(), true);

            return $data;
        } catch (\Exception $e) {
            echo "Error fetching issue links for task $taskKey: ".$e->getMessage();
        }

        return [];
    }

    private function addJiraTaskToList(array $jiraIssueData): void
    {
        // Skip tasks with a "Closed" or "Done" status
        $status = $jiraIssueData['fields']['status']['name'];
        if ($this->isClosedStatus($status)) {
            echo "Task {$jiraIssueData['key']} is closed. Skipping".PHP_EOL;
            return;
        }

        $taskKey = $jiraIssueData['key'];

        // Fetch any dependencies of this task.
        // We need to fetch this again because the original response to get all tasks
        // does not include the issue links.
        $soloIssueData = $this->getJiraIssueData($taskKey);

        $this->tasks[$taskKey] = [
            'key' => $taskKey,
            'summary' => $jiraIssueData['fields']['summary'],
            'status' => $jiraIssueData['fields']['status']['name'],
            'label' => $this->getAppEposLabel($jiraIssueData['fields']['summary']),
            'parentTaskKey' => $soloIssueData['fields']['parent']['key'] ?? null,
            'assignee' => $soloIssueData['fields']['assignee']['displayName'] ?? null,
        ];

        $issueLinks = $this->taskLinks[$taskKey] = $soloIssueData ? $soloIssueData['fields']['issuelinks'] : [];

        // Add all the dependencies as tasks too.
        foreach ($issueLinks as $issueLink) {
            if ($issueLink['type']['name'] !== 'Blocks') {
                // We only want to visualise blocking tasks, not "relates to".
                continue;
            }

            if (!empty($issueLink['outwardIssue'])) {
                $linkedIssue = $issueLink['outwardIssue'];
            } else {
                $linkedIssue = $issueLink['inwardIssue'];
            }

            $linkedIssueKey = $linkedIssue['key'];
            if (empty($this->tasks[$linkedIssueKey])) {
                $this->addJiraTaskToList($linkedIssue);
            }
        }
    }

    public function generateMermaidGraph(string $epicKey): string
    {
        $mermaidGraph = [];
        $mermaidGraph[] = "graph TD";

        // Define classDef styles for APP and EPOS tasks
        $classDefs = [
            'AppInProgress' => 'fill:#ffeaa7,stroke:#333,stroke-width:2px',
            'AppBlocked' => 'fill:#e17055,stroke:#333,stroke-width:2px',
            'EPOSInProgress' => 'fill:#81ecec,stroke:#333,stroke-width:2px',
            'EPOSBlocked' => 'fill:#22a6b3,stroke:#333,stroke-width:2px',
        ];

        foreach ($classDefs as $className => $style) {
            $mermaidGraph[] = "  classDef $className $style;";
        }

        // Epic name for the feature branches (without adding a separate block for Epic)

        // Define APP and EPOS feature branches with Epic name
        $mermaidGraph[] = "  {$epicKey}App[\"{$epicKey} App Feature Branch\"]";
        $mermaidGraph[] = "  class {$epicKey}App AppBlocked";

        $mermaidGraph[] = "  {$epicKey}EPOS[\"{$epicKey} EPOS Feature Branch\"]";
        $mermaidGraph[] = "  class {$epicKey}EPOS EPOSBlocked";

        // Fetch all the tasks and any linked issues.
        $epicTasks = $this->getTasksFromEpic($epicKey);

        echo "Found ".count($epicTasks)." tasks for epic $epicKey".PHP_EOL;

        // Build a list of tasks.
        foreach ($epicTasks as $task) {
            // echo "Processing {$task['key']} - {$task['fields']['summary']} ({$task['fields']['status']['name']})".PHP_EOL;

            // Skip tasks with a "Closed" or "Done" status
            $status = $task['fields']['status']['name'];
            if ($this->isClosedStatus($status)) {
                // echo "Task is closed. Skipping".PHP_EOL;
                continue; // Skip closed tasks
            }

            $this->addJiraTaskToList($task);
        }

        // Build an array of which tasks block a given task.
        // The first-level keys are the task keys which may be blocked, and each item is an array of tasks that blocks
        // that task.
        $tasksBlockedBy = [];

        // Build the links between tasks.
        foreach ($this->taskLinks as $taskKey => $links) {
            foreach ($links as $link) {
                if ($link['type']['name'] === 'Blocks') {
                    if (!empty($link['outwardIssue'])) {
                        // This task blocks another task.
                        $type = 'blocks';
                        $linkedIssue = $link['outwardIssue'];
                    } else {
                        // This task is blocked by another task.
                        $type = 'blocked by';
                        $linkedIssue = $link['inwardIssue'];
                    }

                    $linkedIssueKey = $linkedIssue['key'];
                    $linkedIssueSummary = $linkedIssue['fields']['summary'];
                    $linkedIssueStatus = $linkedIssue['fields']['status']['name'];
                    $sameApp = $this->getAppEposLabel($this->tasks[$taskKey]['summary']) === $this->getAppEposLabel(
                            $linkedIssueSummary
                        );

                    if ($this->isClosedStatus($linkedIssueStatus)) {
                        // echo "Linked task $linkedIssueKey is closed. Skipping".PHP_EOL;
                        continue; // Skip closed tasks
                    }

                    if ($type === 'blocks') {
                        // Add that the linked task is blocked by this task.

                        // If this task blocks the epic itself, handle it differently.
                        if ($linkedIssueKey === $epicKey) {
                            $label = $this->getAppEposLabel($linkedIssueSummary);
                            $blockedEpicKey = $label === 'APP' ? "{$epicKey}APP" : "{$epicKey}EPOS";
                            $tasksBlockedBy[$blockedEpicKey][$taskKey] = [
                                'key' => $taskKey,
                                'sameApp' => true,
                            ];
                            continue;
                        }

                        $tasksBlockedBy[$linkedIssueKey][$taskKey] = [
                            'key' => $linkedIssueKey,
                            'sameApp' => $sameApp,
                        ];
                    } elseif ($type === 'blocked by') {
                        // Add that this task is blocked by the linked task.
                        $tasksBlockedBy[$taskKey][$linkedIssueKey] = [
                            'key' => $taskKey,
                            'sameApp' => $sameApp,
                        ];
                    }

                    // If the linked issue is not present in the tasks list, add it in.
                    if (empty($this->tasks[$linkedIssueKey])) {
                        $this->addJiraTaskToList($linkedIssue);
                    }
                }
            }
        }

        foreach ($this->tasks as $taskKey => $task) {
            // If this task is in the epic, and it's not blocked by anything else in the epic,
            // mark it as blocked by the appropriate feature branch.

            if (empty($task['parentTaskKey']) || $task['parentTaskKey'] !== $epicKey) {
                // This task is not in the Epic.
                continue;
            }

            // Check if this is blocked by anything else in the Epic.
            foreach ($tasksBlockedBy[$taskKey] ?? [] as $blockingTaskKey => $blockingTask) {
                $blockingTaskData = $this->tasks[$blockingTaskKey];

                if (
                    !$this->isClosedStatus($blockingTaskData['status'])
                    && $this->getAppEposLabel($blockingTaskData['summary']) === $this->getAppEposLabel($task['summary'])
                    && !empty($blockingTaskData['parentTaskKey'])
                    && $blockingTaskData['parentTaskKey'] === $epicKey
                ) {
                    // This task is blocked by something else in the Epic,
                    // so a path back to the feature branch should already be established.
                    continue 2;
                }
            }

            // This task is not blocked by anything else in the Epic.

            $appLabel = $this->getAppEposLabel($task['summary']);
            $blockingEpicKey = $appLabel === 'APP' ? "{$epicKey}App" : "{$epicKey}EPOS";

            // Add a line from the feature branch blocking this task.
            $tasksBlockedBy[$taskKey][$blockingEpicKey] = [
                'key' => $epicKey,
                'sameApp' => true,
            ];
        }

        // Output the tasks.
        foreach ($this->tasks as $taskKey => $task) {
            $summary = str_replace('"', "'", $task['summary']); // Replace double quotes with single quotes

            // Check for APP/EPOS label based on the task summary
            $label = $this->getAppEposLabel($summary);

            $status = $task['status'];

            $assignee = $task['assignee'] ?? 'Unassigned';

            // Add task to the Mermaid graph with the task key, summary, and status
            $url = "{$this->jiraUrl}/browse/$taskKey";
            $mermaidGraph[] = "  {$taskKey}[\"<a href='$url' target='_blank'><strong>$taskKey</strong></a><br/>$label<br/>$summary<br/><b>$status ($assignee)</b>\"]";

            $isBlocked = $this->isBlockedStatus($status);
            if ($label === 'APP') {
                $mermaidGraph[] = "  class $taskKey ".($isBlocked ? 'AppBlocked' : 'AppInProgress');
            } else {
                $mermaidGraph[] = "  class $taskKey ".($isBlocked ? 'EPOSBlocked' : 'EPOSInProgress');
            }
        }

        // Output the links between tasks.
        foreach ($tasksBlockedBy as $blockeeKey => $blockingTasks) {
            foreach ($blockingTasks as $blockerKey => $blockingTask) {
                if ($blockingTask['sameApp']) {
                    $mermaidGraph[] = "  {$blockerKey} --> {$blockeeKey}";
                } else {
                    $mermaidGraph[] = "  {$blockerKey} -.-> {$blockeeKey}";
                }
            }
        }

        return implode("\n", $mermaidGraph);
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

    private function isBlockedStatus(string $status): bool
    {
        return in_array(
            $status,
            [
                'On Hold',
                'In Review',
                'QA Ready',
                'QA In Progress',
            ]
        );
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
