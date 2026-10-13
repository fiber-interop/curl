<?php

namespace async\curl\Tests;

use PHPUnit\Framework\TestCase;
use function async\{run, go, await, await_all};

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
        $this->assertSame('hello GET', \async\curl\exec(self::h('/')));
        $h = self::h('/status?code=404');
        $this->assertSame('status 404', \async\curl\exec($h));
        $this->assertSame(404, curl_getinfo($h, CURLINFO_HTTP_CODE));
    }

    public function testExecInsideRun(): void
    {
        $this->assertSame('hello GET', run(fn() => \async\curl\exec(self::h('/'))));
    }

    public function testOverlap(): void
    {
        $t0 = microtime(true);
        $r = run(fn() => await_all(array_map(fn() => go(fn() => \async\curl\exec(self::h('/slow?ms=200'))), range(1, 10))));
        $elapsed = microtime(true) - $t0;
        $this->assertSame(array_fill(0, 10, 'slow'), $r);
        $this->assertLessThan(0.6, $elapsed, "10 x 200 ms took {$elapsed}s");
    }

    public function testOtherTasksRunWhileWaiting(): void
    {
        $ticks = 0;
        run(function () use (&$ticks) {
            $ticker = go(function () use (&$ticks) {
                for ($i = 0; $i < 5; $i++) { \async\sleep(0.02); $ticks++; }
            });
            \async\curl\exec(self::h('/slow?ms=200'));
            await($ticker);
        });
        $this->assertSame(5, $ticks);
    }

    public function testStartIsEagerAndRepeatableAwait(): void
    {
        [$a, $b] = run(function () {
            $t = \async\curl\start(self::h('/slow?ms=100'));
            \async\sleep(0.15);      // transfer progresses meanwhile
            $t0 = microtime(true);
            $a = await($t);
            return [$a, await($t), microtime(true) - $t0];
        });
        $this->assertSame('slow', $a);
        $this->assertSame('slow', $b);
    }

    public function testErrorSetsErrnoAndHandleIsReusable(): void
    {
        $h = curl_init('http://127.0.0.1:1/');
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        $this->assertFalse(run(fn() => \async\curl\exec($h)));
        $this->assertSame(CURLE_COULDNT_CONNECT, curl_errno($h));
        $this->assertNotSame('', curl_error($h));
        curl_setopt($h, CURLOPT_URL, FI_BASE . '/');
        $this->assertSame('hello GET', run(fn() => \async\curl\exec($h)));
        $this->assertSame(0, curl_errno($h));
        $this->assertSame(200, curl_getinfo($h, CURLINFO_HTTP_CODE));
    }

    public function testHttpErrorStatusIsNotACurlError(): void
    {
        $h = self::h('/status?code=500');
        $this->assertSame('status 500', run(fn() => \async\curl\exec($h)));
        $this->assertSame(500, curl_getinfo($h, CURLINFO_HTTP_CODE));
    }

    public function testWithoutReturnTransferBodyIsEchoed(): void
    {
        ob_start();
        $r = run(fn() => \async\curl\exec(self::h('/', false)));
        $out = ob_get_clean();
        $this->assertTrue($r);
        $this->assertSame('hello GET', $out);
    }

    public function testTimedOutAwaitLeavesTransferRunning(): void
    {
        [$timedOut, $late] = run(function () {
            $t = \async\curl\start(self::h('/slow?ms=200'));
            try {
                await($t, 0.05);
                $timedOut = false;
            } catch (\async\TimeoutException) {
                $timedOut = true;
            }
            return [$timedOut, await($t)];
        });
        $this->assertTrue($timedOut);
        $this->assertSame('slow', $late);
    }
}
