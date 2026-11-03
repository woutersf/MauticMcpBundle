<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

/**
 * Makes generated email and landing page HTML openable in the GrapesJS builder.
 *
 * The builder parses the stored HTML with DOMParser and rejects it when either side
 * of the document comes back empty:
 *
 *     if (!doc.body.innerHTML || !doc.head.innerHTML) {
 *         throw new Error('No valid HTML template found');
 *     }
 *
 * See GrapesJsBuilderBundle/Assets/library/js/preset-mautic/content.service.js.
 *
 * Server side rendering, the list view and the preview pane do not look at the head,
 * so a headless document looks perfectly correct everywhere except in the builder,
 * where it produces a white screen. An assistant writing email HTML through the API
 * almost never emits a <head>, so repair the document on the way in rather than rely
 * on every client remembering.
 *
 * Input that already carries a non-empty <head> is returned byte for byte unchanged.
 */
final class BuilderHtmlNormalizer
{
    private const DEFAULT_HEAD = '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">';

    /**
     * @param string $title Optional document title, normally the email subject.
     */
    public static function normalize(string $html, string $title = ''): string
    {
        $trimmed = trim($html);

        if ('' === $trimmed) {
            return $html;
        }

        $head  = self::DEFAULT_HEAD;
        $title = trim($title);

        if ('' !== $title) {
            $head .= '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';
        }

        // A head that already holds something is none of our business.
        if (preg_match('#<head\b[^>]*>(.*?)</head>#is', $trimmed, $matches)) {
            if ('' !== trim($matches[1])) {
                return $html;
            }

            return preg_replace(
                '#(<head\b[^>]*>)\s*(</head>)#is',
                '${1}' . $head . '${2}',
                $trimmed,
                1
            ) ?? $html;
        }

        // An <html> element with no head at all: open one right after it.
        if (preg_match('#<html\b[^>]*>#i', $trimmed)) {
            return preg_replace(
                '#(<html\b[^>]*>)#i',
                '${1}' . "\n" . '<head>' . $head . '</head>',
                $trimmed,
                1
            ) ?? $html;
        }

        // A bare fragment. Give it a document to live in, keeping any doctype it had.
        $doctype = '<!doctype html>';

        if (preg_match('#^<!doctype[^>]*>#i', $trimmed, $matches)) {
            $doctype = $matches[0];
            $trimmed = trim(substr($trimmed, strlen($matches[0])));
        }

        $body = preg_match('#<body\b#i', $trimmed)
            ? $trimmed
            : '<body>' . $trimmed . '</body>';

        return $doctype . "\n<html>\n<head>" . $head . "</head>\n" . $body . "\n</html>";
    }
}
