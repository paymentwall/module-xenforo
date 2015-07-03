<?php

require_once XenForo_Application::getInstance()->getRootDir() . '/library/Paymentwall/common.php';

/**
 * Handles user upgrade processing with Paymentwall.
 *
 * @package XenForo_UserUpgrade
 */
class XenForo_UserUpgradeProcessor_Paymentwall
{
    const PAYMENT_REGULAR_OFFER = 0;
    const PAYMENT_VIRTUAL_CURRENCY = 1;
    const PAYMENT_CHARGE_BACK = 2;
    const PAYMENT_STATUS_CANCELED = 'cancelled';
    const PAYMENT_STATUS_PROCESSED = 'processed';
    const PROCESSER_ID = 'paymentwall';

    protected $options;

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
     * @var The transaction log messages
     */
    protected $logMessages = array();

    function __construct()
    {
        $this->options = XenForo_Application::getOptions();
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
     * @return bool
     *
     */
    public function validateRequest()
    {
        initPaymentwallConfigs(
            $this->options->get('Paymentwall_appKey'),
            $this->options->get('Paymentwall_appSecret')
        );

        $pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);

        if ($pingback->validate()) {
            return true;
        } else {
            $this->logMessages[] = $pingback->getErrorSummary();
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
                    $type = self::PAYMENT_STATUS_CANCELED;
                    break;
                case self::PAYMENT_VIRTUAL_CURRENCY:
                    $type = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED;
                    break;
                case self::PAYMENT_REGULAR_OFFER:
                    $type = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED;
                    break;
                default:
                    $type = bdPaygate_Processor_Abstract::PAYMENT_STATUS_OTHER;
            }

        } else {
            $type = self::PAYMENT_STATUS_PROCESSED;
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
        return self::PROCESSER_ID;
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
        return $this->_filtered;
    }

    /**
     * Validates pre-conditions on the callback. These represent things that likely wouldn't get fixed
     * (and generally shouldn't happen), so retries are not necessary.
     *
     * @return boolean
     */
    public function validatePreConditions()
    {

        $itemParts = explode('|', $this->_filtered['custom'], 4);
        if (count($itemParts) != 4) {
            $this->logMessages[] = 'Invalid item (custom)';
            return false;
        }

        list($userId, $userUpgradeId, $validationType, $validation) = $itemParts;

        $this->_user = XenForo_Model::create('XenForo_Model_User')->getFullUserById($userId);
        $this->_upgrade = $this->_upgradeModel->getUserUpgradeById($userUpgradeId);
        $tokenParts = explode(',', $validation);
        $transaction = $this->_upgradeModel->getProcessedTransactionLog($this->_filtered['ref']);

        $continue = $this->handleValidate(array(
            array('result' => $this->_user, 'message' => 'Invalid user'),
            array(
                'result' => !(count($tokenParts) != 3 || sha1($tokenParts[1] . $this->_user['csrf_token']) != $tokenParts[2]),
                'message' => 'Invalid validation'
            ),
            array('result' => $this->_upgrade, 'message' => 'Invalid user upgrade'),
            array('result' => $this->_filtered['ref'], 'message' => 'No reference ID')
        ));

        if (!$continue) return $continue;

        if ($transaction) {
            if ($this->_filtered['type'] != self::PAYMENT_CHARGE_BACK) {
                $this->alreadyprocessed = true;
                $this->logMessages[] = 'Transaction already processed';
                return false;
            }
        }

        $this->_upgradeRecord = $this->_upgradeModel->getActiveUserUpgradeRecord(
            $this->_user['user_id'],
            $this->_upgrade['user_upgrade_id']
        );

        if ($this->_upgradeRecord) {
            $this->_upgradeRecordId = $this->_upgradeRecord['user_upgrade_record_id'];
        }

        return true;
    }

    /**
     * @param array $conditions
     * @return bool
     */
    private function handleValidate($conditions = array())
    {
        $flag = true;
        foreach ($conditions as $cond) {
            if (!$cond['result']) {
                $this->logMessages[] = $cond['message'];
                if ($flag) $flag = false;
            }
        }
        return $flag;
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

    /**
     * @return string
     */
    function getLogMessage()
    {
        return implode('<br>', $this->logMessages);
    }
}