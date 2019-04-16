<?php

namespace PoolPort\BitPay;

class BitPaySendException extends \Exception
{
	public static $errors = array(
		-1 => 'api ارسالی با نوع api تعریف شده در bitpay سازگاری ندارد.',
		-2 => 'مقدار amount داده عددی نمی باشد و یا کمتر از 1000 ریال است',
		-3 => 'مقدار redirect رشته null است',
		-4 => 'درگاهی با اطلاعات ارسالی شما وجود ندارد و یا در حالت انتظار می باشد',
		-5 => 'خطا در اتصال به درگاه، لطفا مجددا تلاش کنید',
	);

	public function __construct($errorId)
	{
		$this->errorId = intval($errorId);

		parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
	}
}
