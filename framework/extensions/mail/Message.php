<?php

declare(strict_types=1);

namespace extensions\mail;

use finfo;
use InvalidArgumentException;

final class Message
{
    public string $subject = '';
    public string $html = '';
    public string $text = '';
    public ?string $fromEmail = null;
    public ?string $fromName = null;
    /** Set only by messageId(), which checks it. */
    public private(set) ?string $messageId = null;

    private bool $textExplicit = false;

    /** @var list<string> */
    public array $recipients = [];

    /** @var array<int|string, string> canonical integer names become int keys */
    public array $customHeaders = [];

    /** @var list<array{filename: string, encodedFilename: string, mimeType: string, data: string}> */
    public array $attachments = [];

    /** @param string|list<string> $to */
    public function to(string|array $to): self
    {
        $this->recipients = self::normalizeRecipients(self::recipientItems($to));

        return $this;
    }

    public function subject(string $subject): self
    {
        // Do not trim controls that header validation must reject.
        $this->subject = trim($subject, characters: " \t");

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        if (!$this->textExplicit) {
            $this->text = PlainText::fromHtml($html);
        }

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        $this->textExplicit = true;

        return $this;
    }

    public function from(string $email, ?string $name = null): self
    {
        $this->fromEmail = $email;
        $this->fromName = $name;

        return $this;
    }

    /**
     * Fixes the Message-ID instead of generating a random one, so a retry of the same send carries the same id
     * and the receiver can tell it from a second message.
     *
     * @param string $messageId "id@host", with or without angle brackets. A key with a colon in it is refused:
     *                          use hash('sha256', $key) . '@host'
     */
    public function messageId(string $messageId): self
    {
        $id = str_starts_with($messageId, '<') && str_ends_with($messageId, '>')
            ? substr($messageId, offset: 1, length: -1)
            : $messageId;

        HeaderRules::assertMessageId($id);

        $this->messageId = $id;

        return $this;
    }

    public function attach(string $name, string $data): self
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $this->attachments[] = self::buildAttachment($name, $data, $finfo);

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->customHeaders[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        Mail::send($this);
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private static function normalizeRecipients(array $items): array
    {
        $recipients = [];

        foreach ($items as $item) {
            if (trim($item) === '') {
                continue;
            }

            $address = trim(self::extractAddress($item));

            // Dropping one address would still send, just not to everyone the caller named.
            if (!Recipients::isAddress($address)) {
                throw new InvalidArgumentException('INVALID_RECIPIENT');
            }

            $recipients[] = $address;
        }

        if ($recipients === []) {
            throw new InvalidArgumentException('NO_VALID_RECIPIENT');
        }

        return array_values(array_unique($recipients));
    }

    /**
     * @param string|list<string> $to
     * @return list<string>
     */
    private static function recipientItems(string|array $to): array
    {
        if (!is_array($to)) {
            return RecipientSyntax::items($to, split: true);
        }

        // An array item is one recipient: a comma outside quotes in it refuses the call.
        return array_map(static fn(string $item): string => RecipientSyntax::items($item, split: false)[0], array_values($to));
    }

    private static function extractAddress(string $value): string
    {
        $match = [];

        // RFC 5322 name-addr: the angle-addr ends the item, so one inside the display name is not it.
        return preg_match('/<([^<>]+)>\s*$/D', $value, $match) === 1 ? trim($match[1]) : trim($value);
    }

    /** @return array{filename: string, encodedFilename: string, mimeType: string, data: string} */
    private static function buildAttachment(string $name, string $data, finfo $finfo): array
    {
        if ($name === '' || strlen($name) > AttachmentRules::MAX_FILENAME_BYTES) {
            throw new InvalidArgumentException('INVALID_ATTACHMENT');
        }

        $mimeType = $finfo->buffer($data);
        $normalized = (string)preg_replace(pattern: '/[\x00-\x1F\x7F]+/', replacement: '_', subject: $name);
        $fallback = (string)preg_replace(pattern: '/[^\x20-\x7E]/', replacement: '_', subject: $normalized);
        $fallback = substr(
            string: str_replace(search: ['\\', '"', ';'], replace: '_', subject: $fallback),
            offset: 0,
            length: 180,
        );

        return [
            'filename' => $fallback !== '' ? $fallback : 'attachment',
            'encodedFilename' => rawurlencode($normalized),
            'mimeType' => is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'data' => $data,
        ];
    }
}
