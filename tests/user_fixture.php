<?php

function testUserTemplate(PDO $pdo): array
{
    $existing = $pdo->query('SELECT * FROM user LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return $existing;
    }

    return [
        'id' => '', 'limit_usertest' => 0, 'roll_Status' => 0,
        'username' => 'none', 'Processing_value' => 'none',
        'Processing_value_one' => 'none', 'Processing_value_tow' => 'none',
        'Processing_value_four' => 'none', 'step' => 'home',
        'description_blocking' => null, 'number' => 'none', 'Balance' => 0,
        'User_Status' => 'Active', 'pagenumber' => 1, 'message_count' => '0',
        'last_message_time' => '0', 'agent' => 'f', 'affiliatescount' => '0',
        'affiliates' => '0', 'namecustom' => 'none', 'number_username' => '100',
        'register' => (string) time(), 'verify' => '1', 'cardpayment' => '1',
        'codeInvitation' => null, 'pricediscount' => '0',
        'hide_mini_app_instruction' => '0', 'maxbuyagent' => '0',
        'joinchannel' => '0', 'checkstatus' => '0', 'bottype' => null,
        'score' => 0, 'limitchangeloc' => '0', 'status_cron' => '1',
        'expire' => null, 'token' => null, 'lang' => 'fa',
        'reseller_tier_id' => null,
    ];
}
