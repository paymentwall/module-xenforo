<?php

class Paymentwall_bdPaygate_Model_Processor extends XFCP_Paymentwall_bdPaygate_Model_Processor
{
	public function getProcessorNames()
	{
		$names = parent::getProcessorNames();
		
		$names['paymentwall'] = 'Paymentwall_Processor';
		
		return $names;
	}
}