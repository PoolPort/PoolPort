<?php

namespace PoolPort\Payline;

use PoolPort\Exceptions\PoolPortException;

class PaylineReceiveException extends PoolPortException
{
    public static $errors = array(
        -1 => 'api ارسالی با نوع api تعریف شده در payline سازگار نیست.',
        -2 => 'trans_id ارسال شده معتبر نمی‌باشد.',
        -3 => 'id_get ارسالی معتبر نمی باشد.',
        -4 => 'چنین تراکنشی در سیستم وجود ندارد و یا موفقیت آمیز نبوده است.'
    );

    public function __construct($errorId)
    {
        parent::__construct(@self::$errors[$errorId].' #'.$errorId, $errorId);
    }
}
