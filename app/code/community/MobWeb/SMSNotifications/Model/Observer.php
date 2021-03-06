<?php

class MobWeb_SMSNotifications_Model_Observer
{
	// This method is called whenever an order is saved. It checks if the order's
	// status has been updated and if yes, checks if the new order status should
	// trigger sending a notification
	public function salesOrderSaveAfter($observer)
	{
		// Get the settings
		$settings = Mage::helper('smsnotifications/data')->getSettings();

		// Get the new order object
		$order = $observer->getEvent()->getOrder();

		// Get the old order data
		$oldOrder = $order->getOrigData();

		// If the order status hasn't changed, don't do anything
		if($oldOrder['status'] === $order->getStatus()) {
			return;
		}

		// If the order status has changed, check if a notification should be sent
		// for the new status. If not, don't do anything
		if($order->getStatus() !== $settings['order_notification_status']) {
			return;
		}

		Mage::log('sending', null, 'm.txt');

		// Generate the body for the notification
		$store_name = Mage::app()->getStore()->getFrontendName();
		$customer_name = $order->getCustomerFirstname();
		$customer_name .= ' ' . $order->getCustomerLastname();
		$order_amount = $order->getBaseCurrencyCode();
		$order_amount .= ' ' . $order->getBaseGrandTotal();

		$body = sprintf('%s: %s has just placed an order for %s', $store_name, $customer_name, $order_amount);

		// If no recipients have been set, we can't do anything
		if(!count($settings['order_noficication_recipients'])) {
			return;
		}

		// Send the order notification by SMS
		$result = Mage::helper('smsnotifications/data')->sendSms($body, $settings['order_noficication_recipients']);

		// Check if the sending was successful
		if(!$result) {
			// If an error occured, notify the administrator
			Mage::helper('smsnotifications/data')->sendAdminEmail(sprintf('%s was unable to send one or more order notifications to the specified number(s). Please check your configuration to make sure that your Twilio API settings are correct!', Mage::helper('smsnotifications/data')->app_name));
		}
	}

	// This method is called whenever a new shipment is created for an order
	public function salesOrderShipmentSaveAfter($observer)
	{
		// Get the settings
		$settings = Mage::helper('smsnotifications/data')->getSettings();

		// If no shipment notification has been specified, no notification can be sent
		if(!$settings['shipment_notification_message']) {
			return;
		}

		// Get the telephone # associated with the shipping (or billing) address
		$order = $observer->getEvent()->getShipment()->getOrder();
		$shippingAdress = $order->getShippingAddress();
		$telephoneNumber = trim($shippingAdress->getTelephone());

		// Check if a telephone number has been specified
		if($telephoneNumber) {
			// Send the shipment notification to the specified telephone number
			$result = Mage::helper('smsnotifications/data')->sendSms($settings['shipment_notification_message'], array($telephoneNumber));

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
		$settings = Mage::helper('smsnotifications/data')->getSettings();

		// If no recipients have been set, we can't do anything
		if(!count($settings['order_noficication_recipients'])) {
			return;
		}

		// Verify the settings by sending a test message
		$result = Mage::helper('smsnotifications/data')->sendSms('Congratulations, you have configured the extension correctly!', $settings['order_noficication_recipients']);

		// Display a success or error message
		if($result) {
			// If everything has worked, let the user know that a test message
			// has been sent to the recipients
			$recipients_string = implode(', ', $settings['order_noficication_recipients']);
			Mage::getSingleton('adminhtml/session')->addNotice(sprintf('A test message has been sent to the following recipient(s): %s. Please verify that all recipients received this test message. If not, correct the number(s) below.', $recipients_string));
		} else {
			Mage::getSingleton('adminhtml/session')->addError('Unable to send test message. Please verify that all your settings are correct and try again.');
		}
	}
}