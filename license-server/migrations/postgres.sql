-- Postgres schema for license-server
-- Run with: psql -h $HOST -U $USER -d $DB -f migrations/postgres.sql

CREATE TABLE IF NOT EXISTS licenses (
  license_key TEXT PRIMARY KEY,
  status TEXT NOT NULL DEFAULT 'active',
  features JSONB DEFAULT '[]'::jsonb,
  meta JSONB DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ DEFAULT now(),
  activated_at TIMESTAMPTZ,
  expires_at TIMESTAMPTZ,
  revoked_at TIMESTAMPTZ,
  activation_count INT DEFAULT 0,
  max_activations INT DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_licenses_expires_at ON licenses (expires_at);
CREATE INDEX IF NOT EXISTS idx_licenses_status ON licenses (status);

-- Audit table for activations and events
CREATE TABLE IF NOT EXISTS license_activations (
  id BIGSERIAL PRIMARY KEY,
  license_key TEXT NOT NULL,
  site TEXT,
  ip TEXT,
  user_agent TEXT,
  event TEXT NOT NULL,
  created_at TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_activation_license_key ON license_activations (license_key);

-- Audit table for JWKS/admin events
CREATE TABLE IF NOT EXISTS jwks_audit (
  id BIGSERIAL PRIMARY KEY,
  action TEXT NOT NULL,
  actor JSONB,
  ip TEXT,
  user_agent TEXT,
  payload JSONB,
  created_at TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_jwks_audit_action ON jwks_audit (action);

-- Admin JWT revocations
CREATE TABLE IF NOT EXISTS admin_jwt_revocations (
  id BIGSERIAL PRIMARY KEY,
  jti TEXT NOT NULL,
  reason TEXT,
  actor JSONB,
  expires_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_admin_jwt_revocations_jti ON admin_jwt_revocations (jti);

-- Billing events table used by billing EventStore (for webhook idempotency and retry)
CREATE TABLE IF NOT EXISTS billing_events (
  event_key TEXT PRIMARY KEY,
  provider TEXT,
  event_id TEXT,
  reference TEXT,
  license_key TEXT,
  metadata JSONB DEFAULT '{}'::jsonb,
  raw JSONB DEFAULT '{}'::jsonb,
  status TEXT,
  attempts INTEGER DEFAULT 0,
  created_at TIMESTAMPTZ DEFAULT now(),
  last_attempt_at TIMESTAMPTZ,
  processed_at TIMESTAMPTZ,
  next_retry_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_billing_events_status ON billing_events (status);
CREATE INDEX IF NOT EXISTS idx_billing_events_next_retry ON billing_events (next_retry_at);
