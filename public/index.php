<?php
/**
 * php-microapi — a tiny JSON REST API in a single file, no framework.
 * Run: php -S localhost:8080 public/index.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

final class TaskStore
{
    /** In-memory store (resets on each request in the built-in server). */
    private array $tasks = [];
    private int $nextId = 1;

    public function all(): array
    {
        return array_values($this->tasks);
    }

    public function find(int $id): ?array
    {
        return $this->tasks[$id] ?? null;
    }

    public function create(string $title, bool $done = false): array
    {
        $task = [
            'id'         => $this->nextId++,
            'title'      => $title,
            'done'       => $done,
            'created_at' => date('c'),
        ];
        $this->tasks[$task['id']] = $task;
        return $task;
    }

    public function update(int $id, array $patch): ?array
    {
        if (!isset($this->tasks[$id])) {
            return null;
        }
        if (isset($patch['title']) && is_string($patch['title'])) {
            $this->tasks[$id]['title'] = trim($patch['title']);
        }
        if (isset($patch['done']) && is_bool($patch['done'])) {
            $this->tasks[$id]['done'] = $patch['done'];
        }
        return $this->tasks[$id];
    }

    public function delete(int $id): bool
    {
        if (!isset($this->tasks[$id])) {
            return false;
        }
        unset($this->tasks[$id]);
        return true;
    }
}

// ------------------------------------------------------------- routing ---

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$path   = rtrim((string) $path, '/') ?: '/';

$store = new TaskStore();
$store->create('Khởi động PHP');
$store->create('Học PHP 8 attributes', true);

if (preg_match('#^/tasks/(\d+)$#', $path, $m)) {
    $id = (int) $m[1];
    $task = $store->find($id);
    if ($task === null) {
        Response::json(['error' => 'task not found'], 404);
    }

    switch ($method) {
        case 'GET':
            Response::json($task);
            // no break needed with never-returning helper
        case 'PUT':
        case 'PATCH':
            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            Response::json($store->update($id, is_array($body) ? $body : []));
        case 'DELETE':
            $store->delete($id);
            Response::json(['deleted' => true], 200);
        default:
            Response::json(['error' => 'method not allowed'], 405);
    }
}

if ($path === '/tasks' && $method === 'GET') {
    Response::json(['count' => count($store->all()), 'items' => $store->all()]);
}

if ($path === '/tasks' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    $title = is_array($body) ? ($body['title'] ?? '') : '';
    if (!is_string($title) || trim($title) === '') {
        Response::json(['error' => 'title is required'], 400);
    }
    Response::json($store->create(trim($title)), 201);
}

if ($path === '/health') {
    Response::json(['status' => 'ok', 'php' => PHP_VERSION]);
}

Response::json(['error' => "no route for $method $path"], 404);
