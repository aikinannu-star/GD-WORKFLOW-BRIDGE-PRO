#!/usr/bin/env node
/**
 * Intelligence Learning Validator
 * 
 * Validates all learning API endpoints and contracts
 */

const http = require('http');

const LEARNING_ENDPOINTS = [
  '/api/v1/intelligence-learning/performance',
  '/api/v1/intelligence-learning/adoption-gaps',
  '/api/v1/intelligence-learning/recurring-issues',
  '/api/v1/intelligence-learning/trends',
  '/api/v1/intelligence-learning/effectiveness-score',
  '/api/v1/intelligence-learning',
];

class LearningValidator {
  constructor() {
    this.results = [];
    this.timestamp = new Date().toISOString();
  }

  async run() {
    console.log('\n╔════════════════════════════════════════════════════════════════╗');
    console.log('║    Intelligence Learning API Validation                         ║');
    console.log('╚════════════════════════════════════════════════════════════════╝\n');

    console.log('🧪 Testing all learning endpoints...\n');

    for (const endpoint of LEARNING_ENDPOINTS) {
      await this.testEndpoint(endpoint);
    }

    this.printResults();
  }

  async testEndpoint(endpoint) {
    return new Promise((resolve) => {
      http.get(`http://127.0.0.1:8006${endpoint}`, (res) => {
        let data = '';
        res.on('data', chunk => { data += chunk; });
        res.on('end', () => {
          try {
            const parsed = JSON.parse(data);
            this.validateResponse(endpoint, parsed);
            resolve();
          } catch (e) {
            this.results.push({
              endpoint,
              status: 'FAIL',
              reason: `Invalid JSON: ${e.message}`,
            });
            resolve();
          }
        });
      }).on('error', (err) => {
        this.results.push({
          endpoint,
          status: 'FAIL',
          reason: `Connection error: ${err.message}`,
        });
        resolve();
      });
    });
  }

  validateResponse(endpoint, data) {
    const result = {
      endpoint,
      status: 'PASS',
    };

    // Check for error
    if (data.error) {
      result.status = 'FAIL';
      result.reason = `API error: ${data.error}`;
      this.results.push(result);
      return;
    }

    // Validate specific endpoints
    if (endpoint.includes('performance')) {
      this.validatePerformance(endpoint, data, result);
    } else if (endpoint.includes('adoption-gaps')) {
      this.validateAdoptionGaps(endpoint, data, result);
    } else if (endpoint.includes('recurring-issues')) {
      this.validateRecurringIssues(endpoint, data, result);
    } else if (endpoint.includes('trends')) {
      this.validateTrends(endpoint, data, result);
    } else if (endpoint.includes('effectiveness-score')) {
      this.validateEffectivenessScore(endpoint, data, result);
    } else if (endpoint === '/api/v1/intelligence-learning') {
      this.validateConsolidated(endpoint, data, result);
    }

    this.results.push(result);
  }

  validatePerformance(endpoint, data, result) {
    if (!data.recommendations) {
      result.status = 'FAIL';
      result.reason = 'Missing recommendations array';
      return;
    }

    if (!Array.isArray(data.recommendations)) {
      result.status = 'FAIL';
      result.reason = 'recommendations is not an array';
      return;
    }

    // Validate recommendation structure
    for (const rec of data.recommendations) {
      if (!rec.recommendation_type || rec.success_rate === undefined || rec.effectiveness_score === undefined) {
        result.status = 'FAIL';
        result.reason = 'Invalid recommendation structure';
        return;
      }

      // Validate ranges
      if (rec.success_rate < 0 || rec.success_rate > 1) {
        result.status = 'FAIL';
        result.reason = `success_rate out of range: ${rec.success_rate}`;
        return;
      }

      if (rec.effectiveness_score < 0 || rec.effectiveness_score > 100) {
        result.status = 'FAIL';
        result.reason = `effectiveness_score out of range: ${rec.effectiveness_score}`;
        return;
      }
    }

    result.detail = `${data.recommendations.length} recommendations ranked`;
  }

  validateAdoptionGaps(endpoint, data, result) {
    if (!data.gaps || !Array.isArray(data.gaps)) {
      result.status = 'FAIL';
      result.reason = 'Missing or invalid gaps array';
      return;
    }

    for (const gap of data.gaps) {
      if (gap.adoption_rate < 0 || gap.adoption_rate > 1) {
        result.status = 'FAIL';
        result.reason = `adoption_rate out of range: ${gap.adoption_rate}`;
        return;
      }

      if (!['critical', 'warning', 'advisory'].includes(gap.severity)) {
        result.status = 'FAIL';
        result.reason = `Invalid severity: ${gap.severity}`;
        return;
      }
    }

    result.detail = `${data.gaps.length} gaps identified`;
  }

