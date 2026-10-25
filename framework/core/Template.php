<?php

declare(strict_types=1);

namespace core;

use core\template\Block;
use core\tools\Format;

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
        // Escape template markers as well as HTML so user values cannot inject blocks or tokens.
            $replace[] = str_replace(
                search: ['{', '}'],
                replace: ['&#123;', '&#125;'],
                subject: Format::escape($value, trim: false),
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
            $this->content = Block::apply($this->content, strval($name), $show);
        }
        return $this;
    }

    /**
     * Parallel token and block arrays use the same row index.
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
