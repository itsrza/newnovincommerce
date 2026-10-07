<?php


namespace MobinDev\Novin_Commerce\Common;


class SettingAPI
{
	const PREFIX = 'novin_commerce_settings';
	private static $instance = null;
	private $options = [];

	public function __construct()
	{
		$this->options = get_option(self::PREFIX);
		if (!$this->options) {
			$this->options = [];
		}
	}

	private static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function get($name, $default = false)
	{
		$instance = self::getInstance();

		return $instance->options[$name] ?? $default;
	}

	public static function set($name, $value)
	{
		$instance = self::getInstance();

		$instance->options[$name] = $value;

		return $instance->save();
	}

	public static function getAll()
	{
		$instance = self::getInstance();

		return $instance->options;
	}

	public static function setAll($options)
	{
		$instance = self::getInstance();

		if (!is_array($options)) {
			return false;
		}

		// Merge once and write once. Settings pages use this method when their
		// fields are spread across several client-side tabs; saving one tab
		// must not overwrite values from another tab or cause a sequence of
		// partially-written options.
		$instance->options = array_merge($instance->options, $options);

		return $instance->save();
	}

	/**
	 * Read a sensitive setting through the shared encrypted-value helper.
	 * Legacy unencrypted values remain readable and are migrated the next
	 * time the administrator saves the field.
	 *
	 * @param string $name Setting name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function getSecret($name, $default = '')
	{
		$value = self::get($name, $default);
		if (class_exists('\\MobinDev\\Novin_Commerce\\Digits\\Common\\Secret_Crypt')) {
			$decrypted = \MobinDev\Novin_Commerce\Digits\Common\Secret_Crypt::decrypt((string) $value);
			return '' === $decrypted && '' !== (string) $value ? $default : $decrypted;
		}

		return $value;
	}

	/**
	 * Encrypt and save a sensitive setting while retaining the old setting key.
	 *
	 * @param string $name Setting name.
	 * @param mixed  $value Plain-text value.
	 * @return bool
	 */
	public static function setSecret($name, $value)
	{
		if (class_exists('\\MobinDev\\Novin_Commerce\\Digits\\Common\\Secret_Crypt')) {
			$value = \MobinDev\Novin_Commerce\Digits\Common\Secret_Crypt::encrypt((string) $value);
		}

		return self::set($name, $value);
	}

	private function save()
	{
		return update_option(self::PREFIX, $this->options);
	}
}
