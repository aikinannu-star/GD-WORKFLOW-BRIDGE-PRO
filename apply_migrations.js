const { Pool } = require("pg");
(async () => {
  const pool = new Pool({
    host: process.env.DB_HOST || "127.0.0.1",
    port: process.env.DB_PORT ? parseInt(process.env.DB_PORT,10) : 5432,
    user: process.env.DB_USER || "gdwb_user",
    password: process.env.DB_PASSWORD || "password",
    database: process.env.DB_NAME || "gdwb_app",
  });
  await pool.query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified BOOLEAN DEFAULT false;");
  await pool.query("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ;");
  console.log("Migration applied");
  await pool.end();
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
