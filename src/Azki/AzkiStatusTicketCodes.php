<?php

namespace PoolPort\Azki;

class AzkiStatusTicketCodes
{
    const CREATED      = 1;
    const VERIFIED     = 2;
    const REVERSED     = 3;
    const FAILED       = 4;
    const CANCELED     = 5;
    const SETTLED      = 6;
    const EXPIRED      = 7;
    const DONE         = 8;
    const SETTLE_QUEUE = 9;

    public static function getMessage(int $code)
    {
        return [
                   self::CREATED      => 'Created',
                   self::VERIFIED     => 'Verified',
                   self::REVERSED     => 'Reversed',
                   self::FAILED       => 'Failed',
                   self::CANCELED     => 'Canceled',
                   self::SETTLED      => 'Settled',
                   self::EXPIRED      => 'Expired',
                   self::DONE         => 'Done',
                   self::SETTLE_QUEUE => 'Settle Queue',
               ][$code];
    }
}