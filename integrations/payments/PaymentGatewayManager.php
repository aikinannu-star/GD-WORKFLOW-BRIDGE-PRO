<?php
/**
 * Payment Gateway Manager
 * Handles dynamic payment provider selection and fallback
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';
require_once __DIR__ . '/StripePaymentGateway.php';
require_once __DIR__ . '/PaystackPaymentGateway.php';
require_once __DIR__ . '/RazorpayPaymentGateway.php';
require_once __DIR__ . '/PayPalPaymentGateway.php';
require_once __DIR__ . '/FlutterWavePaymentGateway.php';

class PaymentGatewayManager
{
    private static $gateways = [];
    private static $config = [];
    private $activeGateway;
    private $fallbackChain = [];

    public function __construct(array $config = [])
    {
        self::$config = $config;
    }

    /**
     * Register a payment gateway
     */
    public static function registerGateway(string $provider, string $className): void
    {
        self::$gateways[$provider] = $className;
    }

    /**
     * Get a payment gateway instance
     */
    public static function getGateway(string $provider): PaymentGatewayInterface
    {
        if (!isset(self::$gateways[$provider])) {
            throw new Exception("Payment provider '$provider' not registered");
        }
        
        $className = self::$gateways[$provider];
        $instance = new $className();
        
        if (isset(self::$config[$provider])) {
            $instance->authenticate(self::$config[$provider]);
        }
        
        return $instance;
    }

    /**
     * Set the active gateway
     */
    public function setActiveGateway(string $provider): void
    {
        $this->activeGateway = self::getGateway($provider);
    }

    /**
     * Set fallback gateway chain
     */
    public function setFallbackChain(array $providers): void
    {
        $this->fallbackChain = $providers;
    }

    /**
     * Get active gateway
     */
    public function getActive(): PaymentGatewayInterface
    {
        if (!$this->activeGateway) {
            throw new Exception('No active payment gateway set');
        }
        return $this->activeGateway;
    }

    /**
     * Select gateway based on customer location/currency
     */
    public function selectByLocation(string $country, string $currency = 'USD'): PaymentGatewayInterface
    {
        $providerMap = [
            'NG' => 'paystack',  // Nigeria
            'GH' => 'paystack',  // Ghana
            'ZA' => 'paystack',  // South Africa
            'KE' => 'paystack',  // Kenya
            'IN' => 'razorpay',  // India
            'BD' => 'razorpay',  // Bangladesh
            'PK' => 'razorpay',  // Pakistan
            'LK' => 'razorpay',  // Sri Lanka
            'US' => 'stripe',    // USA
            'CA' => 'stripe',    // Canada
            'GB' => 'stripe',    // UK
            'AU' => 'stripe',    // Australia
            'DE' => 'stripe',    // Germany
            'FR' => 'stripe',    // France
        ];
        
        $provider = $providerMap[strtoupper($country)] ?? 'stripe';
        $this->setActiveGateway($provider);
        
        return $this->getActive();
    }

    /**
     * Select gateway based on supported currencies
     */
    public function selectByCurrency(string $currency): PaymentGatewayInterface
    {
        foreach (array_keys(self::$gateways) as $provider) {
            try {
                $gateway = self::getGateway($provider);
                if (in_array(strtoupper($currency), $gateway->getSupportedCurrencies())) {
                    $this->setActiveGateway($provider);
                    return $this->getActive();
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        throw new Exception("No gateway supports currency: $currency");
    }

    /**
     * Execute operation with fallback support
     */
    public function executeWithFallback(callable $operation, string $primaryProvider = null): array
    {
        $providers = [];
        
        if ($primaryProvider) {
            $providers[] = $primaryProvider;
        }
        
        if ($this->activeGateway) {
            $providers[] = $this->activeGateway->getProvider();
        }
        
        $providers = array_merge($providers, $this->fallbackChain);
        $providers = array_unique($providers);
        
        $lastError = null;
        
        foreach ($providers as $provider) {
            try {
                $this->setActiveGateway($provider);
                $result = $operation($this->getActive());
                $result['gateway_used'] = $provider;
                return $result;
            } catch (Exception $e) {
                $lastError = $e;
                continue;
            }
        }
        
        throw new Exception("All payment gateways failed: " . ($lastError ? $lastError->getMessage() : 'Unknown error'));
    }

    /**
     * Create customer with preferred provider
     */
    public function createCustomer(array $data, string $preferredProvider = null): array
    {
        return $this->executeWithFallback(
            fn($gateway) => $gateway->createCustomer($data),
            $preferredProvider
        );
    }

    /**
     * Create subscription
     */
    public function createSubscription(string $customerId, string $planId, array $metadata = [], string $provider = null): array
    {
        return $this->executeWithFallback(
            fn($gateway) => $gateway->createSubscription($customerId, $planId, $metadata),
            $provider
        );
    }

    /**
     * Charge customer
     */
    public function charge(string $customerId, int $amount, string $currency = 'USD', array $metadata = [], string $provider = null): array
    {
        return $this->executeWithFallback(
            fn($gateway) => $gateway->charge($customerId, $amount, $currency, $metadata),
            $provider
        );
    }

    /**
     * Get all registered providers
     */
    public static function getRegisteredProviders(): array
    {
        return array_keys(self::$gateways);
    }

    /**
     * Get provider capabilities
     */
    public function getCapabilities(string $provider): array
    {
        $gateway = self::getGateway($provider);
        
        return [
            'provider' => $gateway->getProvider(),
            'supported_currencies' => $gateway->getSupportedCurrencies(),
            'features' => [
                'subscriptions' => true,
                'one_time_charges' => true,
                'refunds' => true,
                'webhooks' => true,
            ],
        ];
    }

    /**
     * Get all capabilities
     */
    public function getAllCapabilities(): array
    {
        $capabilities = [];
        
        foreach (self::getRegisteredProviders() as $provider) {
            $capabilities[$provider] = $this->getCapabilities($provider);
        }
        
        return $capabilities;
    }
}

// Register default gateways
PaymentGatewayManager::registerGateway('stripe', 'StripePaymentGateway');
PaymentGatewayManager::registerGateway('paystack', 'PaystackPaymentGateway');
PaymentGatewayManager::registerGateway('razorpay', 'RazorpayPaymentGateway');
PaymentGatewayManager::registerGateway('paypal', 'PayPalPaymentGateway');
PaymentGatewayManager::registerGateway('flutterwave', 'FlutterWavePaymentGateway');
