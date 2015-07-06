<?php

require(dirname(__FILE__) . '/lib/paymentwall-php/lib/paymentwall.php');

function initPaymentwallConfigs($appKey, $appSecret)
{
    Paymentwall_Config::getInstance()->set(array(
        'api_type' => Paymentwall_Config::API_GOODS,
        'public_key' => $appKey,
        'private_key' => $appSecret
    ));
}