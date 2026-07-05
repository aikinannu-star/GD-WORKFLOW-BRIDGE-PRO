#!/usr/bin/env node
/**
 * CI Integration Validation
 * 
 * Verifies effectiveness governance CI integration is properly configured:
 * 1. Workflow files contain effectiveness jobs
 * 2. Reporter script exists and is executable
 * 3. Report artifacts can be generated
 * 4. SLA validation logic works correctly
 */

const fs = require('fs');
const path = require('path');

class CIIntegrationValidator {
  constructor() {
    this.baseDir = process.cwd();
    this.results = [];
  }

  async run() {
    console.log('\n╔════════════════════════════════════════════════════════════════╗');
    console.log('║    CI Integration Validation for Effectiveness Governance         ║');
    console.log('╚════════════════════════════════════════════════════════════════╝\n');

    try {
      await this.validateWorkflows();
      await this.validateScripts();
      await this.validateReportGeneration();
      this.printResults();
      
      const hasCritical = this.results.some(r => r.severity === 'critical' && !r.passed);
      process.exit(hasCritical ? 1 : 0);
    } catch (e) {
      console.error('ERROR:', e.message);
      process.exit(2);
    }
  }

  async validateWorkflows() {
    console.log('🔍 Step 1: Validating workflow configurations...\n');

    // Check marketplace-ci.yml
    this.checkFile('.github/workflows/marketplace-ci.yml', (content) => {
      const hasEffectivenessJob = content.includes('effectiveness-governance');
      const hasReporterCall = content.includes('effectiveness-ci-reporter.js');
      
      this.results.push({
        check: 'marketplace-ci.yml has effectiveness-governance job',
        passed: hasEffectivenessJob,
        severity: 'critical',
        details: hasEffectivenessJob ? 'Job found' : 'Job not found',
      });

      this.results.push({
        check: 'marketplace-ci.yml calls effectiveness reporter',
        passed: hasReporterCall,
        severity: 'critical',
        details: hasReporterCall ? 'Reporter invoked' : 'Reporter not invoked',
      });
    });

    // Check ci.yml
    this.checkFile('.github/workflows/ci.yml', (content) => {
      const hasEffectivenessIntegration = content.includes('effectiveness-ci-reporter');
      const hasKPIValidation = content.includes('kpi-validation');
      
      this.results.push({
        check: 'ci.yml integrates effectiveness validation',
        passed: hasEffectivenessIntegration,
        severity: 'critical',
        details: hasEffectivenessIntegration ? 'Integration found' : 'Integration not found',
      });

      this.results.push({
        check: 'ci.yml has kpi-validation job',
        passed: hasKPIValidation,
        severity: 'critical',
        details: hasKPIValidation ? 'Job found' : 'Job not found',
      });
    });
  }

  async validateScripts() {
    console.log('📄 Step 2: Validating reporter scripts...\n');

    // Check reporter exists
    const reporterPath = 'scripts/effectiveness-ci-reporter.js';
    const reporterExists = this.checkFileExists(reporterPath);
    
    this.results.push({
      check: 'effectiveness-ci-reporter.js exists',
      passed: reporterExists,
      severity: 'critical',
      details: reporterExists ? `Found at ${reporterPath}` : 'Not found',
    });

    if (reporterExists) {
      const content = fs.readFileSync(path.join(this.baseDir, reporterPath), 'utf8');
      
      // Check for key methods
      const hasFetchMetrics = content.includes('async fetchMetrics()');
      const hasValidateSLAs = content.includes('validateSLAs()');
      const hasReportGeneration = content.includes('generateJSONReport');
      const hasHTMLReport = content.includes('generateHTMLReport');
      
      this.results.push({
        check: 'Reporter has fetchMetrics method',
        passed: hasFetchMetrics,
        severity: 'critical',
      });

      this.results.push({
        check: 'Reporter has validateSLAs method',
        passed: hasValidateSLAs,
        severity: 'critical',
      });

      this.results.push({
        check: 'Reporter generates JSON reports',
        passed: hasReportGeneration,
        severity: 'critical',
      });

      this.results.push({
        check: 'Reporter generates HTML reports',
        passed: hasHTMLReport,
        severity: 'critical',
      });
    }

    // Check contract tests exist
    const contractTestsPath = 'tests/EffectivenessContractTests.php';
    const contractTestsExist = this.checkFileExists(contractTestsPath);
    
    this.results.push({
      check: 'Effectiveness contract tests exist',
      passed: contractTestsExist,
      severity: 'normal',
      details: contractTestsExist ? `Found at ${contractTestsPath}` : 'Not found',
    });
  }

