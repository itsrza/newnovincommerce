<?php

namespace Novinwp\Novin_Commerce\Admin;

use Novinwp\Novin_Commerce\Plugin;

class Order_Menu extends Item_Menu {
	protected $name = 'order';
	protected $plural = 'orders';
	protected $singular = 'order';
	protected $_plural = 'فاکتورها';
	protected $_singular = 'فاکتور';
	protected $_description = 'فاکتورهای فروش سایت و وضعیت ارسال آن‌ها به نرم‌افزار حسابداری.';
}
