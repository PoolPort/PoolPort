<?php

namespace PoolPort\IDPay;

use PoolPort\Exceptions\PoolPortException;

class IDPaySendException extends PoolPortException
{
    public static $errors = array(
        11 => 'کاربر مسدود شده است.',
        12 => 'API Key یافت نشد.',
        13 => 'درخواست شما از {ip} ارسال شده است. این IP با IP های ثبت شده در وب سرویس همخوانی ندارد.',
        14 => 'وب سرویس تایید نشده است.'
    );

    public function __construct($errorId)
    {
        parent::__construct(@self::$errors[$errorId].' #'.$errorId, $errorId);
    }
}
