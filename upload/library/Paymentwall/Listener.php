<?php

class Paymentwall_Listener {

	public static function load_class($class, array &$extend) {
		static $classes = array(
			'bdPaygate_Model_Processor',
		
			'XenForo_ControllerAdmin_UserUpgrade',
			'XenForo_ControllerPublic_Misc',
			'XenForo_Model_Option',
		);
		
		if (in_array($class, $classes))
		{
			$extend[] = 'Paymentwall_' . $class;
		}
	}

}