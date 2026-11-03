<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Tests\Unit\Service;

use MauticPlugin\MauticMcpBundle\Service\BuilderHtmlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuilderHtmlNormalizerTest extends TestCase
{
    /**
     * Mirrors the check in the GrapesJS builder, which parses the stored HTML and throws
     * "No valid HTML template found" when either side of the document is empty:
     *
     *     if (!doc.body.innerHTML || !doc.head.innerHTML)
     *
     * See GrapesJsBuilderBundle/Assets/library/js/preset-mautic/content.service.js.
     */
    private static function builderAccepts(string $html): bool
    {
        $document = new \DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return '' !== self::innerHtml($document, 'head') && '' !== self::innerHtml($document, 'body');
    }

    private static function innerHtml(\DOMDocument $document, string $tag): string
    {
        $node = $document->getElementsByTagName($tag)->item(0);

        if (null === $node) {
            return '';
        }

        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unopenableProvider(): array
    {
        return [
            'html element without a head' => ["<!doctype html>\n<html><body style=\"margin:0\"><p>Hi</p></body></html>"],
            'bare fragment'               => ['<div style="padding:10px">Hi {contactfield=firstname}</div>'],
            'doctype then a fragment'     => ["<!doctype html>\n<div>Hello</div>"],
            'empty head element'          => ['<!doctype html><html><head></head><body><p>x</p></body></html>'],
            'head holding only space'     => ["<html><head>\n   \n</head><body><p>x</p></body></html>"],
            'body without an html parent' => ['<body><p>loose body</p></body>'],
        ];
    }

    #[DataProvider('unopenableProvider')]
    public function testItRepairsDocumentsTheBuilderWouldReject(string $html): void
    {
        self::assertFalse(self::builderAccepts($html), 'This fixture is supposed to start out broken.');
        self::assertTrue(self::builderAccepts(BuilderHtmlNormalizer::normalize($html)));
    }

    public function testItLeavesACompleteDocumentByteForByte(): void
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Keep me</title></head><body><p>x</p></body></html>';

        self::assertSame($html, BuilderHtmlNormalizer::normalize($html, 'Some other subject'));
    }

    public function testItLeavesAnEmptyBodyAlone(): void
    {
        self::assertSame('', BuilderHtmlNormalizer::normalize(''));
        self::assertSame('   ', BuilderHtmlNormalizer::normalize('   '));
    }

    public function testItKeepsTokensAndMultibyteContent(): void
    {
        $html   = "<!doctype html>\n<html><body><p>\u{1F384} {contactfield=firstname}</p></body></html>";
        $result = BuilderHtmlNormalizer::normalize($html, 'Season greetings');

        self::assertStringContainsString("\u{1F384}", $result);
        self::assertStringContainsString('{contactfield=firstname}', $result);
    }

    public function testItUsesTheTitleAndEscapesIt(): void
    {
        $result = BuilderHtmlNormalizer::normalize('<p>x</p>', 'Greetings & "2026"');

        self::assertStringContainsString('<title>Greetings &amp; &quot;2026&quot;</title>', $result);
    }

    public function testItOmitsTheTitleElementWhenNoTitleIsGiven(): void
    {
        $result = BuilderHtmlNormalizer::normalize('<p>x</p>');

        self::assertStringNotContainsString('<title>', $result);
        self::assertStringContainsString('<meta charset="utf-8">', $result);
        self::assertTrue(self::builderAccepts($result));
    }

    public function testItDoesNotNestASecondBodyElement(): void
    {
        $result = BuilderHtmlNormalizer::normalize('<body><p>loose body</p></body>');

        self::assertSame(1, substr_count(strtolower($result), '<body'));
    }
}
