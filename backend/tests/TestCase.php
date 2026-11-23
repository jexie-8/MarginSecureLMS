<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * In production every request is a fresh process. Inside one test several
     * requests share one application, so the Sanctum guard would remember the
     * first user it resolved and the default guard would stay on "sanctum".
     * Reset both after each request so tests behave like separate requests.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app['auth']->guard('sanctum')->forgetUser();
            $this->app['auth']->shouldUse('web');
        }
    }
}
