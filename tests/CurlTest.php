<?php

namespace fiber\curl\Tests;

use PHPUnit\Framework\TestCase;
use function fiber\{run, go, await, await_all};

final class CurlTest extends TestCase
{
    private static function h(string $path, bool $return = true): \CurlHandle
    {
        $h = curl_init(FI_BASE . $path);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, $return);
        return $h;
    }

    public function testOutsideRunBehavesLikeCurlExec(): void
    {
        $this->assertSame('hello GET', \fiber\curl\exec(self::h('/')));
        $h = self::h('/status?code=404');
        $this->assertSame('status 404', \fiber\curl\exec($h));
        $this->assertSame(404, curl_getinfo($h, CURLINFO_HTTP_CODE));
    }

    public function testExecInsideRun(): void
    {
        $this->assertSame('hello GET', run(fn() => \fiber\curl\exec(self::h('/'))));
    }

    public function testOverlap(): void
    {
        $t0 = microtime(true);
        $r = run(fn() => await_all(array_map(fn() => go(fn() => \fiber\curl\exec(self::h('/slow?ms=200'))), range(1, 10))));
        $elapsed = microtime(true) - $t0;
        $this->assertSame(array_fill(0, 10, 'slow'), $r);
        $this->assertLessThan(1.5, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $ticks = 0;
        run(function () use (&$ticks) {
            $ticker = go(function () use (&$ticks) {
                for ($i = 0; $i < 5; $i++) { \fiber\sleep(0.02); $ticks++; }
            });
            \fiber\curl\exec(self::h('/slow?ms=200'));
            await($ticker);
        });
        $this->assertSame(5, $ticks);
    }

    public function testStartIsEagerAndRepeatableAwait(): void
    {
        [$a, $b] = run(function () {
            $t = \fiber\curl\start(self::h('/slow?ms=100'));
            \fiber\sleep(0.15);      // transfer progresses meanwhile
            $t0 = microtime(true);
            $a = await($t);
            return [$a, await($t), microtime(true) - $t0];
        });
        $this->assertSame('slow', $a);
        $this->assertSame('slow', $b);
    }

    // Handles are created inside run(): Swoole's native-curl hook only accepts CurlHandles that were
    // created in a coroutine (swoole_curl_get_handle() warns "not initialized in coroutine").
    public function testErrorSetsErrnoAndHandleIsReusable(): void
    {
        [$h, $first, $errno, $error, $second] = run(function () {
            $h = curl_init('http://127.0.0.1:1/');
            curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
            $first = \fiber\curl\exec($h);
            $errno = curl_errno($h);
            $error = curl_error($h);
            curl_setopt($h, CURLOPT_URL, FI_BASE . '/');
            return [$h, $first, $errno, $error, \fiber\curl\exec($h)];
        });
        $this->assertFalse($first);
        $this->assertSame(CURLE_COULDNT_CONNECT, $errno);
        $this->assertNotSame('', $error);
        $this->assertSame('hello GET', $second);
        $this->assertSame(0, curl_errno($h));
        $this->assertSame(200, curl_getinfo($h, CURLINFO_HTTP_CODE));
    }

    public function testHttpErrorStatusIsNotACurlError(): void
    {
        [$h, $body] = run(function () {
            $h = self::h('/status?code=500');
            return [$h, \fiber\curl\exec($h)];
        });
        $this->assertSame('status 500', $body);
        $this->assertSame(500, curl_getinfo($h, CURLINFO_HTTP_CODE));
    }

    public function testWithoutReturnTransferBodyIsEchoed(): void
    {
        ob_start();
        $r = run(fn() => \fiber\curl\exec(self::h('/', false)));
        $out = ob_get_clean();
        $this->assertTrue($r);
        $this->assertSame('hello GET', $out);
    }

    public function testTimedOutAwaitLeavesTransferRunning(): void
    {
        [$timedOut, $late] = run(function () {
            $t = \fiber\curl\start(self::h('/slow?ms=200'));
            try {
                await($t, 0.05);
                $timedOut = false;
            } catch (\fiber\TimeoutException) {
                $timedOut = true;
            }
            return [$timedOut, await($t)];
        });
        $this->assertTrue($timedOut);
        $this->assertSame('slow', $late);
    }
}
