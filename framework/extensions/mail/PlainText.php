<?php

declare(strict_types=1);

namespace extensions\mail;

/**
 * The text alternative of an HTML body: tags and comments removed, entities decoded, style, script and title
 * left out, a line for each block, and web and mail links spelled out as "text (url)".
 *
 * Tags are read with HtmlTag's rule that a quote opens a value only straight after "=", so `class=a'b` cannot
 * swallow the text after the tag as it does in strip_tags(). Every scan moves forward, so the time taken grows
 * with the body, whatever its markup.
 */
final class PlainText
{
    /** What may follow a "<" that starts markup. */
    private const string TAG_START = HtmlTag::LETTERS . '/!?';

    /** Elements whose content is never shown. */
    private const array HIDDEN = ['script', 'style', 'title'];

    /** Elements that start a new line, opening or closing. */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table',
        'tr', 'ul',
    ];

    private string $text = '';

    /** The open link's text, null when no link is open. */
    private ?string $linkText = null;

    private string $url = '';

    private function __construct(
        private readonly string $html,
    ) {}

    public static function fromHtml(string $html): string
    {
        // An invalid UTF-8 sequence is U+FFFD, as in a browser, whatever mbstring's substitute character is:
        // htmlspecialchars() substitutes it, and decoding the three entities it wrote gives the rest back.
        if (!mb_check_encoding($html, encoding: 'UTF-8')) {
            $html = htmlspecialchars_decode(htmlspecialchars($html, flags: ENT_NOQUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8'), flags: ENT_NOQUOTES);
        }

        return new self($html)->read();
    }

    private function read(): string
    {
        // Text before $at is written; the next "<" is looked for from $from.
        $at = 0;
        $from = 0;
        while (($lt = strpos($this->html, needle: '<', offset: $from)) !== false) {
            $markup = strspn($this->html, self::TAG_START, $lt + 1, length: 1) === 1 ? $this->markup($lt) : null;
            if ($markup === null) {
                // A "<" that starts no markup is text, as in `a <3 b`.
                $from = $lt + 1;
                continue;
            }
            $this->write(substr($this->html, $at, $lt - $at));
            [$end, $name, $closing, $href] = $markup;
            $at = $end === false ? strlen($this->html) : $this->tag($name, $closing, $href, $end + 1);
            $from = $at;
        }
        $this->write(substr($this->html, $at));
        $this->closeLink();

        return self::tidy($this->text);
    }

    /**
     * The markup starting at the "<" at $lt: the > that ends it (false when the body ends first), the lowercased
     * tag name ('' for a comment or declaration), whether it closes, and its href; null for a "</" that ends the body.
     *
     * @return array{0: int|false, 1: string, 2: bool, 3: string|null}|null
     */
    private function markup(int $lt): ?array
    {
        $closing = $this->html[$lt + 1] === '/';
        $nameStart = $lt + ($closing ? 2 : 1);
        if (HtmlTag::isLetter($this->html, $nameStart)) {
            $nameEnd = $nameStart + strcspn($this->html, HtmlTag::SPACE . '/>', $nameStart);
            $href = null;
            $end = HtmlTag::end($this->html, $nameEnd, $href);

            return [$end, strtolower(substr($this->html, $nameStart, $nameEnd - $nameStart)), $closing, $href];
        }
        if (substr($this->html, $lt + 1, length: 3) === '!--') {
            return [HtmlComment::end($this->html, $lt + 4), '', false, null];
        }

        // <!...>, <?...> and </ followed by anything but a letter end at the next >.
        return $closing && $nameStart === strlen($this->html) ? null : [strpos($this->html, needle: '>', offset: $lt + 2), '', false, null];
    }

    /** Acts on the tag that ended just before $after and returns where the text resumes. */
    private function tag(string $name, bool $closing, ?string $href, int $after): int
    {
        if (!$closing && in_array($name, self::HIDDEN, strict: true)) {
            // A hidden element never closed hides the rest of the body, as in a browser.
            $closerEnd = HtmlTag::closer($this->html, $name, $after);

            return $closerEnd === false ? strlen($this->html) : $closerEnd + 1;
        }
        if ($name === 'a') {
            // A link ends at its closing tag or where the next one starts.
            $this->closeLink();
            $url = $closing ? null : HtmlTag::address($href);
            if ($url !== null) {
                $this->linkText = '';
                $this->url = $url;
            }
        } elseif (in_array($name, self::BLOCKS, strict: true)) {
            $this->append("\n");
        } elseif ($name === 'td' || $name === 'th') {
            $this->append(' ');
        }

        return $after;
    }

    /**
     * Text between tags: its entities decoded where it stands, and any line break in it only a space.
     * A carriage return reference is one too; DOMDocument writes every CR as one.
     */
    private function write(string $text): void
    {
        $text = html_entity_decode(str_ireplace(HtmlTag::CR_REFERENCES, replace: ' ', subject: $text), flags: ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
        $this->append(strtr($text, from: "\n\r\t\f", to: '    '));
    }

    /** Adds to the open link's text, or to the text when no link is open. */
    private function append(string $text): void
    {
        if ($this->linkText === null) {
            $this->text .= $text;
        } else {
            $this->linkText .= $text;
        }
    }

    /**
     * Spells out the open link as "text (url)", the address alone when the link has no text or its text is the
     * address. An address shown alone is set apart by spaces, so the text after it cannot extend it into another.
     */
    private function closeLink(): void
    {
        if ($this->linkText === null) {
            return;
        }
        // At either end a no-break space separates like a space, and link text of nothing else, as spacer markup
        // leaves, is none. Two spaces for its two bytes keep every offset in $edges an offset in the text.
        $edges = str_replace("\u{A0}", replace: '  ', subject: $this->linkText);
        $start = strspn($edges, characters: " \n");
        $end = max($start, strlen(rtrim($edges, characters: " \n")));
        $shown = substr($this->linkText, $start, $end - $start);
        $before = substr($edges, offset: 0, length: $start);
        $after = substr($edges, $end);
        $this->text .= $before . match ($shown) {
            '', $this->url => ' ' . ($shown === '' ? $this->url : $shown) . ' ',
            default => $shown . ' (' . $this->url . ')',
        } . $after;
        $this->linkText = null;
    }

    /**
     * One space between words, no space at either end of a line, and no line of only spaces, no-break ones
     * included. Read line by line in one forward pass each, so time and memory stay in proportion to the
     * text: a word or a line never becomes an array element.
     */
    private static function tidy(string $text): string
    {
        $tidy = '';
        $text .= "\n";
        for ($at = 0; ($end = strpos($text, needle: "\n", offset: $at)) !== false; $at = $end + 1) {
            $line = trim(self::oneSpace(substr($text, $at, $end - $at)), characters: ' ');
            if (trim(str_replace("\u{A0}", replace: ' ', subject: $line), characters: ' ') !== '') {
                $tidy .= "\n" . $line;
            }
        }

        return substr($tidy, offset: 1);
    }

    /** Each run of spaces as one, in a single forward pass and with no pattern, which a spent backtrack limit empties. */
    private static function oneSpace(string $line): string
    {
        $one = '';
        for ($at = 0; ($run = strpos($line, needle: '  ', offset: $at)) !== false; $at = $run + strspn($line, characters: ' ', offset: $run)) {
            $one .= substr($line, $at, $run - $at + 1);
        }

        return $one . substr($line, $at);
    }
}
