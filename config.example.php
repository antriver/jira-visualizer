<?php

return [
    'jiraUrl' => 'https://yourcompany.atlassian.net/',
    'jiraUser' => 'you@example.com',
    // Generate an API token for your account using these instructions:
    // https://support.atlassian.com/atlassian-account/docs/manage-api-tokens-for-your-atlassian-account/
    'jiraApiToken' => 'abc',

    // Card colour and icon for each status in your workflow. Unlisted statuses are shown in grey.
    'statuses' => [
        'Backlog' => ['color' => '#94a3b8', 'emoji' => '⚪️'],
        'To Do' => ['color' => '#94a3b8', 'emoji' => '⚪️'],
        'Selected for Development' => ['color' => '#64748b', 'emoji' => '⚪️'],
        'In Progress' => ['color' => '#3b82f6', 'emoji' => '🔵'],
        'In Review' => ['color' => '#eab308', 'emoji' => '🟠'],
        'QA Ready' => ['color' => '#a3e635', 'emoji' => '🧪'],
        'QA In Progress' => ['color' => '#a3e635', 'emoji' => '🧪'],
        'QA Passed' => ['color' => '#16a34a', 'emoji' => '🟢'],
        'Blocked' => ['color' => '#ef4444', 'emoji' => '🔴'],
        'On Hold' => ['color' => '#ef4444', 'emoji' => '🔴'],
        'Done' => ['color' => '#16a34a', 'emoji' => '✅'],
    ],

    // Icon shown before the summary for each issue type. Unlisted types get a pin.
    'issueTypes' => [
        'Epic' => '🏆',
        'Story' => '📖',
        'Task' => '📝',
        'Bug' => '💣',
        'Spike' => '🔬',
        'Feature' => '🚀',
        'Improvement' => '✨',
        'Sub-task' => '🔹',
        'Subtask' => '🔹',
    ],

    // Optional shell command run after each page is written, e.g. to copy it to a web server.
    // {file} is replaced with the path of the generated page and {key} with the epic key.
    // 'publishCommand' => 'scp {file} user@server.example.com:/var/www/jira/{key}.html',
    'publishCommand' => null,
];
