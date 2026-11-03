<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Account.php';
require_once __DIR__ . '/src/Transaction.php';
require_once __DIR__ . '/src/SettlementEngine.php';

/*
|--------------------------------------------------------------------------
| Test Helper Functions
|--------------------------------------------------------------------------
*/

function assertEqual($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "[FAIL] {$message}" .
            " | Expected: " . var_export($expected, true) .
            " | Actual: " . var_export($actual, true)
        );
    }

    echo "[PASS] {$message}\n";
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("[FAIL] {$message}");
    }

    echo "[PASS] {$message}\n";
}

function assertThrows(
    string $exceptionClass,
    callable $callback,
    string $message
): void {
    try {
        $callback();
    } catch (Throwable $exception) {

        if (!$exception instanceof $exceptionClass) {
            throw new RuntimeException(
                "[FAIL] {$message}. Expected {$exceptionClass}, got " .
                get_class($exception),
                0,
                $exception
            );
        }

        echo "[PASS] {$message}\n";
        return;
    }

    throw new RuntimeException(
        "[FAIL] {$message}. Expected exception was not thrown."
    );
}

/*
|--------------------------------------------------------------------------
| Test Execution
|--------------------------------------------------------------------------
*/

echo "\n=====================================\n";
echo "REPALOGIC ANALYTICAL LOGIC CHALLENGE\n";
echo "=====================================\n\n";

try {

/*
|--------------------------------------------------------------------------
| SCENARIO 1: Standard Currency Conversion & Ledger Tracking
|--------------------------------------------------------------------------
*/

echo "SCENARIO 1: Currency Conversion\n";

$engine = new SettlementEngine([
    'INR' => [
        'USD' => 1 / 90
    ]
]);

$engine->addAccount(
    new Account('A', 'USD', 100.00, 'STANDARD')
);

$transaction = new Transaction(
    'Vendor-A',
    'A',
    4500.00,
    'INR',
    1000,
    'DEDUCTION'
);

$engine->processTransaction($transaction);

assertEqual(
    50.00,
    $engine->getBalance('A'),
    '4500 INR converted to 50 USD and deducted from balance'
);

$ledger = $engine->getLedger();

assertEqual(
    4500.00,
    $ledger[0]['original_amount'],
    'Ledger stores original transaction amount'
);

assertEqual(
    'INR',
    $ledger[0]['original_currency'],
    'Ledger stores original currency'
);

assertEqual(
    round(1 / 90, 10),
    round($ledger[0]['exchange_rate'], 10),
    'Ledger stores exchange rate'
);

assertEqual(
    -50.00,
    $ledger[0]['base_impact'],
    'Ledger stores converted base currency impact'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 2: VIP Overdraft & Velocity Trap
|--------------------------------------------------------------------------
*/

echo "SCENARIO 2: VIP Velocity Trap\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('B', 'USD', 0.00, 'VIP')
);

$transactions = [
    new Transaction('Vendor-B', 'B', 100, 'USD', 1000, 'DEDUCTION'),
    new Transaction('Vendor-B', 'B', 100, 'USD', 5000, 'DEDUCTION'),
    new Transaction('Vendor-B', 'B', 100, 'USD', 12000, 'DEDUCTION')
];

foreach ($transactions as $index => $transaction) {

    $engine->processTransaction($transaction);

    echo "[PASS] VIP deduction " . ($index + 1) . " accepted\n";
}

assertEqual(
    -300.00,
    $engine->getBalance('B'),
    'VIP balance reaches -300 USD after three deductions'
);

assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction(
                'Vendor-B',
                'B',
                50,
                'USD',
                20000,
                'DEDUCTION'
            )
        );
    },
    'Fourth deduction rejected after velocity threshold'
);

assertEqual(
    -300.00,
    $engine->getBalance('B'),
    'Balance unchanged after rejected fourth deduction'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 3: Vendor Fraud Circuit Breaker
|--------------------------------------------------------------------------
*/

echo "SCENARIO 3: Vendor Circuit Breaker\n";

$engine = new SettlementEngine();

foreach (['C', 'D', 'E', 'F'] as $accountId) {
    $engine->addAccount(
        new Account($accountId, 'USD', 10.00)
    );
}

$failedTransactions = [
    new Transaction('Vendor-X', 'C', 50, 'USD', 1000, 'DEDUCTION'),
    new Transaction('Vendor-X', 'D', 20, 'USD', 2000, 'DEDUCTION'),
    new Transaction('Vendor-X', 'E', 15, 'USD', 3000, 'DEDUCTION')
];

foreach ($failedTransactions as $index => $transaction) {

    assertThrows(
        InsufficientFundsException::class,
        function () use ($engine, $transaction) {
            $engine->processTransaction($transaction);
        },
        'Vendor X rejected transaction ' . ($index + 1)
    );
}

assertTrue(
    $engine->isVendorBlocked('Vendor-X'),
    'Circuit breaker opens after three consecutive failures'
);

assertThrows(
    CircuitBreakerException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction(
                'Vendor-X',
                'F',
                2,
                'USD',
                4000,
                'DEDUCTION'
            )
        );
    },
    'Subsequent transaction blocked by circuit breaker'
);

