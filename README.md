# Repalogic Group – Analytical Logic Challenge

## Senior Backend Engineer Technical Assessment

## 1. Project Overview

This project implements a lightweight financial settlement engine using native PHP.

The engine processes financial transactions from external vendors and evaluates them against account balances, currency conversion rules, VIP overdraft limits, transaction velocity restrictions, and vendor fraud detection rules.

The implementation uses object-oriented programming and maintains all application state in memory without an external database or third-party dependencies.

## 2. Technical Requirements

- PHP 8.2 or higher
- Native PHP only
- No frameworks
- No Composer packages
- No external database
- In-memory data management
- Native PHP exception handling and testing

## 3. Project Structure

```text
analytical-logic-challenge/
│
├── src/
│   ├── Exceptions/
│   │   ├── InsufficientFundsException.php
│   │   ├── CircuitBreakerException.php
│   │   └── ValidationException.php
│   │
│   ├── Account.php
│   ├── SettlementEngine.php
│   └── Transaction.php
│
├── README.md
└── run_tests.php
```

## 4. Architecture

### Account.php

Represents a customer account.

Responsibilities:

- Store account ID.
- Maintain account base currency.
- Maintain current account balance.
- Identify account tier (STANDARD or VIP).
- Provide controlled access to account properties.

### Transaction.php

Represents an incoming financial transaction.

Responsibilities:

- Store vendor ID.
- Store account ID.
- Store transaction amount.
- Store transaction currency.
- Store transaction timestamp in milliseconds.
- Identify transaction type (TOP-UP or DEDUCTION).
- Validate transaction input.

### SettlementEngine.php

The central business logic component.

Responsibilities:

- Register accounts.
- Process individual transactions.
- Convert transaction currencies.
- Validate account balances.
- Enforce VIP overdraft restrictions.
- Track deduction velocity.
- Enforce chronological transaction processing per account.
- Monitor consecutive vendor failures.
- Activate vendor circuit breakers.
- Maintain transaction audit ledger.
- Process atomic transaction batches.
- Restore application state when a batch fails.

### Custom Exceptions

**InsufficientFundsException**

Thrown when a transaction exceeds the available balance or permitted overdraft.

**CircuitBreakerException**

Thrown when a transaction is received from a vendor whose circuit breaker is already open.

**ValidationException**

Thrown when transaction data, account references, exchange rates, or transaction timestamp ordering are invalid.

## 5. Business Logic Implementation

### 5.1 Multi-Currency Conversion

Transactions are converted into the account's base currency using a predefined exchange-rate matrix.

Example:

- Account base currency: USD
- Transaction currency: INR
- Transaction amount: 4500 INR
- Exchange rate: 1 USD = 90 INR

Converted amount: 50 USD.

The ledger records:

- Original transaction amount.
- Original transaction currency.
- Applied exchange rate.
- Converted base currency impact.
- Transaction type.
- Transaction timestamp.
- Balance after processing.

### 5.2 Account Balance Integrity

Standard accounts cannot have negative balances.

VIP accounts can use an overdraft facility of up to 500 units in their base currency.

Transactions exceeding the permitted overdraft are rejected.

### 5.3 VIP Velocity Protection

VIP accounts are allowed a maximum of three deductions within a rolling 60-second window while overdraft protection is active.

When the deduction count exceeds three:

- Overdraft protection is permanently revoked for that account during the engine session.
- Any subsequent transaction that would result in a negative balance is rejected.

### 5.4 Vendor Fraud Circuit Breaker

The engine tracks consecutive rejected transactions separately for each vendor.

After three consecutive invalid or rejected transactions:

- The vendor's circuit breaker is activated.
- The activation event is logged when the threshold is reached.
- All subsequent transactions from that vendor are blocked.
- A CircuitBreakerException is thrown for blocked transactions.

A successful transaction resets the consecutive failure counter.

Only expected transaction/business failures (insufficient funds and validation failures) count toward the vendor failure threshold. Unexpected programming errors are not counted as vendor failures.

Once activated, the circuit breaker remains open during the engine session.

### 5.5 Atomic Batch Processing

Multiple transactions can be processed as one atomic batch.

Before processing begins, the engine captures an in-memory snapshot of its current state.

If any transaction fails:

- Account balances are restored.
- Transaction ledger entries are restored.
- Deduction history is restored.
- Last processed account timestamps are restored.
- Vendor failure counters are restored.
- Circuit breaker states are restored.
- Overdraft revocation states are restored.
- Circuit breaker logs are restored.

This ensures that either all transactions in the batch succeed or none are committed.

## 6. Test Scenarios

The test suite covers the four mandatory assessment scenarios and six additional edge-case scenarios.

| Scenario | Description |
|---|---|
| 1 | Standard currency conversion and ledger tracking |
| 2 | VIP overdraft and velocity protection |
| 3 | Vendor fraud circuit breaker |
| 4 | Atomic batch rollback |
| 5 | Same-currency conversion |
| 6 | Missing exchange rate validation |
| 7 | VIP overdraft limit validation |
| 8 | Vendor failure counter reset |
| 9 | Batch rollback restores vendor failure state |
| 10 | Batch rollback restores VIP velocity history |

## 7. Running the Application

No installation or dependency setup is required.

Make sure PHP is available in your terminal.

Check the PHP version:

```bash
php -v
```

Execute the test suite from the project root:

```bash
php run_tests.php
```

## 8. Expected Test Output

The test script prints individual PASS or FAIL messages for each assertion.

A successful execution ends with:

```text
=====================================
ALL TESTS COMPLETED SUCCESSFULLY
=====================================
```

## 9. Design Decisions

- Used object-oriented programming to separate account, transaction, and settlement responsibilities.
- Used encapsulation to protect account properties.
- Used custom exceptions to distinguish business failures.
- Used associative arrays and objects for in-memory state management.
- Used an exchange-rate matrix for currency conversion.
- Used snapshots to support atomic batch rollback.
- Used a vendor-level failure counter to implement circuit breaker protection.
- Used native PHP test helpers to validate business behavior without external testing frameworks.
- Required transactions for each account to be processed in non-decreasing timestamp order so rolling-window velocity checks remain deterministic.

## 10. Assumptions

- Exchange rates are supplied to the engine during initialization.
- Exchange rates are represented as source-currency-to-target-currency conversion factors. For example, INR to USD is `1 / 90` when 1 USD equals 90 INR.
- All timestamps are provided in milliseconds.
- Transactions for an individual account are processed in non-decreasing timestamp order. Out-of-order transactions are rejected.
- The VIP overdraft limit is 500 units in the account's base currency, as specified by the assessment's USD examples.
- Circuit breaker states and account state exist for the duration of the engine execution.
- A successful vendor transaction resets its consecutive failure count.
- Failed batch processing restores the engine state to its pre-batch snapshot.
- Monetary calculations use PHP floating-point values rounded to two decimal places for this assessment implementation.
