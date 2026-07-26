<?php

/**
 * Canonical Telegram menu layout and strict validation for layouts saved by
 * the web panel/API. Telegram currently accepts at most three visual styles:
 * primary (blue), success (green) and danger (red).
 */
function telegramKeyboardDefaultLayout(): array
{
    return [
        'keyboard' => [
            [['text' => 'text_sell', 'style' => 'success'], ['text' => 'text_extend', 'style' => 'primary']],
            [['text' => 'text_usertest', 'style' => 'primary'], ['text' => 'text_wheel_luck']],
            [['text' => 'text_Purchased_services'], ['text' => 'accountwallet', 'style' => 'primary']],
            [['text' => 'text_affiliates'], ['text' => 'text_Tariff_list']],
            [['text' => 'text_support'], ['text' => 'text_help']],
        ],
    ];
}

function telegramKeyboardAllowedKeys(): array
{
    return [
        'text_sell', 'text_extend', 'text_usertest', 'text_wheel_luck',
        'text_Purchased_services', 'accountwallet', 'text_affiliates',
        'text_Tariff_list', 'text_support', 'text_help',
    ];
}

/**
 * Normalize the rows submitted by the keyboard editor.
 *
 * @throws InvalidArgumentException when an unknown/duplicate key is supplied.
 */
function telegramKeyboardNormalizeRows(array $rows): array
{
    if (count($rows) > 12) {
        throw new InvalidArgumentException('Too many keyboard rows.');
    }

    $allowedKeys = array_flip(telegramKeyboardAllowedKeys());
    $allowedStyles = ['primary', 'success', 'danger'];
    $seen = [];
    $cleanRows = [];

    foreach ($rows as $row) {
        if (!is_array($row) || count($row) > 3) {
            throw new InvalidArgumentException('Invalid keyboard row.');
        }
        $cleanRow = [];
        foreach ($row as $button) {
            if (!is_array($button) || !isset($button['text']) || !is_string($button['text'])) {
                throw new InvalidArgumentException('Invalid keyboard button.');
            }
            $key = $button['text'];
            if (!isset($allowedKeys[$key]) || isset($seen[$key])) {
                throw new InvalidArgumentException('Unknown or duplicate keyboard button.');
            }
            $seen[$key] = true;
            $cleanButton = ['text' => $key];
            if (isset($button['style']) && in_array($button['style'], $allowedStyles, true)) {
                $cleanButton['style'] = $button['style'];
            }
            if (isset($button['icon_custom_emoji_id'])
                && is_string($button['icon_custom_emoji_id'])
                && preg_match('/^[0-9]{1,30}$/', $button['icon_custom_emoji_id'])) {
                $cleanButton['icon_custom_emoji_id'] = $button['icon_custom_emoji_id'];
            }
            $cleanRow[] = $cleanButton;
        }
        if ($cleanRow) {
            $cleanRows[] = $cleanRow;
        }
    }

    if (!$cleanRows) {
        throw new InvalidArgumentException('Keyboard cannot be empty.');
    }
    return $cleanRows;
}

function telegramKeyboardDefaultJson(): string
{
    return json_encode(telegramKeyboardDefaultLayout(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
