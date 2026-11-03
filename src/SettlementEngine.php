<?php

declare(strict_types=1);

require_once __DIR__ . '/Account.php';
require_once __DIR__ . '/Transaction.php';

require_once __DIR__ . '/Exceptions/InsufficientFundsException.php';
require_once __DIR__ . '/Exceptions/CircuitBreakerException.php';
require_once __DIR__ . '/Exceptions/ValidationException.php';

class SettlementEngine
{
    private const OVERDRAFT_LIMIT = 500.00;
    private const MAX_DEDUCTIONS = 3;
    private const VELOCITY_WINDOW_MS = 60000;
    private const VENDOR_FAILURE_THRESHOLD = 3;

    /** @var array<string, Account> */
    private array $accounts = [];

    /** @var array<string, array<string, float>> */
    private array $exchangeRates = [];

    /** @var array<int, array<string, mixed>> */
    private array $ledger = [];

    /** @var array<string, array<int, int>> */
    private array $deductionHistory = [];

    /** @var array<string, int> */
    private array $lastAccountTimestamp = [];

    /** @var array<string, int> */
    private array $vendorFailures = [];

    /** @var array<string, bool> */
    private array $circuitBreakers = [];

    /** @var array<int, array<string, mixed>> */
    private array $circuitBreakerLog = [];

    /** @var array<string, bool> */
    private array $overdraftRevoked = [];

    public function __construct(array $exchangeRates = [])
    {
        $this->exchangeRates = $exchangeRates;
    }

    /**
     * Register an account.
     */
    public function addAccount(Account $account): void
    {
        $id = $account->getAccountId();

        if (isset($this->accounts[$id])) {
            throw new ValidationException("Account {$id} already exists.");
        }

        $this->accounts[$id] = $account;
    }

    /**
     * Process one transaction.
     */
    public function processTransaction(Transaction $transaction): array
    {
        $vendorId = $transaction->getVendorId();

        if ($this->isCircuitBreakerOpen($vendorId)) {
            throw new CircuitBreakerException(
                "Vendor {$vendorId} is blocked by circuit breaker."
            );
        }

        try {
            $result = $this->executeTransaction($transaction);

            // A successful transaction resets consecutive vendor failures.
            $this->vendorFailures[$vendorId] = 0;

            return $result;
        } catch (InsufficientFundsException | ValidationException $exception) {
            // Only expected transaction/business failures count against a vendor.
            $this->recordVendorFailure(
                $vendorId,
                $transaction->getTimestampMs()
            );

            throw $exception;
        }
    }

    /**
     * Execute the actual transaction logic.
     */
    private function executeTransaction(Transaction $transaction): array
    {
        $accountId = $transaction->getAccountId();

        if (!isset($this->accounts[$accountId])) {
            throw new ValidationException("Account {$accountId} does not exist.");
        }

        $account = $this->accounts[$accountId];
        $timestamp = $transaction->getTimestampMs();

        // This in-memory engine expects events for each account in timestamp order.
        if (
            isset($this->lastAccountTimestamp[$accountId]) &&
            $timestamp < $this->lastAccountTimestamp[$accountId]
        ) {
            throw new ValidationException(
                "Transactions for account {$accountId} must be processed in timestamp order."
            );
        }

        $rate = $this->getExchangeRate(
            $transaction->getCurrency(),
            $account->getBaseCurrency()
        );

        $convertedAmount = round(
            $transaction->getAmount() * $rate,
            2
        );

        if ($convertedAmount <= 0 || !is_finite($convertedAmount)) {
            throw new ValidationException('Invalid converted transaction amount.');
        }

        $impact = $transaction->isDeduction()
            ? -$convertedAmount
            : $convertedAmount;

        $newBalance = round($account->getBalance() + $impact, 2);

        // Check VIP velocity before validating the resulting balance.
        if ($transaction->isDeduction()) {
            $this->checkVelocity($account, $transaction);
        }

        $this->validateBalance($account, $newBalance);

        // Commit the transaction only after all validations pass.
        $account->setBalance($newBalance);
        $this->lastAccountTimestamp[$accountId] = $timestamp;

        $ledgerEntry = [
            'vendor_id' => $transaction->getVendorId(),
            'account_id' => $accountId,
            'original_amount' => $transaction->getAmount(),
            'original_currency' => $transaction->getCurrency(),
            'exchange_rate' => $rate,
            'base_currency' => $account->getBaseCurrency(),
            'base_impact' => $impact,
            'transaction_type' => $transaction->getTransactionType(),
            'timestamp_ms' => $timestamp,
            'balance_after' => $newBalance,
        ];

        $this->ledger[] = $ledgerEntry;

        return [
            'status' => 'SUCCESS',
            'account_id' => $accountId,
            'balance' => $newBalance,
            'ledger' => $ledgerEntry,
        ];
    }

    /**
     * Retrieve the exchange rate.
     */
    private function getExchangeRate(
        string $fromCurrency,
        string $toCurrency
    ): float {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        if (
            !isset($this->exchangeRates[$fromCurrency]) ||
            !isset($this->exchangeRates[$fromCurrency][$toCurrency])
        ) {
            throw new ValidationException(
                "Exchange rate not found: {$fromCurrency} to {$toCurrency}."
            );
        }

        $rate = $this->exchangeRates[$fromCurrency][$toCurrency];

        if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0) {
            throw new ValidationException('Invalid exchange rate.');
        }

