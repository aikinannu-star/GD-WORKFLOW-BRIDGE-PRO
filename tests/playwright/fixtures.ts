/**
 * Test Scenario Fixtures for Playwright
 * Provides helper functions to set up test data
 */

import { APIRequestContext } from '@playwright/test';

export class TestScenarioManager {
  private baseURL: string;
  private request: APIRequestContext;

  constructor(baseURL: string, request: APIRequestContext) {
    this.baseURL = baseURL;
    this.request = request;
  }

  /**
   * Set up a test scenario via API
   */
  async setupScenario(scenarioName: string): Promise<any> {
    const response = await this.request.post(`${this.baseURL}/api/v1/marketplace/test/scenario`, {
      data: { scenario: scenarioName },
    });

    if (!response.ok()) {
      throw new Error(`Failed to set up scenario: ${response.status()}`);
    }

    return await response.json();
  }

  /**
   * Reset to default test data
   */
  async resetDefaults(): Promise<any> {
    return this.setupScenario('reset');
  }

  /**
   * Verify platform health calculation matches expected value
   */
  async verifyHealthCalculation(scenario: string, expectedHealth: number, tolerance: number = 2): Promise<boolean> {
    // Setup scenario
    await this.setupScenario(scenario);

    // Fetch dashboard
    const response = await this.request.get(`${this.baseURL}/api/v1/marketplace/platform/dashboard`);
    const dashboard = await response.json();

    return Math.abs(dashboard.health_score - expectedHealth) <= tolerance;
  }

  /**
   * Verify at-risk count matches expected value
   */
  async verifyAtRiskCount(scenario: string, expectedCount: number): Promise<boolean> {
    await this.setupScenario(scenario);

    const response = await this.request.get(`${this.baseURL}/api/v1/marketplace/platform/dashboard`);
    const dashboard = await response.json();

    return dashboard.at_risk_count === expectedCount;
  }

  /**
   * Verify drift breakdown matches expected values
   */
  async verifyDriftBreakdown(
    scenario: string,
    expectedNone: number,
    expectedGovernance: number,
    expectedRevocation: number
  ): Promise<boolean> {
    await this.setupScenario(scenario);

    const response = await this.request.get(`${this.baseURL}/api/v1/marketplace/platform/drift-summary`);
    const drift = await response.json();

    return (
      drift.no_drift === expectedNone &&
      drift.governance_drift === expectedGovernance &&
      drift.revocation_drift === expectedRevocation
    );
  }

  /**
   * Get scenario test expectations
   */
  getScenarioExpectations(scenarioName: string): any {
    const scenarios: Record<string, any> = {
      healthy: {
        description: 'All tenants at 100% health, no drift',
        expectedHealth: 100,
        expectedAtRisk: 0,
        expectedDrift: { none: 5, governance: 0, revocation: 0 },
        expectedTenants: 5,
      },
      degraded: {
        description: 'Mix of healthy and at-risk tenants',
        expectedHealth: 75, // Approximate based on weighted calculation
        expectedAtRisk: 1, // One tenant at 45
        expectedDrift: { none: 3, governance: 1, revocation: 1 },
        expectedTenants: 5,
      },
      drift: {
        description: 'Various drift statuses',
        expectedHealth: 91, // Approximate
        expectedAtRisk: 0,
        expectedDrift: { none: 2, governance: 2, revocation: 1 },
        expectedTenants: 5,
      },
      weighted: {
        description: 'Test weighted health calculation',
        expectedHealth: 73, // (60*5 + 100*1 + 80*2) / 8 = 72.5
        expectedAtRisk: 1,
        expectedDrift: { none: 3, governance: 0, revocation: 0 },
        expectedTenants: 3,
      },
      improved: {
        description: 'Test most improved rankings',
        expectedHealth: 86, // (90 + 85 + 100 + 70) / 4
        expectedAtRisk: 0,
        expectedDrift: { none: 3, governance: 1, revocation: 0 },
        expectedTenants: 4,
      },
      risk: {
        description: 'Test highest risk rankings',
        expectedHealth: 58, // (25 + 50 + 65 + 95) / 4
        expectedAtRisk: 3, // 25, 50, 65 < 60
        expectedDrift: { none: 2, governance: 1, revocation: 1 },
        expectedTenants: 4,
      },
    };

    return scenarios[scenarioName] || null;
  }
}

/**
 * Helper to create test fixtures with scenarios
 */
export const testScenarios = {
  healthy: 'healthy_fleet',
  degraded: 'degraded_fleet',
  drift: 'drift_scenario',
  weighted: 'weighted_health_scenario',
  improved: 'improved_tenants_scenario',
  risk: 'risk_scenario',
};
