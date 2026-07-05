<?php
/**
 * Risk Zones API Endpoint
 * 
 * Serves centralized risk zone configuration as JSON
 * Ensures all components consume the same threshold definitions
 * 
 * Usage:
 *   GET /api/v1/risk-zones
 *   
 * Response:
 *   {
 *     "healthy": { ... },
 *     "watch": { ... },
 *     "stagnant": { ... },
 *     "critical": { ... },
 *     "degrading": { ... }
 *   }
 * 
 * @package GD\Workflow\API
 */

namespace GD\Workflow\API;

use GD\Workflow\Config;

/**
 * Risk Zones Endpoint Handler
 * 
 * Provides centralized risk zone definitions to frontend components
 * This prevents threshold drift and ensures consistent classification
 */
class RiskZonesEndpoint
{
    /**
     * Handle GET /api/v1/risk-zones request
     * 
     * @return void (outputs JSON and exits)
     */
    public static function handle(): void
    {
        // Set content type
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');  // Cache for 1 hour
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        
        try {
            // Get JSON export from Config
            $json = Config\exportRiskZonesAsJSON();
            
            // Output
            echo $json;
            http_response_code(200);
            
        } catch (\Exception $e) {
            // Error response
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            
            echo json_encode([
                'error' => 'Failed to retrieve risk zones',
                'message' => $e->getMessage()
            ]);
        }
        
        exit;
    }
    
    /**
     * Get risk zone for a tenant (used by rankings/filtering)
     * 
     * GET /api/v1/risk-zones/classify?health=85&volatility=25
     * 
     * @param float $health Health score
     * @param float $volatility Volatility score
     * @return array Risk zone information
     */
    public static function classifyTenant(float $health, float $volatility): array
    {
        return Config\formatZoneForUI($health, $volatility);
    }
    
    /**
     * Get zone by ID (for drift engine lookups)
     * 
     * GET /api/v1/risk-zones/{zone_id}
     * 
     * @param string $zone_id Zone identifier
     * @return array|null Zone configuration
     */
    public static function getZoneById(string $zone_id): ?array
    {
        return Config\getRiskZoneById($zone_id);
    }
}

// Integration point: Add this to your marketplace server router
/*
 * In services/marketplace/server.php:
 * 
 * if ($method === 'GET' && $path === '/api/v1/risk-zones') {
 *     require_once __DIR__ . '/../../Config/RiskZones.php';
 *     require_once __DIR__ . '/api/RiskZonesEndpoint.php';
 *     RiskZonesEndpoint::handle();
 * }
 * 
 * if ($method === 'GET' && preg_match('/^\/api\/v1\/risk-zones\/classify$/', $path)) {
 *     require_once __DIR__ . '/../../Config/RiskZones.php';
 *     require_once __DIR__ . '/api/RiskZonesEndpoint.php';
 *     
 *     $health = $_GET['health'] ?? 50;
 *     $volatility = $_GET['volatility'] ?? 50;
 *     
 *     $result = RiskZonesEndpoint::classifyTenant((float)$health, (float)$volatility);
 *     
 *     header('Content-Type: application/json; charset=utf-8');
 *     echo json_encode($result);
 *     exit;
 * }
 */