        return (float) $rate;
    }

    /**
     * Check deductions within a rolling 60-second window.
     */
    private function checkVelocity(
        Account $account,
        Transaction $transaction
    ): void {
        if (!$account->isVip()) {
            return;
        }

        $accountId = $account->getAccountId();
        $currentTimestamp = $transaction->getTimestampMs();

        $history = $this->deductionHistory[$accountId] ?? [];

        // Keep only earlier deductions that are still inside the rolling window.
        $history = array_values(array_filter(
            $history,
            static function (int $timestamp) use ($currentTimestamp): bool {
                return $timestamp <= $currentTimestamp
                    && ($currentTimestamp - $timestamp) < self::VELOCITY_WINDOW_MS;
            }
        ));

        $history[] = $currentTimestamp;
        $this->deductionHistory[$accountId] = $history;

        if (count($history) > self::MAX_DEDUCTIONS) {
            $this->overdraftRevoked[$accountId] = true;
        }
    }

    /**
     * Validate the resulting account balance.
     */
    private function validateBalance(Account $account, float $newBalance): void
    {
        $accountId = $account->getAccountId();

        if (!$account->isVip() && $newBalance < 0) {
            throw new InsufficientFundsException(
                "Insufficient funds in account {$accountId}."
            );
        }

        if (
            $account->isVip() &&
            ($this->overdraftRevoked[$accountId] ?? false) &&
            $newBalance < 0
        ) {
            throw new InsufficientFundsException(
                "Overdraft protection revoked for account {$accountId}."
            );
        }

        if ($account->isVip() && $newBalance < -self::OVERDRAFT_LIMIT) {
            throw new InsufficientFundsException(
                "VIP overdraft limit exceeded for account {$accountId}."
            );
        }
    }

    /**
     * Track consecutive vendor failures and open the breaker at the threshold.
     */
    private function recordVendorFailure(
        string $vendorId,
        int $timestampMs
    ): void {
        $this->vendorFailures[$vendorId] =
            ($this->vendorFailures[$vendorId] ?? 0) + 1;

        if (
            $this->vendorFailures[$vendorId] >= self::VENDOR_FAILURE_THRESHOLD &&
            !$this->isCircuitBreakerOpen($vendorId)
        ) {
            $this->circuitBreakers[$vendorId] = true;

            // Log the activation event once, when the threshold is reached.
            $this->circuitBreakerLog[] = [
                'vendor_id' => $vendorId,
                'timestamp_ms' => $timestampMs,
                'failure_count' => $this->vendorFailures[$vendorId],
                'message' => 'Circuit breaker activated after consecutive failures.',
            ];
        }
    }

    /**
     * Check whether a vendor circuit breaker is open.
     */
    private function isCircuitBreakerOpen(string $vendorId): bool
    {
        return $this->circuitBreakers[$vendorId] ?? false;
    }

    /**
     * Process multiple transactions atomically.
     */
    public function processBatch(array $transactions): array
    {
        $snapshot = $this->createSnapshot();
        $results = [];

        try {
            foreach ($transactions as $transaction) {
                if (!$transaction instanceof Transaction) {
                    throw new ValidationException(
                        'Batch contains an invalid transaction.'
                    );
                }

                $results[] = $this->processTransaction($transaction);
            }

            return $results;
        } catch (Throwable $exception) {
            $this->restoreSnapshot($snapshot);
            throw $exception;
        }
    }

    /**
     * Create an in-memory snapshot.
     */
    private function createSnapshot(): array
    {
        $balances = [];

        foreach ($this->accounts as $id => $account) {
            $balances[$id] = $account->getBalance();
        }

        return [
            'balances' => $balances,
            'ledger' => $this->ledger,
            'deduction_history' => $this->deductionHistory,
            'last_account_timestamp' => $this->lastAccountTimestamp,
            'vendor_failures' => $this->vendorFailures,
            'circuit_breakers' => $this->circuitBreakers,
            'circuit_breaker_log' => $this->circuitBreakerLog,
            'overdraft_revoked' => $this->overdraftRevoked,
        ];
    }

    /**
     * Restore the previous engine state.
     */
    private function restoreSnapshot(array $snapshot): void
    {
        foreach ($snapshot['balances'] as $id => $balance) {
            $this->accounts[$id]->setBalance($balance);
        }

        $this->ledger = $snapshot['ledger'];
        $this->deductionHistory = $snapshot['deduction_history'];
        $this->lastAccountTimestamp = $snapshot['last_account_timestamp'];
        $this->vendorFailures = $snapshot['vendor_failures'];
        $this->circuitBreakers = $snapshot['circuit_breakers'];
        $this->circuitBreakerLog = $snapshot['circuit_breaker_log'];
        $this->overdraftRevoked = $snapshot['overdraft_revoked'];
    }

    /**
     * Get current account balance.
     */
    public function getBalance(string $accountId): float
    {
        if (!isset($this->accounts[$accountId])) {
            throw new ValidationException("Account {$accountId} does not exist.");
        }

        return $this->accounts[$accountId]->getBalance();
    }

    /**
     * Get successful transaction ledger.
     */
    public function getLedger(): array
    {
        return $this->ledger;
    }

    /**
     * Get circuit breaker activation logs.
     */
    public function getCircuitBreakerLog(): array
    {
        return $this->circuitBreakerLog;
    }

    /**
     * Check vendor circuit breaker status.
     */
    public function isVendorBlocked(string $vendorId): bool
    {
        return $this->isCircuitBreakerOpen($vendorId);
    }
}
