---
tags: [table]
---

# 🗄️ `authorized_cards`

Staff cards authorized to operate scanning stations.

## Columns

| Column | Type | Notes |
|---|---|---|
| `id` | int PK, AI | |
| `card_uid` | varchar(20) **UNIQUE** | |
| `name` | varchar(100) | |
| `role` | enum `admin/registrar/superadmin` | default `registrar` |
| `can_change_station` | tinyint(1) | |
| `created_at` | timestamp | |

## Indexes

`PK(id)`, `UNIQUE(card_uid)`.

## Related

- [[Authorized Cards]] · [[RFID Access]] · [[rfid_cards]]
