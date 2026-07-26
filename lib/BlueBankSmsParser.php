<?php

final class BlueBankSmsParser
{
    /**
     * Parse Blu's incoming-transfer SMS without ever confusing the account
     * balance with the deposited amount.
     *
     * @return array{bank:string,amount_rial:int,amount_toman:?int,balance_rial:?int,time:?string,date:?string,raw_message:string}
     */
    public function parse(string $message): array
    {
        $normalized = $this->normalizeDigits(str_replace(["\r\n", "\r"], "\n", trim($message)));

        if (!preg_match('/عزیز\s*[،,]?\s*([0-9,٬]+)\s*ریال\s+به\s+حساب\s+شما\s+نشست/u', $normalized, $amountMatch)) {
            throw new InvalidArgumentException('پیامک واریز بلو معتبر نیست یا مبلغ آن پیدا نشد.');
        }

        $amountRial = $this->parseMoney($amountMatch[1]);
        if ($amountRial <= 0) {
            throw new InvalidArgumentException('مبلغ واریز بلو باید بیشتر از صفر باشد.');
        }

        $balanceRial = null;
        if (preg_match('/موجودی\s*[:：]\s*([0-9,٬]+)\s*ریال?/u', $normalized, $balanceMatch)) {
            $balanceRial = $this->parseMoney($balanceMatch[1]);
        }

        $time = null;
        if (preg_match('/(?:^|\n)\s*([0-2]?[0-9]:[0-5][0-9])\s*(?:\n|$)/u', $normalized, $timeMatch)) {
            $time = $timeMatch[1];
        }

        $date = null;
        if (preg_match('/(?:^|\n)\s*([0-9]{4}[.\/-][0-9]{1,2}[.\/-][0-9]{1,2})\s*(?:\n|$)/u', $normalized, $dateMatch)) {
            $date = $dateMatch[1];
        }

        return [
            'bank' => 'blue',
            'amount_rial' => $amountRial,
            'amount_toman' => $amountRial % 10 === 0 ? intdiv($amountRial, 10) : null,
            'balance_rial' => $balanceRial,
            'time' => $time,
            'date' => $date,
            'raw_message' => $message,
        ];
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function parseMoney(string $value): int
    {
        return (int) str_replace([',', '٬', ' '], '', $value);
    }
}
