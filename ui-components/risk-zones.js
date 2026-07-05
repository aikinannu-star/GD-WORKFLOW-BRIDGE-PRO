// Risk Zone Configuration - Frontend Usage Guide
// 
// This file demonstrates how to import and use the centralized risk zone configuration
// from Config/RiskZones.php across all frontend components
//
// Endpoint: GET /api/v1/risk-zones
// Returns: JSON-encoded risk zone definitions from Config/RiskZones.php

/**
 * Risk Zones Configuration
 * Imported from centralized PHP config to prevent threshold drift
 * 
 * Usage in UI components:
 * - health-volatility-matrix.html
 * - tenant-trend-timeline.html (Phase 2)
 * - drift-analysis-grid.html (Phase 3)
 * - operations-center (Phase 1+)
 */
const RISK_ZONES = {
  healthy: {
    id: 'healthy',
    name: 'Healthy',
    health_min: 75,
    health_max: 100,
    volatility_min: 0,
    volatility_max: 30,
    color: '#10b981',        // Green
    light_color: '#d1fae5',
    icon: '🟢',
    description: 'High health, stable performance',
    remediation_priority: 4,
    status: 'monitor',
    action: 'Continue monitoring'
  },
  
  watch: {
    id: 'watch',
    name: 'Watch',
    health_min: 75,
    health_max: 100,
    volatility_min: 30,
    volatility_max: 100,
    color: '#f59e0b',        // Amber
    light_color: '#fef3c7',
    icon: '🟠',
    description: 'Good health, but increasing volatility',
    remediation_priority: 3,
    status: 'observe',
    action: 'Stabilize and investigate volatility source'
  },
  
  stagnant: {
    id: 'stagnant',
    name: 'Stagnant',
    health_min: 50,
    health_max: 75,
    volatility_min: 0,
    volatility_max: 100,
    color: '#6366f1',        // Indigo
    light_color: '#e0e7ff',
    icon: '🔵',
    description: 'Moderate health, optimization needed',
    remediation_priority: 2,
    status: 'improve',
    action: 'Review performance metrics and optimize'
  },
  
  critical: {
    id: 'critical',
    name: 'Critical',
    health_min: 0,
    health_max: 50,
    volatility_min: 0,
    volatility_max: 30,
    color: '#ef4444',        // Red
    light_color: '#fee2e2',
    icon: '🔴',
    description: 'Low health, but stable degradation',
    remediation_priority: 1,
    status: 'critical',
    action: 'Investigate root cause immediately'
  },
  
  degrading: {
    id: 'degrading',
    name: 'Degrading',
    health_min: 0,
    health_max: 50,
    volatility_min: 30,
    volatility_max: 100,
    color: '#dc2626',        // Dark Red
    light_color: '#fecaca',
    icon: '🟥',
    description: 'Critical: Severe health decline and volatility',
    remediation_priority: 1,
    status: 'emergency',
    action: 'Emergency response required - escalate immediately'
  }
};

/**
 * Determine risk zone for a given tenant
 * 
 * Usage:
 *   const zone = getRiskZone(85, 25);  // health=85, volatility=25
 *   console.log(zone.name);            // "Healthy"
 *   console.log(zone.color);           // "#10b981"
 * 
 * @param {number} health_score - Health score (0-100)
 * @param {number} volatility - Volatility score (0-100)
 * @returns {Object} Risk zone configuration
 */
function normalizeScore(value) {
  let numberValue = Number(value);
  if (Number.isNaN(numberValue)) {
    return 0;
  }
  if (numberValue > 0 && numberValue <= 1) {
    return numberValue * 100;
  }
  return Math.max(0, Math.min(100, numberValue));
}

function getRiskZone(health_score, volatility) {
  const health = normalizeScore(health_score);
  const vol = normalizeScore(volatility);
  
  for (const [zone_id, zone] of Object.entries(RISK_ZONES)) {
    if (health >= zone.health_min && health <= zone.health_max &&
        vol >= zone.volatility_min && vol <= zone.volatility_max) {
      return zone;
    }
  }
  
  // Fallback
  return RISK_ZONES.critical;
}

/**
 * Get zone color by ID
 * 
 * Usage:
 *   const color = getZoneColor('healthy');  // "#10b981"
 * 
 * @param {string} zone_id - Risk zone identifier
 * @returns {string} Hex color code
 */
