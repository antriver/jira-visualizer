# Jira Visualizer

This project is a simple tool to visualize the connection between issues in a Jira epic Jira issues in a graph.
It uses the Jira API to fetch issues and their relationships, and then uses [https://mermaid.js.org/](mermaid.js) to render the graph.

## Usage

- Install dependencies:
  (There actually are none, but this sets up the composer autoloader)
```
composer install
```

- Create a config file:
```
cp config.example.php config.php
```

- Edit the `config.php` file and set the appropriate values.

- Run the script to generate a graph of the issues in the specified epic:
For example:
```
php generate.php PROP-292
```
