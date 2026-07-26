<?php

require_once dirname(__DIR__) . '/lib/TelegramKeyboard.php';

$default = telegramKeyboardDefaultLayout();
$rows = telegramKeyboardNormalizeRows($default['keyboard']);
if (($rows[0][0]['style'] ?? null) !== 'success' || ($rows[0][1]['style'] ?? null) !== 'primary') {
    throw new RuntimeException('Default Telegram button styles are missing.');
}

$invalidCases = [
    [[['text' => 'unknown_key']]],
    [[['text' => 'text_sell'], ['text' => 'text_sell']]],
    [[['text' => 'text_sell'], ['text' => 'text_extend'], ['text' => 'text_help'], ['text' => 'text_support']]],
];
foreach ($invalidCases as $case) {
    try {
        telegramKeyboardNormalizeRows($case);
        throw new RuntimeException('Invalid keyboard layout was accepted.');
    } catch (InvalidArgumentException $e) {
        // Expected.
    }
}

$sanitized = telegramKeyboardNormalizeRows([[['text' => 'text_sell', 'style' => 'javascript']]]);
if (isset($sanitized[0][0]['style'])) {
    throw new RuntimeException('Unknown Telegram button style was persisted.');
}

fwrite(STDOUT, "Telegram keyboard validation tests passed.\n");
