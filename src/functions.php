<?php

/**
 * fiber-interop/curl: cooperative curl_exec() on any fiber-interop adapter.
 */

namespace fiber\curl;

if (\function_exists('fiber\curl\exec')) {
    return;
}

/**
 * Same contract as curl_exec(): returns the body (CURLOPT_RETURNTRANSFER), true, or false, and
 * leaves curl_errno()/curl_error() set. Inside fiber\run() only the calling task waits; outside
 * run() this is a plain blocking curl_exec().
 */
function exec(\CurlHandle $h): string|bool
{
    if (\fiber\Registry::$active === []) {
        return \curl_exec($h);
    }
    return \fiber\await(start($h));
}

/**
 * Begin the transfer now and return an awaitable that resolves to what exec() would return.
 * Outside fiber\run() the transfer completes before start() returns (the awaitable is already
 * settled). A timed-out or cancelled await leaves the transfer running to completion.
 */
function start(\CurlHandle $h): object
{
    if (\fiber\Registry::$active === []) {
        $t = new Transfer();
        $t->resolve(\curl_exec($h));
        return $t;
    }
    return driver()->start($h);
}

/**
 * Install the driver. Same semantics as fiber\setup(): repeating it with a driver of the same
 * class is a no-op, a different class throws \LogicException.
 */
function setup(Driver $driver): void
{
    $current = Registry::$driver;
    if ($current === null) {
        Registry::$driver = $driver;
    } elseif (\get_class($current) !== \get_class($driver)) {
        throw new \LogicException(\sprintf(
            'fiber\curl driver %s is already set up; refusing %s.', \get_class($current), \get_class($driver)));
    }
}

/**
 * Offer a driver candidate: a thin wrapper over Registry::$candidates. Nothing is instantiated
 * here. Adapter packages do not call this from their Composer `files` autoload, because the order
 * in which packages' files load is not guaranteed; they write Registry::$candidates directly,
 * guarded by interface_exists(\fiber\curl\Driver::class) (autoload on). Applications and
 * packages loaded later may call register().
 *
 * @param callable(): Driver $factory
 */
function register(callable $factory, string $package): void
{
    Registry::$candidates[$package] = $factory;
}

/**
 * The driver in use: setup() wins; else the single registered candidate; else MultiDriver.
 * Two or more candidates without setup() throw \fiber\NotConfiguredException.
 */
function driver(): Driver
{
    if (Registry::$driver !== null) {
        return Registry::$driver;
    }
    $c = Registry::$candidates;
    if (\count($c) > 1) {
        throw new \fiber\NotConfiguredException('Several fiber\curl drivers are registered ('
            . \implode(', ', \array_keys($c)) . '); call fiber\curl\setup() with the one you want.');
    }
    return Registry::$driver = $c === [] ? new MultiDriver() : \current($c)();
}
