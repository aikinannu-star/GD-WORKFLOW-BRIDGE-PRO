<?php
/**
 * Payment Gateway Interface
 * Defines the contract for all payment providers
 */

interface PaymentGatewayInterface
{
    /**
     * Authenticate with the payment provider
     * @param array $config Provider configuration (API keys, etc)
     * @return bool Success
     */
    public function authenticate(array $config): bool;

    /**
     * Create a customer
     * @param array $data Customer information
     * @return array Customer ID and provider reference
     */
    public function createCustomer(array $data): array;

    /**
     * Create a subscription
     * @param string $customerId Customer ID in payment provider
     * @param string $planId Plan/price ID in payment provider
     * @param array $metadata Additional metadata
     * @return array Subscription details
     */
    public function createSubscription(string $customerId, string $planId, array $metadata = []): array;

    /**
     * Get subscription details
     * @param string $subscriptionId Subscription ID
     * @return array Subscription data
     */
    public function getSubscription(string $subscriptionId): array;

    /**
     * Update subscription
     * @param string $subscriptionId Subscription ID
     * @param array $updates Fields to update (plan, metadata, etc)
     * @return array Updated subscription
     */
    public function updateSubscription(string $subscriptionId, array $updates): array;

    /**
     * Cancel subscription
     * @param string $subscriptionId Subscription ID
     * @param bool $immediate Cancel immediately or at period end
     * @return array Cancellation result
     */
    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array;

    /**
     * Create one-time payment/charge
     * @param string $customerId Customer ID
     * @param int $amount Amount in cents
     * @param string $currency Currency code
     * @param array $metadata Additional data
     * @return array Payment details
     */
    public function charge(string $customerId, int $amount, string $currency = 'USD', array $metadata = []): array;

    /**
     * Create a refund
     * @param string $chargeId Charge/payment ID
     * @param int|null $amount Amount to refund (null = full refund)
     * @return array Refund result
     */
    public function refund(string $chargeId, ?int $amount = null): array;

    /**
     * Get invoice details
     * @param string $invoiceId Invoice ID
     * @return array Invoice data
     */
    public function getInvoice(string $invoiceId): array;

    /**
     * List invoices for customer
     * @param string $customerId Customer ID
     * @param int $limit Results limit
     * @return array List of invoices
     */
    public function listInvoices(string $customerId, int $limit = 10): array;

    /**
     * Verify webhook signature
     * @param string $payload Raw request body
     * @param string $signature Signature header
     * @return bool Valid signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Process webhook event
     * @param array $event Parsed webhook event
     * @return array Processing result
     */
    public function processWebhookEvent(array $event): array;

    /**
     * Get provider name
     * @return string Provider identifier
     */
    public function getProvider(): string;

    /**
     * Get supported currencies
     * @return array List of currency codes
     */
    public function getSupportedCurrencies(): array;
}
