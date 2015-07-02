<?php

require(dirname(__FILE__) . '/lib/paymentwall-php/lib/paymentwall.php');

class Paymentwall_Processor extends bdPaygate_Processor_Abstract
{
    protected $appKey;
    protected $appSecret;
    protected $widgetCode;
    protected $testMode;

    function __construct()
    {
        $options = XenForo_Application::getOptions();
        $this->appKey = $options->get('Paymentwall_appKey');
        $this->appSecret = $options->get('Paymentwall_appSecret');
        $this->widgetCode = $options->get('Paymentwall_widgetCode');
        $this->testMode = $options->get('Paymentwall_testMode');
    }

    public function isAvailable()
    {
        // no application key secret key, and widget code
        if (empty($this->appKey) || empty($this->appSecret) || empty ($this->widgetCode)) {
            return false;
        }

        if ($this->_sandboxMode()) {
            return false;
        }

        return true;
    }

    public function getSupportedCurrencies()
    {
        return array(
            bdPaygate_Processor_Abstract::CURRENCY_USD,
            bdPaygate_Processor_Abstract::CURRENCY_CAD,
            bdPaygate_Processor_Abstract::CURRENCY_AUD,
            bdPaygate_Processor_Abstract::CURRENCY_GBP,
            bdPaygate_Processor_Abstract::CURRENCY_EUR
        );
    }

    public function isRecurringSupported()
    {
        return false;
    }


    public function validateCallback(Zend_Controller_Request_Http $request, &$transactionId, &$paymentStatus, &$transactionDetails, &$itemId)
    {
        // return true;
    }

    /*
     * Calculate the parameters so we know we are sending a legitimate (unaltered) request to the servers
     */
    function calculateWidgetSignature($params, $secret)
    {

    }

    public function generateFormData($amount, $currency, $itemName, $itemId, $recurringInterval = false, $recurringUnit = false, array $extraData = array())
    {
        $this->initPaymentwallConfigs();

        $itemParts = explode('|', $itemId, 4);
        $upgradeId = $itemParts[3];
        $validationType = $itemParts[0];
        $validation = XenForo_Visitor::getInstance()->get('csrf_token_page');

        $this->_assertAmount($amount);
        $this->_assertCurrency($currency);
        $this->_assertItem($itemName, $itemId);
        $this->_assertRecurring($recurringInterval, $recurringUnit);

        $userid = XenForo_Visitor::getInstance()->get('user_id');
        $custom = $userid . '|' . $upgradeId . '|' . $validationType . '|' . $validation;

        $widget = new Paymentwall_Widget(
            $userid,
            $this->widgetCode,
            array(
                new Paymentwall_Product(
                    $itemId,
                    $amount,
                    $currency,
                    $itemName
                )
            ),
            array(
                'email' => XenForo_Visitor::getInstance()->get('email'),
                'integration_module' => 'xenforo',
                'test_mode' => $this->testMode,
                'success_url' => $this->_generateReturnUrl($extraData),
                'custom' => $custom,
            ));

        $callToAction = new XenForo_Phrase('Paymentwall_call_to_action');
        $_xfToken = XenForo_Visitor::getInstance()->get('csrf_token_page');

        $form = <<<EOF
    <form action="{$widget->getUrl()}" method="POST">
    <input type="submit" value="{$callToAction}" class="button" />
    </form>
EOF;

        return $form;
    }

    function initPaymentwallConfigs()
    {
        Paymentwall_Config::getInstance()->set(array(
            'api_type' => Paymentwall_Config::API_GOODS,
            'public_key' => $this->appKey,
            'private_key' => $this->appSecret
        ));
    }
}