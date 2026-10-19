<?php

namespace fiber\curl;

/** Driver state. Adapters write $candidates directly from their files autoload (it may run before fiber\curl\register() is defined). */
final class Registry
{
    public static ?Driver $driver = null;
    /** @var array<string, callable(): Driver> */
    public static array $candidates = [];
}