assertEqual(
    10.00,
    $engine->getBalance('F'),
    'Blocked transaction does not modify Account F balance'
);

assertTrue(
    count($engine->getCircuitBreakerLog()) === 1,
    'Circuit breaker rejection is logged'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 4: Atomic Batch Rollback
|--------------------------------------------------------------------------
*/

echo "SCENARIO 4: Atomic Batch Rollback\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('G', 'USD', 100.00)
);

$batch = [
    new Transaction('Vendor-G', 'G', 40, 'USD', 1000, 'DEDUCTION'),
    new Transaction('Vendor-G', 'G', 50, 'USD', 2000, 'DEDUCTION'),
    new Transaction('Vendor-G', 'G', 30, 'USD', 3000, 'DEDUCTION')
];

assertThrows(
    InsufficientFundsException::class,
    function () use ($engine, $batch) {
        $engine->processBatch($batch);
    },
    'Batch rejected due to insufficient funds'
);

assertEqual(
    100.00,
    $engine->getBalance('G'),
    'Account G balance restored after batch rollback'
);

assertEqual(
    0,
    count($engine->getLedger()),
    'All batch ledger entries removed after rollback'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 5: Same Currency Conversion
|--------------------------------------------------------------------------
*/

echo "SCENARIO 5: Same Currency Conversion\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('H', 'USD', 100.00)
);

$engine->processTransaction(
    new Transaction('Vendor-H', 'H', 25, 'USD', 1000, 'DEDUCTION')
);

assertEqual(
    75.00,
    $engine->getBalance('H'),
    'Same currency transaction uses exchange rate 1'
);

assertEqual(
    1.0,
    $engine->getLedger()[0]['exchange_rate'],
    'Same currency ledger records exchange rate 1'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 6: Missing Exchange Rate
|--------------------------------------------------------------------------
*/

echo "SCENARIO 6: Missing Exchange Rate\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('I', 'USD', 100.00)
);

assertThrows(
    ValidationException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-I', 'I', 100, 'EUR', 1000, 'DEDUCTION')
        );
    },
    'Transaction rejected when exchange rate is missing'
);

assertEqual(
    100.00,
    $engine->getBalance('I'),
    'Balance unchanged after missing exchange rate'
);

assertEqual(
    0,
    count($engine->getLedger()),
    'Invalid currency transaction is not recorded in ledger'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 7: VIP Overdraft Limit
|--------------------------------------------------------------------------
*/

echo "SCENARIO 7: VIP Overdraft Limit\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('J', 'USD', 0.00, 'VIP')
);

$engine->processTransaction(
    new Transaction('Vendor-J', 'J', 500, 'USD', 1000, 'DEDUCTION')
);

assertEqual(
    -500.00,
    $engine->getBalance('J'),
    'VIP account can use the full 500 USD overdraft limit'
);

assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-J', 'J', 1, 'USD', 2000, 'DEDUCTION')
        );
    },
    'VIP transaction exceeding 500 USD overdraft is rejected'
);

assertEqual(
    -500.00,
    $engine->getBalance('J'),
    'VIP balance unchanged after overdraft limit rejection'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 8: Vendor Failure Counter Reset
|--------------------------------------------------------------------------
*/

echo "SCENARIO 8: Vendor Failure Counter Reset\n";

$engine = new SettlementEngine();

$engine->addAccount(new Account('K', 'USD', 0.00));
$engine->addAccount(new Account('L', 'USD', 100.00));

// First failure.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-Z', 'K', 10, 'USD', 1000, 'DEDUCTION')
        );
    },
    'First vendor failure recorded'
);

// Successful transaction resets failure counter.
$engine->processTransaction(
    new Transaction('Vendor-Z', 'L', 10, 'USD', 2000, 'DEDUCTION')
);

