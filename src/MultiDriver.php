<?php

namespace fiber\curl;

/**
 * The generic driver: one curl_multi handle per process, polled by one fiber\go() task.
 *
 * PHP exposes no socket for a curl_multi handle, so fiber\readable() cannot be used; the poller
 * calls curl_multi_exec() and sleeps between rounds, 1 ms after activity backing off to 10 ms.
 *
 * The multi handle is created on the first start(), i.e. inside a task: with Swoole's native-curl
 * hook, curl_multi_init() returns a coroutine multi handle only when called in a coroutine, and
 * only those accept hooked easy handles. A coroutine multi handle is not tied to the coroutine
 * that created it (only curl_multi_select() binds one), so the poller task can drive it.
 */
final class MultiDriver implements Driver
{
    private ?\CurlMultiHandle $multi = null;
    /** @var array<int, array{\CurlHandle, Transfer}> */
    private array $transfers = [];
    private bool $polling = false;

    public function start(\CurlHandle $h): object
    {
        $t = new Transfer();
        $id = \spl_object_id($h);
        if (isset($this->transfers[$id])) {
            throw new \LogicException('This curl handle already has a transfer in progress');
        }
        $this->multi ??= \curl_multi_init();
        $this->transfers[$id] = [$h, $t];
        \curl_multi_add_handle($this->multi, $h);
        \curl_multi_exec($this->multi, $running);   // begin now: resolve DNS, connect
        $this->collect();
        if ($this->transfers !== [] && !$this->polling) {
            $this->polling = true;
            \fiber\go($this->poll(...));
        }
        return $t;
    }

    private function poll(): void
    {
        $interval = 0.001;
        try {
            while ($this->transfers !== []) {
                \fiber\sleep($interval);
                \curl_multi_exec($this->multi, $running);
                $interval = $this->collect() ? 0.001 : \min(0.010, $interval * 1.5);
            }
        } finally {
            $this->polling = false;
        }
    }

    /** Settle finished transfers; returns whether any finished. */
    private function collect(): bool
    {
        $any = false;
        while ($info = \curl_multi_info_read($this->multi)) {
            $h = $info['handle'];
            $id = \spl_object_id($h);
            [, $t] = $this->transfers[$id];
            unset($this->transfers[$id]);
            // curl_multi_info_read() already set curl_errno()/curl_error() on $h.
            $ok = $info['result'] === \CURLE_OK;
            $content = \curl_multi_getcontent($h);
            \curl_multi_remove_handle($this->multi, $h);
            $t->resolve(!$ok ? false : ($content ?? true));
            $any = true;
        }
        return $any;
    }
}
