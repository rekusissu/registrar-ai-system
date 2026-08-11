---
tags: [architecture]
---

# 🗄️ Database Layer

`shared/database.php` — a thin PDO wrapper (singleton).

## API

| Method | Purpose |
|---|---|
| `getInstance()` | singleton access |
| `query($sql, $params)` | prepare + execute, returns PDOStatement |
| `fetchAll` / `fetchOne` / `fetchColumn` | shorthand reads |
| `insert($table, $data)` | keyed insert → lastInsertId |
| `update($table, $data, $where, $params)` | keyed update → rowCount |
| `delete($table, $where, $params)` | → rowCount |
| `getConnection()` | raw PDO if needed |

## Hardening

- `PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_EMULATE_PREPARES = false` (real prepared statements)
- Never echoes the connection error to the browser — logs it, returns a generic 500 JSON

## Usage pattern

```php
require_once __DIR__ . '/../shared/database.php';
$db = Database::getInstance();
$rows = $db->fetchAll("SELECT ... WHERE x = ?", [$x]);
```

## Related

- [[config.php]] · [[Database MOC]] · [[Architecture MOC]]
