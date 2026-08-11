-- ============================================================
--  REGISTER USERS — idempotent user seeds
--  Adds the owner/admin account for the Registrar System.
--  Safe to re-run: INSERT ... SELECT ... WHERE NOT EXISTS.
--  Apply with:  mysql -u root registrar_ai < register_users.sql
-- ============================================================

USE registrar_ai;

-- Owner account: roldantiu89@gmail.com / roldan123 / role admin
-- bcrypt hash computed with password_hash('roldan123', PASSWORD_DEFAULT)
INSERT INTO `users` (`email`, `password_hash`, `full_name`, `role`, `is_active`, `created_at`, `updated_at`)
SELECT 'roldantiu89@gmail.com',
       '$2y$10$fKjYMZE8/dbUTFxnKO1yNOmydGkWvxV0nJTDlfWjGWqb8emY9lOrK',
       'Roldan Tiu',
       'admin',
       1,
       CURRENT_TIMESTAMP,
       CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1 FROM `users` WHERE `email` = 'roldantiu89@gmail.com'
);
