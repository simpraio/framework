<?php

declare(strict_types=1);

namespace core;

final class Template
{
    public function __construct(private string $content = '')
    {
    }

    /** @param array<string, string> $tokens */
    public function tokens(array $tokens): Template
    {
        if ($tokens === []) {
            return $this;
        }
        $search = [];
        $replace = [];
        foreach ($tokens as $key => $value) {
            $search[] = '{' . $key . '}';
            // Escape both HTML grammar (<, >, &, ", ') and template grammar
            // ({, }) so a user value can never form a token or block marker
            // in the rendered output. Browsers decode &#123;/&#125; back to
            // { and } visually - end users see the original characters.
            // Use rawTokens() for content that legitimately contains braces.
            $replace[] = str_replace(
                search: ['{', '}'],
                replace: ['&#123;', '&#125;'],
                subject: htmlspecialchars($value, flags: ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8'),
            );
        }
        $this->content = str_replace($search, $replace, $this->content);
        return $this;
    }

    /** @param array<string, string> $tokens */
    public function rawTokens(array $tokens): Template
    {
        if ($tokens === []) {
            return $this;
        }
        $search = [];
        $replace = [];
        foreach ($tokens as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }
        $this->content = str_replace($search, $replace, $this->content);
        return $this;
    }

    /** @param array<string, bool|string> $blocks */
    public function blocks(array $blocks): Template
    {
        foreach ($blocks as $name => $visible) {
            $show = $visible === true || $visible === 'show' || $visible === 'true';
            $pattern = '/\{' . preg_quote($name, delimiter: '/') . '\}(.*?)\{-' . preg_quote(
                    $name,
                    delimiter: '/'
                ) . '\}/s';
            $this->content = preg_replace_callback(
                $pattern,
                static fn(array $m): string => $show ? $m[1] : '',
                $this->content
            ) ?? $this->content;
        }
        return $this;
    }

    /**
     * Renders the template repeatedly for each row and returns the concatenated result.
     * Each entry in $rows is a flat token array (escaped). Optional parallel arrays
     * $rawRows and $blockRows are keyed by the same index for raw tokens and block flags.
     *
     * @param list<array<string, string>> $rows
     * @param list<array<string, string>> $rawRows
     * @param list<array<string, bool|string>> $blockRows
     */
    public function renderRows(array $rows, array $rawRows = [], array $blockRows = []): string
    {
        if (!$rows) {
            return '';
        }

        $source = $this->content;
        $result = '';

        foreach ($rows as $key => $tokens) {
            $tpl = new self($source);

            $tpl->tokens($tokens);
            if (is_array($rawRows[$key] ?? null)) {
                $tpl->rawTokens($rawRows[$key]);
            }
            if (is_array($blockRows[$key] ?? null)) {
                $tpl->blocks($blockRows[$key]);
            }

            $result .= $tpl->render();
        }

        return $result;
    }

    public function render(): string
    {
        return $this->content;
    }
}
