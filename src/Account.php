<?php
declare(strict_types=1);

class Account
{
    private string $accountId;
    private string $baseCurrency;
    private string $tier;
    private float $balance;

    public function __construct(string $accountId,string $baseCurrency,float $balance,$tier = 'STANDARD') 
    {
        if ($accountId === '') {
            throw new InvalidArgumentException('Account ID cannot be empty.');
        }

        if ($balance < 0 && $tier !== 'VIP') {
            throw new InvalidArgumentException(
                'Standard accounts cannot start with a negative balance.'
            );
        }

        if (!in_array($tier, ['STANDARD', 'VIP'], true)) {
            throw new InvalidArgumentException('Invalid account tier.');
        }

        $this->accountId = $accountId;
        $this->baseCurrency = strtoupper($baseCurrency);
        $this->balance = $balance;
        $this->tier = $tier;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function getBalance(): float
    {
        return $this->balance;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setBalance(float $balance): void
    {
        $this->balance = round($balance, 2);
    }

    public function isVip(): bool
    {
        return $this->tier === 'VIP';
    }
}
