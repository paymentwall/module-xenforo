<?php

require(dirname(__FILE__) . '/common.php');

class Paymentwall_Processor extends bdPaygate_Processor_Abstract
{
    protected $options;
    protected $enableCurrencies;

    function __construct()
    {
        $this->options = XenForo_Application::getOptions();
    }

    /**
     * Checks whether the processor is available and ready
     * to accept payments
     *
     * @return bool
     */
    public function isAvailable()
    {
        if ($this->_sandboxMode()) {
            return false;
        }
        return true;
    }


    /**
     * Returns list of supported currencies.
     * @override abstract class
     * @return array
     */
    public function getSupportedCurrencies()
    {
        return array_keys(array_merge(
            $this->getModelFromCache('bdPaygate_Model_Processor')->getEnabledCurrencies(),
            Paymentwall_XenForo_Model_Currency::getCurrencies()
        ));
    }

    /**
     * Returns boolean value whether this processor supports recurring.
     * @override from abstract class
     * @return bool
     */
    public function isRecurringSupported()
    {
        return false;
    }


    /**
     * Validates callback from payment gateway.
     * THIS METHOD HAS BEEN DEPRECATED, please implement validateCallback2
     *
     * @override abstract class
     * @param Zend_Controller_Request_Http $request
     * @param out $transactionId
     * @param out $paymentStatus
     * @param out $transactionDetails
     * @param out $itemId
     * @return bool|void
     */
    public function validateCallback(Zend_Controller_Request_Http $request, &$transactionId, &$paymentStatus, &$transactionDetails, &$itemId)
    {
        $this->validateCallback2($request, $transactionId, $paymentStatus, $transactionDetails, $itemId, $amount, $currency);
    }

    /**
     * Validates callback from payment gateway.
     * This is version 2 of the method validateCallback which supports
     * amount and currency extraction, allow the system to detect a few
     * type of malicious activities.
     *
     * @param Zend_Controller_Request_Http $request
     * @param out $transactionId
     * @param out $paymentStatus
     * @param out $transactionDetails
     * @param out $itemId
     * @param out $amount
     * @param out $currency
     *
     * @return bool
     */
    public function validateCallback2(Zend_Controller_Request_Http $request, &$transactionId, &$paymentStatus, &$transactionDetails, &$itemId, &$amount, &$currency)
    {
        return true;
    }


    public function generateFormData($amount, $currency, $itemName, $itemId, $recurringInterval = false, $recurringUnit = false, array $extraData = array())
    {
        initPaymentwallConfigs(
            $this->options->get('Paymentwall_appKey'),
            $this->options->get('Paymentwall_appSecret')
        );

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
            $this->options->get('Paymentwall_widgetCode'),
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
                'test_mode' => $this->options->get('Paymentwall_testMode'),
                'success_url' => $this->_generateReturnUrl($extraData),
                'custom' => $custom,
            ));

        $callToAction = new XenForo_Phrase('Paymentwall_call_to_action');

        $form = <<<EOF
    <form action="{$widget->getUrl()}" method="POST">
    <input type="submit" value="{$callToAction}" class="button" />
    </form>
EOF;
        return $form;
    }
}