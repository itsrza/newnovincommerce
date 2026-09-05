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

		foreach ($options as $name => $value) {
			self::set($name, $value);
		}

		return $instance->save();
	}

	private function save()
	{
		return update_option(self::PREFIX, $this->options);
	}
}
