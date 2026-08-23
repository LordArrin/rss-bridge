<?php

/**
 * Escape for html context
 */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Explicitly don't escape
 */
function raw(string $s): string
{
    return $s;
}

function truncate(string $s, int $length = 150, $marker = '...'): string
{
    $s = trim($s);
    if (mb_strlen($s) <= $length) {
        return $s;
    }
    return mb_substr($s, 0, $length) . $marker;
}

function html_input(array $attributes): string
{
    return html_tag('input', null, $attributes);
}

function html_option(string $name, $value, bool $selected = false): string
{
    return html_tag('option', $name, [
        'value'     => $value,
        'selected'  => $selected,
    ]);
}

function html_tag(
    string $name,
    ?string $content = null,
    array $attributes = []
): string {
    $strings = [
        'type',
        'id',
        'name',
        'value',
        'placeholder',
        'title',
        'pattern',
        'class',
        'for',
        'oncontextmenu',
        'data-for',
        'formtarget',
    ];
    $booleans = [
        'checked',
        'required',
        'selected',
    ];

    $s = "<$name";

    foreach ($attributes as $k => $v) {
        if (in_array($k, $strings)) {
            if ($v) {
                $s .= sprintf(' %s="%s"', $k, e($v));
            }
        } elseif (in_array($k, $booleans)) {
            if ($v === true) {
                $s .= sprintf(' %s', $k);
            }
        } else {
            throw new Exception(sprintf('Illegal html tag attribute: %s', $k));
        }
    }

    if ($content) {
        $s .= sprintf('>%s</%s>', e($content), $name);
    } else {
        $s .= ' />';
    }

    return $s;
}
