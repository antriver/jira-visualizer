<?php

// Renders examples/demo.html from made-up data, so the output can be previewed without a Jira account.

use JiraVisualizer\GraphPage;

require dirname(__DIR__).'/vendor/autoload.php';

$config = require dirname(__DIR__).'/config.example.php';

$epic = ['key' => 'SHOP-100', 'summary' => 'Redesign the checkout flow', 'status' => 'In Progress'];

// [key, summary, status, assignee, type, parent]
$issues = [
    ['SHOP-101', 'Single-page checkout layout', 'In Progress', 'Sam Lee', 'Story', 'SHOP-100'],
    ['SHOP-102', 'Guest checkout without an account', 'To Do', 'Priya Patel', 'Story', 'SHOP-100'],
    ['SHOP-103', 'Apple Pay and Google Pay buttons', 'To Do', 'Jordan Diaz', 'Story', 'SHOP-100'],
    ['SHOP-104', 'Save cards for returning customers', 'Backlog', null, 'Task', 'SHOP-100'],
    ['SHOP-105', 'Discount code lost on page refresh', 'QA Ready', 'Alex Morgan', 'Bug', 'SHOP-100'],
    ['SHOP-106', 'Evaluate address autocomplete providers', 'In Progress', 'Chris Novak', 'Spike', 'SHOP-100'],
    ['SHOP-107', 'Update checkout analytics events', 'Blocked', 'Alex Morgan', 'Task', 'SHOP-100'],
    ['SHOP-110', 'Build address form component', 'In Review', 'Sam Lee', 'Sub-task', 'SHOP-101'],
    ['SHOP-111', 'Order summary sidebar', 'To Do', 'Sam Lee', 'Sub-task', 'SHOP-101'],
    ['PAY-12', 'Payments API v2', 'In Progress', 'Dana Kim', 'Epic', 'SHOP-103'],
    ['PAY-42', 'Expose tokenised card endpoint', 'In Progress', 'Dana Kim', 'Task', 'SHOP-104'],
    ['AUTH-9', 'Allow anonymous sessions to hold a cart', 'In Review', 'Riley Chen', 'Task', 'SHOP-102'],
    ['SUP-318', 'Customer reports lost discount code', 'On Hold', null, 'Task', 'SHOP-105'],
];

// [from, to, kind, head] where head is the blocked or caused end, as JiraMermaidGraph emits them.
$links = [
    ['SHOP-103', 'PAY-12', 'block', 'SHOP-103'],
    ['SHOP-104', 'PAY-42', 'block', 'SHOP-104'],
    ['SHOP-102', 'AUTH-9', 'block', 'SHOP-102'],
    ['SHOP-101', 'SHOP-107', 'block', 'SHOP-107'],
    ['SHOP-105', 'SUP-318', 'cause', 'SUP-318'],
];

$url = fn (string $key) => "https://example.atlassian.net/browse/{$key}";

$nodes = [[
    'key' => $epic['key'],
    'summary' => $epic['summary'],
    'status' => $epic['status'],
    'assignee' => null,
    'type' => 'Epic',
    'isEpic' => true,
    'parent' => null,
    'url' => $url($epic['key']),
]];
$edges = [];

foreach ($issues as [$key, $summary, $status, $assignee, $type, $parent]) {
    $nodes[] = [
        'key' => $key,
        'summary' => $summary,
        'status' => $status,
        'assignee' => $assignee,
        'type' => $type,
        'isEpic' => false,
        'parent' => $parent,
        'url' => $url($key),
    ];
}

// Hierarchy edges point from parent to child, with the parent as the head.
foreach ($issues as [$key, , , , $type, $parent]) {
    if ($type === 'Sub-task' || $parent === $epic['key']) {
        $edges[] = ['from' => $parent, 'to' => $key, 'kind' => 'child', 'head' => $parent];
    }
}
foreach ($links as [$from, $to, $kind, $head]) {
    $edges[] = ['from' => $from, 'to' => $to, 'kind' => $kind, 'head' => $head];
}

$html = GraphPage::render(
    ['epic' => $epic, 'nodes' => $nodes, 'edges' => $edges],
    $config['statuses'],
    $config['issueTypes']
);

file_put_contents(__DIR__.'/demo.html', $html);
echo 'Wrote output to '.__DIR__."/demo.html\n";
