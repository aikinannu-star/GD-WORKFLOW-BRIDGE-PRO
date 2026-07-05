import { config } from '../../config.js';
import { createSdk, SdkError } from '../../sdk.js';

async function main() {
  const sdk = createSdk({
    basePath: config.apiBaseUrl,
    accessToken: config.apiToken,
    timeout: config.requestTimeoutMs,
  });

  console.log('Running intelligence learning workflow');

  const score = await sdk.intelligence.effectivenessScore();
  console.log('Effectiveness score:', score);

  const adoptionGaps = await sdk.intelligence.adoptionGaps();
  console.log('Adoption gaps:', adoptionGaps);

  const recurringIssues = await sdk.intelligence.recurringIssues();
  console.log('Recurring issues:', recurringIssues);

  const trends = await sdk.intelligence.trends();
  console.log('Learning trends:', trends);

  console.log('Intelligence learning workflow complete');
}

main().catch((error) => {
  if (error instanceof SdkError) {
    console.error('SDK error:', error.message, { status: error.status, code: error.code, requestId: error.requestId });
  } else {
    console.error('Unexpected error:', error);
  }
  process.exit(1);
});
