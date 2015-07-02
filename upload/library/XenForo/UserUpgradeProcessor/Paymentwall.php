<?php

require_once XenForo_Application::getInstance()->getRootDir() . '/library/Paymentwall/lib/paymentwall-php/lib/paymentwall.php';

/**
 * Handles user upgrade processing with Paymentwall.
 *
 * @package XenForo_UserUpgrade
 */
class XenForo_UserUpgradeProcessor_Paymentwall
{
    const PAYMENT_CHARGE_BACK = 2;

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

    function __construct()
    {
        $options = XenForo_Application::getOptions();
        $this->appKey = $options->get('Paymentwall_appKey');
        $this->appSecret = $options->get('Paymentwall_appSecret');
        $this->widgetCode = $options->get('Paymentwall_widgetCode');
        $this->testMode = $options->get('Paymentwall_testMode');
    }

    /**
     * Initializes handling for processing a request callback.
     *
     * @param Zend_Controller_Request_Http $request
     */
    public function initCallbackHandling(Zend_Controller_Request_Http $request)
    {
        /**
         *  Collect the GET parameters from the request URL
         */
        $this->_request = $request;
        $this->_input = new XenForo_Input($request);

        $this->_filtered = $this->_input->filter(array(
            'uid' => XenForo_Input::STRING,
            'goodsid' => XenForo_Input::STRING,
            'slength' => XenForo_Input::STRING,
            'speriod' => XenForo_Input::STRING,
            'type' => XenForo_Input::STRING,
            'sig' => XenForo_Input::STRING,
            'ref' => XenForo_Input::STRING,
            'sign_version' => XenForo_Input::STRING,
            'custom' => XenForo_Input::STRING
        ));

        $this->_upgradeModel = XenForo_Model::create('XenForo_Model_UserUpgrade');
        $this->_bdUpgradeModel = XenForo_Model::create('bdPaygate_Model_Processor');
    }

    /**
     * Validates the callback request is valid. If failure happens, the response should
     * tell the processor to retry.
     *
     * @param string $errorString Output error string
     *
     * @return boolean
     */
    public function validateRequest(&$errorString)
    {
        $this->initPaymentwallConfigs();
        $pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);

        if ($pingback->validate()) {
            return true;
        } else {
            $errorString = $pingback->getErrorSummary();
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
    public function log($type, $message, array $extra)
    {

        $upgradeRecordId = $this->getUpgradeRecordId();
        $processor = $this->getProcessorId();
        $transactionId = $this->getTransactionId();
        $details = $this->getLogDetails() + $extra;

        $this->_upgradeModel->logProcessorCallback(
            $upgradeRecordId, $processor, $transactionId, $type, $message, $details
        );

        if (!$this->alreadyprocessed) {
            switch ($this->_filtered['type']) {
                case self::PAYMENT_CHARGE_BACK:
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

        $this->_bdUpgradeModel->log($processor, $transactionId, $type, $message, $details);
    }

    /**
     * Gets the ID of the upgrade record changed.
     *
     * @return integer
     */
    public function getUpgradeRecordId()
    {
        return intval($this->_upgradeRecordId);
    }

    /**
     * Gets the ID of the processor.
     *
     * @return string
     */
    public function getProcessorId()
    {
        return 'paymentwall';
    }

    /**
     * Gets the transaction ID.
     *
     * @return string
     */
    public function getTransactionId()
    {
        return $this->_filtered['ref'];
    }

    /**
     * Get details for use in the log.
     *
     * @return array
     */
    public function getLogDetails()
    {
        $details = $this->_filtered;
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
    public function validatePreConditions(&$errorString)
    {

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
            if ($this->_filtered['type'] != self::PAYMENT_CHARGE_BACK) {
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
    public function processTransaction()
    {
        switch ($this->_filtered['type']) {
            case self::PAYMENT_CHARGE_BACK:
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

    function initPaymentwallConfigs()
    {
        Paymentwall_Config::getInstance()->set(array(
            'api_type' => Paymentwall_Config::API_GOODS,
            'public_key' => $this->appKey,
            'private_key' => $this->appSecret
        ));
    }
}