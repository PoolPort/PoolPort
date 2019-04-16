<?php

namespace PoolPort\BitPay;

class BitPayReceiveException extends \Exception
{
	public static $errors = array(
		-1 => 'api ارسالی با نوع api تعریف شده در bitpay سازگاری ندارد.',
		-2 => 'trans_id ارسال شده داده عددی نمی باشد',
		-3 => 'id_get ارسال شده داده عددی نمی باشد',
		-4 => 'چنین تراکنشی در پایگاه داده وجود ندارد و یا موفقیت آمیز نبوده است',
	);

	public function __construct($errorId)
	{
		$this->errorId = intval($errorId);

		parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
	}
}
