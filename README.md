# php-microapi

A tiny JSON REST API in **one PHP file** — no framework, no Composer.

## Run
```bash
php -S localhost:8080 public/index.php
curl localhost:8080/tasks
curl -X POST localhost:8080/tasks -d '{"title":"Việc mới"}'
curl -X PUT localhost:8080/tasks/1 -d '{"done":true}'
curl -X DELETE localhost:8080/tasks/1
```

## Endpoints
| Method | Path        | Description     |
|--------|-------------|-----------------|
| GET    | /health     | Health check    |
| GET    | /tasks      | List tasks      |
| POST   | /tasks      | Create task     |
| GET/PUT/DELETE | /tasks/:id | Read / update / delete |
