<?php

namespace Code\Lib;

class Api_router
{

    private static array $routes = [];

    public static function register($path, $fn, $auth_required): void
    {
        self::$routes[$path] = ['func' => $fn, 'auth' => $auth_required];
    }

    public static function find($path)
    {
        if (array_key_exists($path, self::$routes)) {
            return self::$routes[$path];
        }

        $with_params = dirname($path) . '/[id]';

        if (array_key_exists($with_params, self::$routes)) {
            return self::$routes[$with_params];
        }

        return null;
    }

    /** @noinspection PhpUnused */
    public static function dbg(): array
    {
        return self::$routes;
    }
}
