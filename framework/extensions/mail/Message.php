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

    private bool $textExplicit = false;

    /** @var list<string> */
    public array $recipients = [];

    /** @var array<string, string> */
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
        $this->subject = trim($subject);

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        if (!$this->textExplicit) {
            $this->text = trim(html_entity_decode(strip_tags($html), flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'));
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
            $address = trim(self::extractAddress($item));

            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = $address;
            }
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
        return is_array($to) ? array_values($to) : self::splitRecipients($to);
    }

    /** @return list<string> */
    private static function splitRecipients(string $value): array
    {
        $items = [];
        $current = '';
        $inQuotes = false;
        $angleDepth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $prev = $i > 0 ? $value[$i - 1] : null;

            if ($char === '"' && $prev !== '\\') {
                $inQuotes = !$inQuotes;
                $current .= $char;
                continue;
            }

            if (!$inQuotes) {
                if ($char === '<') {
                    $angleDepth++;
                    $current .= $char;
                    continue;
                }

                if ($char === '>' && $angleDepth > 0) {
                    $angleDepth--;
                    $current .= $char;
                    continue;
                }

                if ($char === ',' && $angleDepth === 0) {
                    $items[] = $current;
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        $items[] = $current;

        return $items;
    }

    private static function extractAddress(string $value): string
    {
        $match = [];

        return preg_match('/<([^>]+)>/', $value, $match) === 1 ? trim($match[1]) : trim($value);
    }

    /** @return array{filename: string, encodedFilename: string, mimeType: string, data: string} */
    private static function buildAttachment(string $name, string $data, finfo $finfo): array
    {
        if ($name === '') {
            throw new InvalidArgumentException('INVALID_ATTACHMENT');
        }

        $mimeType = $finfo->buffer($data);
        $sanitized = str_replace(['\\', '"', ';', "\r", "\n"], ['\\\\', '\"', '', '', ''], $name);

        return [
            'filename' => $sanitized,
            'encodedFilename' => rawurlencode($name),
            'mimeType' => is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'data' => $data,
        ];
    }
}
