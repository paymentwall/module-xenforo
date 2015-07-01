<?php

class Paymentwall_Processor extends bdPaygate_Processor_Abstract {

    protected $appKey;
    protected $appSecret;
    protected $widgetCode;
        
	public function isAvailable() {
		$options = XenForo_Application::getOptions();
		$this->appKey = $options->get('Paymentwall_appKey');
		$this->appSecret = $options->get('Paymentwall_appSecret');
		$this->widgetCode = $options->get('Paymentwall_widgetCode');

        // no application key secret key, and widget code
		if (empty($this->appKey) || empty($this->appSecret) || empty ($this->widgetCode)) {
			return false;
		}

		if ($this->_sandboxMode())
		{
			return false;
		}

		return true;
	}

	public function getSupportedCurrencies() {

		return array(
			bdPaygate_Processor_Abstract::CURRENCY_USD,
			bdPaygate_Processor_Abstract::CURRENCY_CAD,
			bdPaygate_Processor_Abstract::CURRENCY_AUD,
			bdPaygate_Processor_Abstract::CURRENCY_GBP,
			bdPaygate_Processor_Abstract::CURRENCY_EUR
		);
	}
	
	public function isRecurringSupported() {
		return false;
	}
    
	
	public function validateCallback(Zend_Controller_Request_Http $request, &$transactionId, &$paymentStatus, &$transactionDetails, &$itemId) {
		// return true;
	}
	
    /*
     * Calculate the parameters so we know we are sending a legitimate (unaltered) request to the servers
     */
    function calculateWidgetSignature($params, $secret) {
        // work with sorted data
        ksort($params);
        // generate the base string
        $baseString = '';
        foreach($params as $key => $value) {
            $baseString .= $key . '=' . $value;
        }
        $baseString .= $secret;
        return md5($baseString);
    }

	public function generateFormData($amount, $currency, $itemName, $itemId, $recurringInterval = false, $recurringUnit = false, array $extraData = array()) {
		
        $itemParts = explode('|', $itemId, 4);
        $upgradeId = $itemParts[3];
        $validationType = $itemParts[0];
        $validation = XenForo_Visitor::getInstance()->get('csrf_token_page');

        $this->_assertAmount($amount);
		$this->_assertCurrency($currency);
		$this->_assertItem($itemName, $itemId);
		$this->_assertRecurring($recurringInterval, $recurringUnit);
        
        $userid = XenForo_Visitor::getInstance()->get('user_id');
        $email = XenForo_Visitor::getInstance()->get('email');
        $custom = $userid . '|' . $upgradeId . '|' . $validationType . '|' . $validation;
        
        $pwVars = array (
            'key' => $this->appKey,
            'uid' => $userid,
            'widget' => $this->widgetCode,
            'sign_version' => 2,
            'amount' => $amount,
            'email' =>  $email,
            'currencyCode' => $currency,
            'ag_name' => $itemName,
            'ag_external_id' => $itemId,
            'ag_type' => 'fixed',
            'custom' => $custom,
            'success_url' => $this->_generateReturnUrl($extraData)
        );

        $pwVars['sign'] = $this->calculateWidgetSignature($pwVars, $this->appSecret);
		$formAction = "https://wallapi.com/api/subscription?" . http_build_query ( $pwVars );
		$callToAction = new XenForo_Phrase('Paymentwall_call_to_action');
		$_xfToken = XenForo_Visitor::getInstance()->get('csrf_token_page');
		
		$form = <<<EOF
<form action="{$formAction}" method="POST">
	<input type="submit" value="{$callToAction}" class="button" />
</form>
EOF;

		return $form;
	}
}