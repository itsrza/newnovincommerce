<?php

namespace MobinDev\Novin_Commerce\Digits\SmsGateways;

/**
 * Common contract every SMS gateway integration must follow.
 *
 * Keeping this interface small and generic (send + settings-field
 * definitions) means adding a new provider later is just a new class
 * implementing this interface plus one line in Gateway_Registry — no
 * changes needed anywhere else in the Digits module.
 */
interface Sms_Gateway_Interface {

	/**
	 * Unique, stable slug for this gateway (used as the stored option value).
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human-readable label shown in the gateway dropdown.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Describe the settings fields this gateway needs, so the settings
	 * page can render them generically without knowing gateway internals.
	 *
	 * Each field: [
	 *   'key'         => string (stored under digits_sms_settings[gateway_slug][key]),
	 *   'label'       => string,
	 *   'type'        => 'text'|'password',
	 *   'default'     => string,
	 *   'description' => string,
	 * ]
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_settings_fields();

	/**
	 * Send an SMS message.
	 *
	 * @param string               $to       Destination mobile number (digits only, with country code already applied by caller).
	 * @param string               $message  The message text (already rendered from the template, plain text — not URL-encoded).
	 * @param array<string,string> $settings This gateway's saved settings (already merged with defaults).
	 *
	 * @return array{success:bool,message:string,raw:string} Result of the send attempt; 'raw' holds the unmodified provider response for logging.
	 */
	public function send( $to, $message, array $settings );
}
