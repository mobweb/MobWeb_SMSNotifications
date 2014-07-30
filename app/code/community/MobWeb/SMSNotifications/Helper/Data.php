<?php

class MobWeb_SMSNotifications_Helper_Data extends Mage_Core_Helper_Abstract {

	public $app_name = 'MobWeb_SMSNotifications';

	// This method simply returns an array of all the extension specific settings
	public function getSettings()
	{
		// Create an empty array
		$settings = array();

		// Get the Twilio settings
		$settings['twilio_account_sid'] = Mage::getStoreConfig('smsnotifications/twilio_api_credentials/account_sid');
		$settings['twilio_auth_token'] = Mage::getStoreConfig('smsnotifications/twilio_api_credentials/auth_token');
		$settings['twilio_sender_number'] = Mage::getStoreConfig('smsnotifications/twilio_api_credentials/sender_number');

		// Get the general settings
		$settings['country_code_filter'] = Mage::getStoreConfig('smsnotifications/general/country_code_filter');

		// Get the order notification settings
		$settings['order_noficication_recipients'] = Mage::getStoreConfig('smsnotifications/order_notification/recipients');
		$settings['order_noficication_recipients'] = explode(';', $settings['order_noficication_recipients']);
		$settings['order_notification_status'] = Mage::getStoreConfig('smsnotifications/order_notification/order_status');

		// Get the shipment notification settings
		$settings['shipment_notification_message'] = Mage::getStoreConfig('smsnotifications/shipment_notification/message');

		// Return the settings
		return $settings;
	}

	// This method sends the specified message to the specified recipients
	public function sendSms($body, $recipients = array())
	{
		// Get the settings
		$settings = $this->getSettings();

		// If no recipients have been specified, don't do anything
		if(!count($recipients)) {
			return;
		}

		// Loop through the recipients and send each SMS separately
		$errors = array();
		foreach($recipients AS $recipient) {
			// Before working with the telephone number, do some guesswork to optimize the number format
			// Notice: These optimizations are specific to Swiss phone numbers. Add your own logic for your country
			// These are the strings that should be optimized, but only if they occur at the beginning of the number
			$optimizable = array('07', '00');
			$optimizableReplace = array('+417', '+');
			foreach($optimizable AS $optimizableString) {
				if(strpos($recipient, $optimizableString) === 0) {
					$this->log(sprintf('Optimizing %s...', $recipient));
					$recipient = str_replace($optimizable, $optimizableReplace, $recipient);
					$this->log(sprintf('.. result: %s', $recipient));
				}
			}

			// If a country code filter has been defined, check if the current telephone number matches against it
			if($telephoneNumberFilter = $settings['country_code_filter']) {
				$telephoneNumberIsAllowed = false;
				$telephoneNumberFilter = explode(',', $telephoneNumberFilter);
				foreach($telephoneNumberFilter AS $telephoneNumberFilterItem) {
					if(strpos($recipient, trim($telephoneNumberFilterItem)) === 0) {
						$telephoneNumberIsAllowed = true;
						break;
					}
				}

				// If the current telephone number is not in the list of allowed country codes, abort
				if(!$telephoneNumberIsAllowed) {
					$this->log(sprintf('Telephone number %s is not in list of allowed country codes: %s', $recipient, implode(',', $telephoneNumberFilter)));
					return false;
				}
			}

			// Send the request via CURL
			$ch = curl_init(sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/SMS/Messages.xml', $settings['twilio_account_sid']));
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Skip SSL certificate verification
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $settings['twilio_account_sid'], $settings['twilio_auth_token']));
			curl_setopt($ch, CURLOPT_POSTFIELDS, array(
				'From' => $settings['twilio_sender_number'],
				'To' => $recipient,
				'Body' => $body
			));

			// Execute the request
			curl_exec($ch);
			$response = curl_getinfo($ch);
			$response_code = $response['http_code'];

			// Check the response code. If it's not 201, an error has occured
			if($response_code !== 201) {
				$errors[] = array(
					$settings['twilio_account_sid'],
					$settings['twilio_auth_token'],
					$settings['twilio_sender_number'],
					$recipients,
					print_r($response, true)
				);
			}
		}

		// Check if any errors have occured
		if(count($errors)) {
			// Log the errors
			$this->log('Unable to send sms via twilio: ' . print_r($errors, true));
			return false;
		} else {
			$this->log('SMS sent: ' . print_r(array($body, $recipients), true));
			return true;
		}
	}

	// This method sends a notification email to the store's admin
	public function sendAdminEmail($body)
	{
		// Get the email settings from the store
		$store_name = Mage::app()->getStore()->getFrontendName();
		$general_contact_name = Mage::getStoreConfig('trans_email/ident_general/name');
		$general_contact_email = Mage::getStoreConfig('trans_email/ident_general/email');

		// Set the subject
		$subject = sprintf('%s: Notification from «%s»', $store_name, $this->app_name);

		// Create the mail object
		$mail = Mage::getModel('core/email');
		$mail->setToName($general_contact_name);
		$mail->setToEmail($general_contact_email);
		$mail->setBody($body);
		$mail->setSubject('=?utf-8?B?' . base64_encode($subject) . '?=');
		$mail->setFromEmail($general_contact_email);
		$mail->setFromName($this->app_name);
		$mail->setType('text');

		// Try sending the email
		try {
		    $mail->send();
		}
		catch (Exception $e) {
		    Mage::logException($e);
		    $this->log('unable to send email to admin: ' . print_r($e, true));
		}
	}

	// This method creates a log entry in the extension specific log file
	public function log($msg)
	{
		Mage::log($msg, null, 'mobweb_smsnotifications.log', true);
	}
}