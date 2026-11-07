<?php

declare(strict_types=1);

namespace extensions\mail\transport;

use extensions\mail\Envelope;
use extensions\mail\MailTransport;
use extensions\mail\transport\smtp\Client;
use extensions\mail\transport\smtp\Config;
use Throwable;

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
            self::quit($client);
        } finally {
            $client->close();
        }
    }

    /**
     * The 250 after DATA committed the message. A server that hangs up instead of answering QUIT
     * must not turn it into a failed send: the caller would retry and deliver the message twice.
     */
    private static function quit(Client $client): void
    {
        try {
            $client->quit();
        } catch (Throwable) {
            return;
        }
    }
}
