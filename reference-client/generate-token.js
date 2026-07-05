#!/usr/bin/env node
/**
 * Generate a valid JWT token for local testing
 * Uses the same secret as services/auth/server.php
 */

import crypto from 'crypto';

// Use the same development secret as local auth service or override via environment
const JWT_SECRET = process.env.AUTH_JWT_SECRET ?? 'dev_jwt_secret';

function base64url(buffer) {
  return buffer
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=/g, '');
}

function jwtEncode(payload) {
  const header = {
    alg: 'HS256',
    typ: 'JWT'
  };

  const headerB64 = base64url(Buffer.from(JSON.stringify(header)));
  const payloadB64 = base64url(Buffer.from(JSON.stringify(payload)));
  
  const signature = crypto
    .createHmac('sha256', JWT_SECRET)
    .update(headerB64 + '.' + payloadB64)
    .digest();
  
  const signatureB64 = base64url(signature);
  
  return headerB64 + '.' + payloadB64 + '.' + signatureB64;
}

// Generate token for ci@example.com / ci-tenant
const payload = {
  iss: 'gdwb-auth-service',
  sub: 'c5b458679eee2d9bc474ad099ff6e024',
  tenant_id: 'ci-tenant',
  email: 'ci@example.com',
  role: 'admin',
  permissions: ['read', 'write', 'deploy'],
  iat: Math.floor(Date.now() / 1000),
  exp: Math.floor(Date.now() / 1000) + 3600 // 1 hour
};

const token = jwtEncode(payload);
console.log(token);
