---
tags: [architecture]
---

# 🗄️ `shared/database.php`

PDO wrapper singleton — the app's database access layer.

## API

| Method | Purpose |
|---|---|
| `getInstance()` | singleton |
| `query($sql, $params)` | prepare + execute |
| `fetchAll` / `fetchOne` / `fetchColumn` | read shorthand |
| `insert($table, $data)` | keyed insert → lastInsertId |
| `update($table, $data, $where, $params)` | keyed update → rowCount |
| `delete($table, $where, $params)` | → rowCount |

## Hardening

- `PDO::ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES = false`
- Connection errors are logged, never echoed to the browser (generic 500 JSON)

## Related

- [[Database Layer]] · [[config.php]] · [[Database MOC]]
