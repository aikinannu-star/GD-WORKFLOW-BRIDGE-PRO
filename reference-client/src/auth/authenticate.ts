import { ApiClient } from '@gd-workflow-bridge-pro/api-sdk';
import { config } from '../config.js';

export function authenticateClient() {
  return new ApiClient({
    basePath: config.apiBaseUrl,
    accessToken: config.apiToken,
    timeout: config.requestTimeoutMs,
  });
}
