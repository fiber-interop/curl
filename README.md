# fiber-interop/curl

Cooperative `curl_exec()` for [fiber-interop/api](https://github.com/fiber-interop/api): inside
`async\run()` a transfer suspends only the calling task, on whichever adapter is installed.

```
composer require fiber-interop/curl
```

```php
use function async\{run, go, await_all};

$bodies = run(fn() => await_all(array_map(
    fn($url) => go(function () use ($url) {
        $h = curl_init($url);
        curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        return async\curl\exec($h);          // same contract as curl_exec()
    }),
    ['https://example.com/a', 'https://example.com/b'],
)));
```

## API

- `async\curl\exec(\CurlHandle $h): string|bool` — exactly `curl_exec()`'s contract: the body with
  `CURLOPT_RETURNTRANSFER`, else `true` (curl echoes the body); `false` on failure, with
  `curl_errno()`/`curl_error()` set. The handle is reusable afterwards. Outside `async\run()` it is a
  plain blocking `curl_exec()`.
- `async\curl\start(\CurlHandle $h): object` — begins the transfer now and returns an awaitable
  (repeatable `async\await()`) resolving to what `exec()` returns. `exec()` is `await(start())`.
- `async\curl\Driver` — one method, `start(\CurlHandle): object`.
- `async\curl\setup(Driver)`, `async\curl\register(callable $factory, string $package)`,
  `async\curl\driver()` — same semantics as the api's adapter registry: `setup()` wins; a single
  registered candidate is instantiated lazily; two candidates without `setup()` throw
  `async\NotConfiguredException`; with none, the generic `MultiDriver` is used.

How adapters register: `register()` is a thin wrapper over `async\curl\Registry::$candidates`.
Adapters cannot call it from their Composer `files` autoload, since the order in which different
packages' files load is not guaranteed (and `interface_exists(..., false)` is always false for a
PSR-4 interface that has not been used yet). So each adapter's `register.php` does

```php
if (interface_exists(\async\curl\Driver::class)) {   // autoload on: true when fiber-interop/curl is installed
    \async\curl\Registry::$candidates['fiber-interop/phasync'] = static fn() => new CurlDriver();
}
```

guarded by its own runtime check (phasync: `extension_loaded('phasync')`; Swoole: native curl
hooking available). Your own code can call `async\curl\register()` at any point before the first
transfer.

## How it waits: polling, honestly

`MultiDriver` keeps one `curl_multi` handle per process. `start()` adds the handle and calls
`curl_multi_exec()` once, so the transfer begins immediately. One poller task loops
`curl_multi_exec()` / `curl_multi_info_read()` and sleeps with `async\sleep()` between rounds:
1 ms after a round in which something completed, backing off ×1.5 toward 10 ms while nothing does.
It stops when the multi is empty and is restarted by the next `start()`.

This is polling. PHP exposes no file descriptor for a `curl_multi` handle, so `async\readable()`
cannot be used. What would remove it is a php-src change exposing libcurl's socket callbacks
(`CURLMOPT_SOCKETFUNCTION` / `CURLMOPT_TIMERFUNCTION`) or a selectable fd for `curl_multi`, so the
event loop could watch curl's sockets directly. Native drivers below avoid the polling.

The poller is started with `async\go()`, not `async\service()`: on Revolt (and AMPHP) a service's
watchers are unreferenced, so a task awaiting a transfer driven only by a service is seen as a
deadlock ("Event loop terminated without resuming the current suspension"). A `go()` task keeps the
loop alive exactly while transfers exist and exits when the multi is empty, so it never extends
`run()` beyond the transfers it serves.

## Native drivers

| Runtime | Driver |
|---|---|
| phasync with the phasync extension loaded | `FiberInterop\Phasync\CurlDriver` (`go(fn() => curl_exec($h))`; the extension makes `curl_exec()` cooperative) |
| Swoole built with native curl (`SWOOLE_HOOK_NATIVE_CURL` in `SWOOLE_HOOK_ALL`) | `FiberInterop\Swoole\CurlDriver` |
| Revolt, AMPHP, ReactPHP, phasync without the extension, Swoole without native curl | `MultiDriver` |

## Limits

- A timed-out or cancelled `await()` does not abort the transfer; it runs to completion and its
  result is discarded unless awaited again.
- Without `CURLOPT_RETURNTRANSFER`, curl writes the body from inside `curl_multi_exec()`, i.e. in the
  poller task, not the caller's. It reaches the same output buffer stack, but not a buffer that only
  the calling task has open at that moment.
- Swoole without native curl: the legacy `SWOOLE_HOOK_CURL` replaces curl inside coroutines and
  combines badly with `curl_multi`; see the test notes in the repository.

## Tests

The suite starts a local `php -S` fixture (16 workers), so no network is needed.
`FI_RUNTIME=revolt|amp|react|phasync|swoole` picks the adapter, `FI_DRIVER=generic|native` the driver:

```
FI_RUNTIME=amp vendor/bin/phpunit
FI_RUNTIME=phasync FI_DRIVER=native php -d extension=phasync.so vendor/bin/phpunit
```
