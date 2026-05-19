<?php

declare(strict_types=1);

namespace extensions\mail\transport;

use extensions\mail\Envelope;
use extensions\mail\MailTransport;
use extensions\mail\transport\smtp\Client;
use extensions\mail\transport\smtp\Config;

final readonly class Smtp implements MailTransport
{
    public function __construct(private Config $config)
    {
    }

    public function deliver(Envelope $envelope): void
    {
        $client = Client::connect($this->config);

        try {
            $client->hello();
            $client->startTlsIfNeeded();
            $client->authenticateIfNeeded();
            $client->send($envelope);
            $client->quit();
        } finally {
            $client->close();
        }
    }
}
