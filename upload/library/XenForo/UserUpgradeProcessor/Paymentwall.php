<?php

/**
 * Handles user upgrade processing with Paymentwall.
 *
 * @package XenForo_UserUpgrade
 */
class XenForo_UserUpgradeProcessor_Paymentwall {
	/**
	 * @var Zend_Controller_Request_Http
	 */
	protected $_request;

	/**
	 * @var XenForo_Input
	 */
	protected $_input;

	/**
	 * List of filtered input for handling a callback.
	 *
	 * @var array
	 */
	protected $_filtered = null;

	/**
	 * Info about the upgrade being processed.
	 *
	 * @var array|false
	 */
	protected $_upgrade = false;

	/**
	 * Info about the user the upgrade is for.
	 *
	 * @var array|false
	 */
	protected $_user = false;

	/**
	 * The upgrade record ID inserted/updated.
	 *
	 * @var integer|null
	 */
	protected $_upgradeRecordId = null;

	/**
	 * The upgrade record being processed.
	 *
	 * @var array|false
	 */
	protected $_upgradeRecord = false;

	/**
	 * @var XenForo_Model_UserUpgrade
	 */
	protected $_upgradeModel = null;
    
    /**
	 * @var BD_Model_UserUpgrade
	 */
    protected $_bdUpgradeModel = null;
    
    /**
	 * @var The transaction is already processed
	 */
    protected $alreadyprocessed = null;
    
    /**
	 * @var The transaction error message
	 */
    protected $errormsg = null;
    
    /**
	 * @var Paymentwall's Server IPs
	 */
    protected $ipsWhitelist = array(
                                    '174.36.92.186',
                                    '174.36.96.66',
                                    '174.36.92.187',
                                    '174.36.92.192',
                                    '174.37.14.28'
                                );
	/**
	 * Initializes handling for processing a request callback.
	 *
	 * @param Zend_Controller_Request_Http $request
	 */
	public function initCallbackHandling(Zend_Controller_Request_Http $request) {
        /**  
         *  Collect the GET parameters from the request URL
         */
        $this->userId = isset($_GET['uid']) ? $_GET['uid'] : null; 
        $this->goodsId = isset($_GET['goodsid']) ? $_GET['goodsid'] : null; 
        $this->length = isset($_GET['slength']) ? $_GET['slength'] : null; 
        $this->period = isset($_GET['speriod']) ? $_GET['speriod'] : null; 
        $this->type = isset($_GET['type']) ? $_GET['type'] : null; 
        $this->refId = isset($_GET['ref']) ? $_GET['ref'] : null; 
        $this->signature = isset($_GET['sig']) ? $_GET['sig'] : null;
        $this->sign_version = isset($_GET['sign_version']) ? $_GET['sign_version'] : null;

		$this->_request = $request;
		$this->_input = new XenForo_Input($request);

		$this->_filtered = $this->_input->filter(array(
			'uid' => XenForo_Input::STRING,
			'goodsid' => XenForo_Input::STRING,
			'slength' => XenForo_Input::STRING,
			'speriod' => XenForo_Input::STRING,
			'type' => XenForo_Input::STRING,
			'ref' => XenForo_Input::STRING,
			'sig' => XenForo_Input::STRING,
			'sign_version' => XenForo_Input::STRING,
            'custom' => XenForo_Input::STRING
		));
        
        $options = XenForo_Application::getOptions();
        $this->appSecret = $options->get('Paymentwall_appSecret');
        
		$this->_upgradeModel =  XenForo_Model::create('XenForo_Model_UserUpgrade');
		$this->_bdUpgradeModel =  XenForo_Model::create('bdPaygate_Model_Processor');
	}

	/**
	 * Validates the callback request is valid. If failure happens, the response should
	 * tell the processor to retry.
	 *
	 * @param string $errorString Output error string
	 *
	 * @return boolean
	 */
	public function validateRequest(&$errorString) {
        /**
         *  version 1 signature
         */
        
        if (empty($this->sign_version) || $this->sign_version <= 1) {
            $this->signatureParams = array(
                'uid' => $this->userId, 
                'goodsid' => $this->goodsId, 
                'slength' => $this->length, 
                'speriod' => $this->period, 
                'type' => $this->type, 
                'ref' => $this->refId
            ); 
        }
        /**
         *  version 2+ signature
         */
        else {
            $this->signatureParams = array();
            foreach ($_GET as $param => $value) {    
                $this->signatureParams[$param] = $value;
            }
            unset($this->signatureParams['sig']);
            
        }
        
        /**
         *  check if IP is in whitelist and if signature matches    
         */
        $signatureCalculated = $this->calculatePingbackSignature($this->signatureParams, $this->appSecret, $this->sign_version);
        
        /**
         *  Run the security check -- if the request's origin is one
         *  of Paymentwall's servers, and if the signature matches
         *  the parameters.
         */
        if (!empty($this->userId) && !empty($this->goodsId) && isset($this->type) && !empty($this->refId) && !empty($this->signature)) {
    
            if (in_array($_SERVER['REMOTE_ADDR'], $this->ipsWhitelist)) {
            
                if ($this->signature == $signatureCalculated) {
                    return true;

                } else {
                    $errorString = 'wrong signature';
                    return false;
                }
            } else {
                $errorString = 'unauthorized source';
                return false;
            }
            
        } else {
            $errorString =   'missing parameters';
            return false;
        }
        
	}
    
