<?php

namespace PoolPort\ResalatWallet;

use PoolPort\Exceptions\PoolPortException;

class ResalatWalletException extends PoolPortException
{
    const ERROR_CODE_NOT_ENOUGH_CREDIT = 10309;
    const ERROR_CODE_USER_NOT_FOUND = 10291;

    const ERROR_MESSAGE_UNKNOWN = 'خطای ناشناخته رخ داده است.';

    public static $errors = array(
        '0'     => 'عملیات با موفقیت انجام شد',
        '10291' => 'برای این شماره موبایل کیف پول رسالت یافت نشد.',
        '10002' => 'مشخصات کیف پول یافت نشد.',
        '10277' => 'پذیرنده یافت نشد.',
        '10535' => 'جیب با قابلیت خرید یافت نشد',
        '10309' => 'موجودی کیف پول رسالت شما کافی نیست.',
        '10007' => 'ترمینال یافت نشد.',
        '10254' => 'HostKeyدر سامانه تعریف نشده است .',
    );

    public function __construct($action)
    {
        parent::__construct(self::getError($action));
    }

    public static function getError($action)
    {
        if (isset(self::$errors[$action])) {
            return self::$errors[$action];
        }

        return self::ERROR_MESSAGE_UNKNOWN;
    }
}
