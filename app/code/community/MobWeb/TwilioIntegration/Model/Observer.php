<?php

class MobWeb_TwilioIntegration_Model_Observer
{
	// This method is called whenever a new order is placed with the store
	public function salesOrderPlaceAfter($observer)
	{
		// Get the order object
		$order = $observer->getEvent()->getOrder();

		// Generate the body for the notification
		$store_name = Mage::app()->getStore()->getFrontendName();
		$customer_name = $order->getCustomerFirstname();
		$customer_name .= ' ' . $order->getCustomerLastname();
		$order_amount = $order->getBaseCurrencyCode();
		$order_amount .= ' ' . $order->getBaseGrandTotal();

		$body = sprintf('%s: %s has just placed an order for %s', $store_name, $customer_name, $order_amount);

		// Get the settings
		$settings = Mage::helper('twiliointegration/data')->getSettings();

		// If no recipients have been set, we can't do anything
		if(!count($settings['recipients'])) {
			return;
		}
		
		// Send the order notification by SMS
		$result = Mage::helper('twiliointegration/data')->sendSms($body, $settings['recipients']);

		// Check if the sending was successful
		if(!$result) {
			// If an error occured, notify the administrator
			Mage::helper('twiliointegration/data')->sendAdminEmail(sprintf('%s was unable to send one or more order notifications to the specified number(s). Please check your configuration to make sure that your Twilio API settings are correct!', Mage::helper('twiliointegration/data')->app_name));
		}
	}

	// This method is called whenever a new shipment is created for an order
	public function salesOrderShipmentSaveAfter($observer)
	{
		// Get the telephone # associated with the shipping (or billing) address
		$order = $observer->getEvent()->getShipment()->getOrder();
		$shippingAdress = $order->getShippingAddress();
		$telephoneNumber = $shippingAdress->getTelephone();

		// Check if a telephone number has been specified
		if($telephoneNumber) {
			// If a country code filter has been defined, check if the current telephone number matches against it
			if($telephoneNumberFilter = Mage::getStoreConfig('twiliointegration/notification_settings/telephone_number_country_code_filter')) {
				$telephoneNumberIsAllowed = false;
				$telephoneNumberFilter = explode(',', $telephoneNumberFilter);
				foreach($telephoneNumberFilter AS $telephoneNumberFilterItem) {
					if(strpos($telephoneNumber, $telephoneNumberFilterItem) === 0) {
						$telephoneNumberIsAllowed = true;
						break;
					}
				}

				// If the current telephone number is not in the list of filtered country codes, abort
				if(!$telephoneNumberIsAllowed) {
					Mage::helper('twiliointegration/data')->log(sprintf('Telephone number %s is not in list of allowed country codes: %s', $telephoneNumber, implode(',', $telephoneNumberFilter)));
					return;
				}
			}

			// Send the shipment notification to the specified telephone number
			$result = Mage::helper('twiliointegration/data')->sendSms(Mage::getStoreConfig('twiliointegration/notification_settings/shipment_notification_message'), array($telephoneNumber));

			// Display a success or error message
			if($result) {
				Mage::getSingleton('adminhtml/session')->addSuccess(sprintf('The shipment notification has been sent via SMS to: %s', $telephoneNumber));
			} else {
				Mage::getSingleton('adminhtml/session')->addError('There has been an error sending the shipment notification SMS.');
			}
		}
	}

	// This method is called whenever the application's setting in the
	// adminhtml are changed
	public function configSaveAfter($observer)
	{
		// Get the settings
		$settings = Mage::helper('twiliointegration/data')->getSettings();

		// If no recipients have been set, we can't do anything
		if(!count($settings['recipients'])) {
			return;
		}

		// Verify the settings by sending a test message
		$result = Mage::helper('twiliointegration/data')->sendSms('Congratulations, you have configured the extension correctly!', $settings['recipients']);

		// Display a success or error message
		if($result) {
			// If everything has worked, let the user know that a test message
			// has been sent to the recipients
			$recipients_string = implode(', ', $settings['recipients']);
			Mage::getSingleton('adminhtml/session')->addNotice(sprintf('A test message has been sent to the following recipient(s): %s. Please verify that all recipients received this test message. If not, correct the number(s) below.', $recipients_string));
		} else {
			Mage::getSingleton('adminhtml/session')->addError('Unable to send test message. Please verify that all your settings are correct and try again.');
		}
	}
}