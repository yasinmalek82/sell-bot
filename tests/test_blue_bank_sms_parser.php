<?php

require_once dirname(__DIR__) . '/lib/BlueBankSmsParser.php';

function assertBlueValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

$sample = <<<'SMS'
بلو
واریز پول
 یاسین عزیز، 1,800,000 ریال به حساب شما نشست.
 موجودی: 16,451,024 ریال
۱۰:۰۷
۱۴۰۵.۰۵.۰۳
SMS;

try {
    $result = (new BlueBankSmsParser())->parse($sample);
    assertBlueValue(1800000, $result['amount_rial'], 'Incoming amount');
    assertBlueValue(180000, $result['amount_toman'], 'Incoming amount in toman');
    assertBlueValue(16451024, $result['balance_rial'], 'Balance must remain separate');
    assertBlueValue('10:07', $result['time'], 'Persian time normalization');
    assertBlueValue('1405.05.03', $result['date'], 'Persian date normalization');

    try {
        (new BlueBankSmsParser())->parse("بلو\nموجودی: 1,800,000 ریال");
        throw new RuntimeException('A balance-only message must not be accepted.');
    } catch (InvalidArgumentException $expected) {
        // Expected: only the explicit incoming-transfer sentence is trusted.
    }

    fwrite(STDOUT, "Blu SMS parser tests passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Blu SMS parser tests failed: {$e->getMessage()}\n");
    exit(1);
}
