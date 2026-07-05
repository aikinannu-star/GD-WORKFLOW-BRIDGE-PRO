import dotenv from 'dotenv';

dotenv.config();

export const config = {
  apiBaseUrl: process.env.API_BASE_URL ?? 'http://localhost:8006',
  authMethod: process.env.API_AUTH_METHOD ?? 'bearer',
  apiToken: process.env.API_TOKEN ?? '',
  tenantId: process.env.TENANT_ID ?? '',
  requestTimeoutMs: Number(process.env.REQUEST_TIMEOUT_MS ?? 30000)
};

if (!config.apiToken) {
  throw new Error('API_TOKEN is required in environment variables.');
}

if (!config.tenantId) {
  throw new Error('TENANT_ID is required in environment variables.');
}
