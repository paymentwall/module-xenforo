<?php

class Paymentwall_XenForo_Model_Currency
{
    /**
     * Return addition currencies
     * @return array
     * @throws Zend_Exception
     */
    public static function getCurrencies()
    {
        // Get string currency from config
        $currency = XenForo_Application::get('options')->Paymentwall_currencies;
        $currencies = array();

        if ($currency) {

            $currencyArray = array_unique(array_filter(array_map(array(
                'Paymentwall_XenForo_Model_Currency',
                'processStr'), explode(',', $currency))));

            foreach ($currencyArray as $cur) {
                $currencies[$cur] = strtoupper($cur);
            }
        }

        return $currencies;
    }

    /**
     * For array map function
     * @param $string
     * @return string
     */
    protected static function processStr($string)
    {
        return strtolower(substr(trim($string), 0, 3));
    }
}