    /**
	 * Logs the request.
	 *
	 * @param string $type Log type (info, payment, cancel, error)
	 * @param string $message Log message
	 * @param array $extra Extra details to log (not including output from getLogDetails)
	 */
	public function log($type, $message, array $extra) {

		$upgradeRecordId = $this->getUpgradeRecordId();
		$processor = $this->getProcessorId();
		$transactionId = $this->getTransactionId();
		$details = $this->getLogDetails() + $extra;

        $this->_upgradeModel->logProcessorCallback(
			$upgradeRecordId, $processor, $transactionId, $type, $message, $details
		);
        
        if (!$this->alreadyprocessed) {
            switch ($this->type) {
                case '2':
                    $type = 'cancelled';
                    break;
                case '1':
                    $type = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED;
                    break;
                case '0':
                    $type = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED;
                    break;
                    
                default:
                    $type = bdPaygate_Processor_Abstract::PAYMENT_STATUS_OTHER;
            }

        } else {
            $type = 'already processed';
        }
        
        $this->_bdUpgradeModel->log ($processor, $transactionId, $type, $message, $details);
       
	}

	/**
	 * Gets the ID of the upgrade record changed.
	 *
	 * @return integer
	 */
	public function getUpgradeRecordId() {
        return intval($this->_upgradeRecordId);
	}
    
    /**
	 * Gets the ID of the processor.
	 *
	 * @return string
	 */
	public function getProcessorId() {
		return 'paymentwall';
	}

	/**
	 * Gets the transaction ID.
	 *
	 * @return string
	 */
	public function getTransactionId() {
		return $this->_filtered['ref'];
	}

	/**
	 * Get details for use in the log.
	 *
	 * @return array
	 */
	public function getLogDetails() {
		$details = $this->signatureParams;
		return $details;
	}

	/**
	 * Validates pre-conditions on the callback. These represent things that likely wouldn't get fixed
	 * (and generally shouldn't happen), so retries are not necessary.
	 *
	 * @param string $errorString
	 *
	 * @return boolean
	 */
	public function validatePreConditions(&$errorString) {
        
        $itemParts = explode('|', $this->_filtered['custom'], 4);
		if (count($itemParts) != 4) {
			$errorString = 'Invalid item (custom)';
			return false;
		}

        list($userId, $userUpgradeId, $validationType, $validation) = $itemParts;
        
        $user = XenForo_Model::create('XenForo_Model_User')->getFullUserById($userId);
		if (!$user) {
			$errorString = 'Invalid user';
			return false;
		}
        

        
        $this->_user = $user;
        $tokenParts = explode(',', $validation);
		if (count($tokenParts) != 3 || sha1($tokenParts[1] . $user['csrf_token']) != $tokenParts[2]) {
			$errorString = 'Invalid validation';
			return false;
		}

        $upgrade = $this->_upgradeModel->getUserUpgradeById($userUpgradeId);
        if (!$upgrade) {
			$errorString = 'Invalid user upgrade';
			return false;
		}
        
		$this->_upgrade = $upgrade;
        if (!$this->_filtered['ref']) {
			$errorString = 'No reference ID';
			return false;
		}
        
        $transaction = $this->_upgradeModel->getProcessedTransactionLog($this->_filtered['ref']);
		if ($transaction) {
            
            if ( $this->type != '2' ) {
                $this->alreadyprocessed = true;
                $errorString = 'Transaction already processed';
                return false;
            }
		}
        
        $upgradeRecord = $this->_upgradeModel->getActiveUserUpgradeRecord($this->_user['user_id'], $this->_upgrade['user_upgrade_id']);
		if ($upgradeRecord) {
			$this->_upgradeRecordId = $upgradeRecord['user_upgrade_record_id'];
			$this->_upgradeRecord = $upgradeRecord;
		}

		return true;
	}

	/**
	 * Once all conditions are validated, process the transaction.
	 *
	 * @return array [0] => log type (payment, cancel, info), [1] => log message
	 */
	public function processTransaction() {
        
        switch ($this->type) {
			
            case '2':
                if ($this->_upgradeRecord) {
                    $this->_upgradeModel->downgradeUserUpgrade($this->_upgradeRecord);
                    return array('cancel', 'Payment refunded/reversed, downgraded');
                }
                break;
                
            default:
                $this->_upgradeRecordId = $this->_upgradeModel->upgradeUser($this->_user['user_id'], $this->_upgrade);
                return array('payment', 'Payment received, upgraded/extended');
		}

        $this->alreadyprocessed = true;
        return array('info', 'OK, no action');
	}

    /**
	 * Sign the parameters so that we know we are sending a legitimate request
	 *
	 * @return string
	 */
    function calculatePingbackSignature($params, $secret, $version) {
        $str = '';
        if ($version == 2) {
            ksort($params);
        }
        foreach ($params as $k=>$v) {
            $str .= "$k=$v";
        }
        $str .= $secret;
        return md5($str);
    }
}