<?php
declare(strict_types=1);

class Transaction
{
    private string $vendorId;
    private string $accountId;
    private float $amount;
    private string $currency;
    private int $timestampMs;
    private string $transactionType;

    public function __construct(
        string $vendorId,
        string $accountId,
        float $amount,
        string $currency,
        int $timestampMs,
        string $transactionType
    ) {
        if (trim($vendorId) === '') {
            throw new InvalidArgumentException('Vendor ID cannot be empty.');
        }

        if (trim($accountId) === '') {
            throw new InvalidArgumentException('Account ID cannot be empty.');
        }

        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        if (trim($currency) === '') {
            throw new InvalidArgumentException('Currency cannot be empty.');
        }

        if ($timestampMs < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative.');
        }

        $transactionType = strtoupper(trim($transactionType));

        if (!in_array($transactionType, ['TOP-UP', 'DEDUCTION'], true)) {
            throw new InvalidArgumentException('Invalid transaction type.');
        }

        $this->vendorId = $vendorId;
        $this->accountId = $accountId;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->timestampMs = $timestampMs;
        $this->transactionType = $transactionType;
    }

    public function getVendorId(): string
    {
        return $this->vendorId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getTimestampMs(): int
    {
        return $this->timestampMs;
    }

    public function getTransactionType(): string
    {
        return $this->transactionType;
    }

    public function isDeduction(): bool
    {
        return $this->transactionType === 'DEDUCTION';
    }

    public function isTopUp(): bool
    {
        return $this->transactionType === 'TOP-UP';
    }
}
