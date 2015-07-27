<?php

class Paymentwall_XenForo_ControllerPublic_Misc extends XFCP_Paymentwall_XenForo_ControllerPublic_Misc
{
    public function actionPaymentwall()
    {
        $appKey = XenForo_Application::getOptions()->get('Paymentwall_appKey');
        if (empty($appKey)) {
            throw new XenForo_Exception('Project Key has not been configured');
        }
    }
}