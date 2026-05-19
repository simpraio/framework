<?php

declare(strict_types=1);

namespace extensions\mail;

interface MailTransport
{
    public function deliver(Envelope $envelope): void;
}
