<?php

class Paymentwall_XenForo_Model_Option extends XFCP_Paymentwall_XenForo_Model_Option
{

    // this property must be static because XenForo_ControllerAdmin_UserUpgrade::actionIndex
    // for no apparent reason use XenForo_Model::create to create the optionModel
    // (instead of using XenForo_Controller::getModelFromCache)
    private static $_Paymentwall_hijackOptions = FALSE;

    public function getOptionsByIds(array $optionIds, array $fetchOptions = array())
    {
        if (self::$_Paymentwall_hijackOptions === TRUE) {
            $optionIds[] = 'Paymentwall_widgetCode';
            $optionIds[] = 'Paymentwall_appSecret';
            $optionIds[] = 'Paymentwall_appKey';
        }

        $options = parent::getOptionsByIds($optionIds, $fetchOptions);

        self::$_Paymentwall_hijackOptions = FALSE;

        return $options;
    }

    public function Paymentwall_hijackOptions()
    {
        self::$_Paymentwall_hijackOptions = TRUE;
    }
}