  validateRecurringIssues(endpoint, data, result) {
    if (!data.issues || !Array.isArray(data.issues)) {
      result.status = 'FAIL';
      result.reason = 'Missing or invalid issues array';
      return;
    }

    for (const issue of data.issues) {
      if (!issue.issue || !issue.occurrence_count) {
        result.status = 'FAIL';
        result.reason = 'Missing required issue fields';
        return;
      }

      if (!['improving', 'degrading', 'stable'].includes(issue.trend)) {
        result.status = 'FAIL';
        result.reason = `Invalid trend: ${issue.trend}`;
        return;
      }
    }

    result.detail = `${data.issues.length} recurring issues detected`;
  }

  validateTrends(endpoint, data, result) {
    if (!data.periods || !data.trends) {
      result.status = 'FAIL';
      result.reason = 'Missing periods or trends';
      return;
    }

    const trendKeys = ['mttd_trend', 'mttr_trend', 'accuracy_trend', 'acceptance_trend'];
    for (const key of trendKeys) {
      if (!data.trends[key]) {
        result.status = 'FAIL';
        result.reason = `Missing trend: ${key}`;
        return;
      }

      if (!['improving', 'degrading', 'stable'].includes(data.trends[key])) {
        result.status = 'FAIL';
        result.reason = `Invalid ${key} value: ${data.trends[key]}`;
        return;
      }
    }

    result.detail = `Trends computed with overall direction: ${data.overall_direction}`;
  }

  validateEffectivenessScore(endpoint, data, result) {
    if (data.score === undefined || data.status === undefined) {
      result.status = 'FAIL';
      result.reason = 'Missing score or status';
      return;
    }

    if (data.score < 0 || data.score > 100) {
      result.status = 'FAIL';
      result.reason = `Score out of range: ${data.score}`;
      return;
    }

    if (!['excellent', 'healthy', 'warning', 'critical'].includes(data.status)) {
      result.status = 'FAIL';
      result.reason = `Invalid status: ${data.status}`;
      return;
    }

    if (!data.components) {
      result.status = 'FAIL';
      result.reason = 'Missing components';
      return;
    }

    // Validate component weights sum to 1.0
    const weightSum = Object.values(data.components).reduce((sum, comp) => sum + comp.weight, 0);
    if (Math.abs(weightSum - 1.0) > 0.01) {
      result.status = 'FAIL';
      result.reason = `Component weights don't sum to 1.0: ${weightSum}`;
      return;
    }

    result.detail = `Score: ${data.score.toFixed(1)}/100 (${data.status})`;
  }

  validateConsolidated(endpoint, data, result) {
    if (!data.performance || !data.adoption_gaps || !data.recurring_issues || !data.trends || !data.effectiveness_score) {
      result.status = 'FAIL';
      result.reason = 'Missing one or more sections in consolidated response';
      return;
    }

    result.detail = 'All 5 learning sections present';
  }

  printResults() {
    console.log('════════════════════════════════════════════════════════════════');
    console.log('Results');
    console.log('════════════════════════════════════════════════════════════════\n');

    const passed = this.results.filter(r => r.status === 'PASS');
    const failed = this.results.filter(r => r.status === 'FAIL');

    passed.forEach(r => {
      console.log(`✅ ${r.endpoint}`);
      if (r.detail) console.log(`   ${r.detail}`);
    });

    if (failed.length > 0) {
      console.log('\n❌ FAILURES:\n');
      failed.forEach(r => {
        console.log(`❌ ${r.endpoint}`);
        console.log(`   ${r.reason}`);
      });
    }

    console.log('\n════════════════════════════════════════════════════════════════');
    console.log(`✅ ${passed.length} passed`);
    if (failed.length > 0) {
      console.log(`❌ ${failed.length} failed`);
    }
    console.log('════════════════════════════════════════════════════════════════\n');

    process.exit(failed.length > 0 ? 1 : 0);
  }
}

const validator = new LearningValidator();
validator.run().catch(e => {
  console.error('Fatal error:', e);
  process.exit(2);
});
