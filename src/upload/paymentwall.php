<?php

$startTime = microtime(true);
$fileDir = dirname(__FILE__);

require($fileDir . '/library/XenForo/Autoloader.php');

XenForo_Autoloader::getInstance()->setupAutoloader($fileDir . '/library');

XenForo_Application::initialize($fileDir . '/library', $fileDir);
XenForo_Application::set('page_start_time', $startTime);

$deps = new XenForo_Dependencies_Public();
$deps->preLoadData();

$response = new Zend_Controller_Response_Http();
$processor = new XenForo_UserUpgradeProcessor_Paymentwall();
$processor->initCallbackHandling(new Zend_Controller_Request_Http());

$logExtra = array();

try {
    if (!($processor->validateRequest() && $processor->validatePreConditions())) {
        throw new Exception($processor->getLogMessage(), 500);
    }

    list($logType, $logMessage) = $processor->processTransaction();

} catch (Exception $e) {
    $response->setHttpResponseCode($e->getCode());
    XenForo_Error::logException($e);

    $logType = 'error';
    $logMessage = 'Exception: ' . $e->getMessage();
    $logExtra['_e'] = $e;
}

$processor->log($logType, $logMessage, $logExtra);

$response->setBody(htmlspecialchars($logMessage));
$response->sendResponse();