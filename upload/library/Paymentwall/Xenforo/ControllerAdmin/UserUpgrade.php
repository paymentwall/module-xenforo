<?php

class Paymentwall_XenForo_ControllerAdmin_UserUpgrade extends XFCP_Paymentwall_XenForo_ControllerAdmin_UserUpgrade
{
	public function actionIndex()
	{
		$optionModel = $this->getModelFromCache('XenForo_Model_Option');
		$optionModel->Paymentwall_hijackOptions();
		
		return parent::actionIndex();
	}
}