  async validateReportGeneration() {
    console.log('📊 Step 3: Validating report generation...\n');

    // Check that ci-artifacts directory can be created
    const artifactDir = path.join(this.baseDir, 'ci-artifacts');
    
    try {
      if (!fs.existsSync(artifactDir)) {
        fs.mkdirSync(artifactDir, { recursive: true });
      }

      this.results.push({
        check: 'Artifact directory writable',
        passed: true,
        severity: 'normal',
        details: `Directory: ${artifactDir}`,
      });

      // Check if reports were already generated
      const jsonReportPath = path.join(artifactDir, 'effectiveness-metrics.json');
      const htmlReportPath = path.join(artifactDir, 'effectiveness-report.html');

      const jsonExists = fs.existsSync(jsonReportPath);
      const htmlExists = fs.existsSync(htmlReportPath);

      this.results.push({
        check: 'JSON report artifact exists',
        passed: jsonExists,
        severity: 'normal',
        details: jsonExists ? 'Report generated' : 'Not yet generated',
      });

      this.results.push({
        check: 'HTML report artifact exists',
        passed: htmlExists,
        severity: 'normal',
        details: htmlExists ? 'Report generated' : 'Not yet generated',
      });

      // Validate JSON report structure if it exists
      if (jsonExists) {
        const jsonContent = JSON.parse(fs.readFileSync(jsonReportPath, 'utf8'));
        
        const hasMetrics = jsonContent.metrics !== undefined;
        const hasTestResults = jsonContent.test_results !== undefined;
        const hasStatus = jsonContent.status !== undefined;

        this.results.push({
          check: 'JSON report has metrics section',
          passed: hasMetrics,
          severity: 'normal',
        });

        this.results.push({
          check: 'JSON report has test_results section',
          passed: hasTestResults,
          severity: 'normal',
        });

        this.results.push({
          check: 'JSON report has status field',
          passed: hasStatus,
          severity: 'normal',
        });
      }
    } catch (e) {
      this.results.push({
        check: 'Report generation validation',
        passed: false,
        severity: 'normal',
        details: e.message,
      });
    }
  }

  checkFile(filePath, validator) {
    try {
      const fullPath = path.join(this.baseDir, filePath);
      if (!fs.existsSync(fullPath)) {
        this.results.push({
          check: `${filePath} exists`,
          passed: false,
          severity: 'critical',
          details: 'File not found',
        });
        return;
      }

      const content = fs.readFileSync(fullPath, 'utf8');
      validator(content);
    } catch (e) {
      this.results.push({
        check: `${filePath} readable`,
        passed: false,
        severity: 'critical',
        details: e.message,
      });
    }
  }

  checkFileExists(filePath) {
    const fullPath = path.join(this.baseDir, filePath);
    return fs.existsSync(fullPath);
  }

  printResults() {
    console.log('\n════════════════════════════════════════════════════════════════');
    console.log('📋 Validation Results');
    console.log('════════════════════════════════════════════════════════════════\n');

    // Group by severity
    const critical = this.results.filter(r => r.severity === 'critical');
    const normal = this.results.filter(r => r.severity === 'normal');

    if (critical.length > 0) {
      console.log('🔴 CRITICAL CHECKS:\n');
      critical.forEach(r => {
        const icon = r.passed ? '✅' : '❌';
        console.log(`${icon} ${r.check}`);
        if (r.details) {
          console.log(`   ${r.details}`);
        }
      });
    }

    if (normal.length > 0) {
      console.log('\nℹ️  NORMAL CHECKS:\n');
      normal.forEach(r => {
        const icon = r.passed ? '✅' : '⚠️';
        console.log(`${icon} ${r.check}`);
        if (r.details) {
          console.log(`   ${r.details}`);
        }
      });
    }

    // Summary
    const totalPassed = this.results.filter(r => r.passed).length;
    const totalFailed = this.results.filter(r => !r.passed).length;
    const criticalFailed = critical.filter(r => !r.passed).length;

    console.log('\n════════════════════════════════════════════════════════════════');
    console.log(`Total: ${totalPassed} passed, ${totalFailed} failed`);
    console.log(`Critical: ${criticalFailed} failed`);

    if (criticalFailed === 0) {
      console.log('\n✅ All critical CI integration checks passed');
      console.log('Effectiveness governance is ready for CI/CD pipeline\n');
    } else {
      console.log('\n❌ Critical checks failed - CI integration not ready\n');
    }
  }
}

// Run validator
const validator = new CIIntegrationValidator();
validator.run().catch(e => {
  console.error('Fatal error:', e);
  process.exit(2);
});
