<?php

/**
 * fiber-interop/curl: cooperative curl_exec() on any fiber-interop adapter.
 */

namespace async\curl;

if (\function_exists('async\curl\exec')) {
    return;
}

/**
 * Same contract as curl_exec(): returns the body (CURLOPT_RETURNTRANSFER), true, or false, and
 * leaves curl_errno()/curl_error() set. Inside async\run() only the calling task waits; outside
 * run() this is a plain blocking curl_exec().
 */
function exec(\CurlHandle $h): string|bool
{
    if (\async\Registry::$active === []) {
        return \curl_exec($h);
    }
    return \async\await(start($h));
}

/**
 * Begin the transfer now and return an awaitable that resolves to what exec() would return.
 * Outside async\run() the transfer completes before start() returns (the awaitable is already
 * settled). A timed-out or cancelled await leaves the transfer running to completion.
 */
function start(\CurlHandle $h): object
{
    if (\async\Registry::$active === []) {
        $t = new Transfer();
        $t->resolve(\curl_exec($h));
        return $t;
    }
    return driver()->start($h);
}

/**
 * Install the driver. Same semantics as async\setup(): repeating it with a driver of the same
 * class is a no-op, a different class throws \LogicException.
 */
function setup(Driver $driver): void
{
    $current = Registry::$driver;
    if ($current === null) {
        Registry::$driver = $driver;
    } elseif (\get_class($current) !== \get_class($driver)) {
        throw new \LogicException(\sprintf(
            'async\curl driver %s is already set up; refusing %s.', \get_class($current), \get_class($driver)));
    }
}

/**
 * Offer a native driver; called by adapter packages from their Composer `files` autoload,
 * guarded by interface_exists(\async\curl\Driver::class, false). Nothing is instantiated here.
 *
 * @param callable(): Driver $factory
 */
function register(callable $factory, string $package): void
{
    Registry::$candidates[$package] = $factory;
}

/**
 * The driver in use: setup() wins; else the single registered candidate; else MultiDriver.
 * Two or more candidates without setup() throw \async\NotConfiguredException.
 */
function driver(): Driver
{
    if (Registry::$driver !== null) {
        return Registry::$driver;
    }
    $c = Registry::$candidates;
    if (\count($c) > 1) {
        throw new \async\NotConfiguredException('Several async\curl drivers are registered ('
            . \implode(', ', \array_keys($c)) . '); call async\curl\setup() with the one you want.');
    }
    return Registry::$driver = $c === [] ? new MultiDriver() : \current($c)();
}
