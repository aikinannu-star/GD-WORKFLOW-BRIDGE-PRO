Migration: import existing `data/users.json` into PostgreSQL

This project now supports using a Postgres-backed user store for the license server. If you have existing demo users stored in `license-server/data/users.json`, follow these steps to import them into Postgres.

1. Configure database DSN and credentials (example):

- Export a PDO DSN and optional user/password for the license server. Example (bash):

```bash
export LICENSE_DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=gdwb_app"
export LICENSE_DB_USER=postgres
export LICENSE_DB_PASSWORD=yourpassword
```

2. Run the migration script (PHP):

```bash
php license-server/migrate_users_to_db.php
```

This will create a `users` table (if missing) and insert users found in `data/users.json`. Existing emails are skipped.

3. Enable DB-backed auth in the license server by setting `LICENSE_DB_DSN` in your environment (see above). The server will automatically use the DB when configured; otherwise it will continue to use the JSON file.

Notes:
- Password hashes from the JSON file (bcrypt from PHP's `password_hash`) are preserved.
- After migration, the license server's `auth.php` will use the Postgres store when `LICENSE_DB_DSN` is set.
- If you want to migrate users into a different DB (for example the main application DB), adapt the DSN and run the same script against that DB.