assertEqual(
    90.00,
    $engine->getBalance('L'),
    'Successful vendor transaction processed'
);

// Two failures should not trip the circuit breaker.
for ($i = 1; $i <= 2; $i++) {

    assertThrows(
        InsufficientFundsException::class,
        function () use ($engine, $i) {
            $engine->processTransaction(
                new Transaction(
                    'Vendor-Z',
                    'K',
                    10,
                    'USD',
                    3000 + ($i * 1000),
                    'DEDUCTION'
                )
            );
        },
        "Vendor failure {$i} after successful transaction"
    );

    assertTrue(
        !$engine->isVendorBlocked('Vendor-Z'),
        "Circuit breaker remains closed after {$i} consecutive failures"
    );
}

// Third consecutive failure trips the circuit breaker.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-Z', 'K', 10, 'USD', 6000, 'DEDUCTION')
        );
    },
    'Third consecutive failure after reset'
);

assertTrue(
    $engine->isVendorBlocked('Vendor-Z'),
    'Circuit breaker opens after three consecutive failures'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 9: Batch Rollback Restores Vendor Failure State
|--------------------------------------------------------------------------
*/

echo "SCENARIO 9: Batch Rollback - Vendor State\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('M', 'USD', 0.00)
);

// Record first vendor failure.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-R', 'M', 10, 'USD', 1000, 'DEDUCTION')
        );
    },
    'Initial vendor failure recorded'
);

// Second failure occurs inside a batch and must be rolled back.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processBatch([
            new Transaction('Vendor-R', 'M', 10, 'USD', 2000, 'DEDUCTION')
        ]);
    },
    'Failed batch is rolled back'
);

// Failure count must still be one.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-R', 'M', 10, 'USD', 3000, 'DEDUCTION')
        );
    },
    'Vendor failure count restored after batch rollback'
);

assertTrue(
    !$engine->isVendorBlocked('Vendor-R'),
    'Batch rollback does not incorrectly trip circuit breaker'
);

// Third consecutive failure trips the circuit breaker.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processTransaction(
            new Transaction('Vendor-R', 'M', 10, 'USD', 4000, 'DEDUCTION')
        );
    },
    'Third consecutive failure trips circuit breaker'
);

assertTrue(
    $engine->isVendorBlocked('Vendor-R'),
    'Circuit breaker remains open after rollback test'
);

echo "\n";

/*
|--------------------------------------------------------------------------
| SCENARIO 10: Batch Rollback Restores VIP Velocity History
|--------------------------------------------------------------------------
*/

echo "SCENARIO 10: Batch Rollback - VIP Velocity\n";

$engine = new SettlementEngine();

$engine->addAccount(
    new Account('N', 'USD', 0.00, 'VIP')
);

// First two deductions.
$engine->processTransaction(
    new Transaction('Vendor-N', 'N', 100, 'USD', 1000, 'DEDUCTION')
);

$engine->processTransaction(
    new Transaction('Vendor-N', 'N', 100, 'USD', 5000, 'DEDUCTION')
);

// Third succeeds inside batch; fourth fails.
// The entire batch must roll back.
assertThrows(
    InsufficientFundsException::class,
    function () use ($engine) {
        $engine->processBatch([
            new Transaction('Vendor-N', 'N', 100, 'USD', 12000, 'DEDUCTION'),
            new Transaction('Vendor-N', 'N', 50, 'USD', 13000, 'DEDUCTION')
        ]);
    },
    'VIP batch rejected after velocity threshold'
);

assertEqual(
    -200.00,
    $engine->getBalance('N'),
    'VIP balance restored after batch rollback'
);

assertEqual(
    2,
    count($engine->getLedger()),
    'Only two transactions remain in ledger after batch rollback'
);

// If velocity history was restored, this is the third deduction.
$engine->processTransaction(
    new Transaction('Vendor-N', 'N', 100, 'USD', 14000, 'DEDUCTION')
);

assertEqual(
    -300.00,
    $engine->getBalance('N'),
    'VIP deduction history restored after batch rollback'
);

echo "\n";

} catch (Throwable $exception) {

    echo "\n=====================================\n";
    echo "TEST SUITE FAILED\n";
    echo "=====================================\n";

    echo get_class($exception) . ': ';
    echo $exception->getMessage() . "\n";

    exit(1);
}

/*
|--------------------------------------------------------------------------
| Final Result
|--------------------------------------------------------------------------
*/

echo "=====================================\n";
echo "ALL TESTS COMPLETED SUCCESSFULLY\n";
echo "=====================================\n";
