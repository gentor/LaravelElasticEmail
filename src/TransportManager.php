<?php

namespace Gentor\LaravelElasticEmail;

use Illuminate\Mail\TransportManager as LaravelTransportManager;

/**
 * Class TransportManager
 * @package Gentor\LaravelElasticEmail
 */
class TransportManager extends LaravelTransportManager
{
    /**
     * @return ElasticTransport
     */
    protected function createElasticEmailDriver()
    {
        $config = $this->app['config']->get('services.elastic_email', []);

        return new ElasticTransport(
            $this->guzzle($config),
            $config['key'],
            $config['account']
        );
    }
}
