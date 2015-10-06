<?php

namespace IPay;

abstract class IPayAbstract
{
    /**
     * Status code for status field in ipay_transactions table
     */
    const TRANSACTION_INIT = 0;

    /**
     * Status code for status field in ipay_transactions table
     */
    const TRANSACTION_SUCCEED = 1;

    /**
     * Transaction succeed text for put in log
     */
    const TRANSACTION_SUCCEED_TEXT = 'پرداخت با موفقیت انجام شد.';

    /**
     * Status code for status field in ipay_transactions table
     */
    const TRANSACTION_FAILED = 2;

    /**
     * Get port id, $this->portId
     *
     * @return int
     */
    public function portId()
    {
        return $this->portId;
    }

    /**
     * Add query string to a url
     *
     * @return string
     */
    protected function buildQuery($url, array $query)
    {
        $query = http_build_query($query);

        $questionMark = strpos($url, '?');
        if (!$questionMark)
            return "$url?$query";
        else {
            return substr($url, 0, $questionMark + 1).$query."&".substr($url, $questionMark + 1);
        }
    }
}