function getZoneColor(zone_id) {
  return RISK_ZONES[zone_id]?.color ?? '#cccccc';
}

/**
 * Get zone background color for SVG rendering
 * 
 * Usage:
 *   const bgColor = getZoneBgColor('watch');  // "#fef3c7"
 * 
 * @param {string} zone_id - Risk zone identifier
 * @returns {string} Hex color code (light variant)
 */
function getZoneBgColor(zone_id) {
  return RISK_ZONES[zone_id]?.light_color ?? '#f3f4f6';
}

/**
 * Format zone information for display
 * 
 * Usage:
 *   const display = formatZoneForDisplay(85, 25);
 *   console.log(`${display.icon} ${display.name}: ${display.action}`);
 * 
 * @param {number} health - Health score
 * @param {number} volatility - Volatility score
 * @returns {Object} Formatted zone with all display properties
 */
function formatZoneForDisplay(health, volatility) {
  const normalizedHealth = normalizeScore(health);
  const normalizedVolatility = normalizeScore(volatility);
  const zone = getRiskZone(normalizedHealth, normalizedVolatility);
  
  return {
    zone_id: zone.id,
    zone_name: zone.name,
    zone_icon: zone.icon,
    zone_color: zone.color,
    zone_light_color: zone.light_color,
    zone_description: zone.description,
    health_score: Math.round(normalizedHealth * 10) / 10,
    volatility_score: Math.round(normalizedVolatility * 10) / 10,
    status: zone.status,
    recommended_action: zone.action,
    remediation_priority: zone.remediation_priority
  };
}

/**
 * Fetch risk zones from centralized API endpoint
 * 
 * This keeps frontend in sync with backend configuration
 * 
 * Usage:
 *   const zones = await fetchRiskZonesFromAPI();
 *   window.RISK_ZONES = zones;  // Update with latest
 * 
 * @returns {Promise<Object>} Risk zones from server
 */
async function fetchRiskZonesFromAPI() {
  try {
    const response = await fetch('/api/v1/risk-zones');
    if (!response.ok) throw new Error('Failed to fetch risk zones');
    return await response.json();
  } catch (error) {
    console.warn('Failed to fetch risk zones from API, using bundled defaults:', error);
    return RISK_ZONES;  // Fallback to bundled config
  }
}

/**
 * Import risk zones configuration into a component
 * 
 * Usage in HTML:
 *   <script src="risk-zones.js"></script>
 *   <script>
 *     // Now use getRiskZone(), getZoneColor(), etc. in your component
 *     const zone = getRiskZone(health, volatility);
 *   </script>
 */

// ============================================
// INTEGRATION CHECKLIST
// ============================================

/*
 * To integrate centralized risk zones into a component:
 *
 * 1. BACKEND (PHP)
 *    - Import: require_once __DIR__ . '/../Config/RiskZones.php';
 *    - Use: \GD\Workflow\Config\getRiskZone($health, $volatility);
 *    - Export: \GD\Workflow\Config\exportRiskZonesAsJSON();
 *
 * 2. FRONTEND (JavaScript)
 *    - Import: <script src="risk-zones.js"></script>
 *    - Use: getRiskZone(health, volatility);
 *    - Update: await fetchRiskZonesFromAPI();
 *
 * 3. ANALYTICS ENGINE (services/marketplace/TimeSeriesHelper.php)
 *    - Import: use GD\Workflow\Config\getRiskZone;
 *    - Use: $zone = getRiskZone($health, $volatility);
 *
 * 4. DRIFT ENGINE (services/marketplace/DriftAnalyzer.php)
 *    - Import: use GD\Workflow\Config\getRiskZoneById;
 *    - Use: $zone_config = getRiskZoneById($zone_id);
 *
 * 5. OPERATIONS CENTER (services/marketplace/server.php)
 *    - Import: use GD\Workflow\Config\getAllRiskZones;
 *    - Use: $zones = getAllRiskZones();
 *
 * 6. TESTING
 *    - Unit: tests/unit/RiskZoneThresholdsTest.php
 *    - Integration: ui-tests/risk-zones.spec.js
 *    - Validation: tests/SyntheticScenarioHelper.php
 */

export {
  RISK_ZONES,
  getRiskZone,
  getZoneColor,
  getZoneBgColor,
  formatZoneForDisplay,
  fetchRiskZonesFromAPI
};
