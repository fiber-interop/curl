<?php

namespace fiber\curl;

/**
 * Runs curl transfers for fiber\curl\start().
 *
 * start() must begin the transfer before it returns and give back something fiber\await()
 * accepts (repeatable, by several awaiters) that resolves to what curl_exec($h) would return,
 * with curl_errno()/curl_error() on $h set as curl_exec() would leave them.
 */
interface Driver
{
    public function start(\CurlHandle $h): object;
}
