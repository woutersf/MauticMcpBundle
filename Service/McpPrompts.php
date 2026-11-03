<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\Service;

/**
 * Ready made prompts an MCP client can offer as slash commands.
 */
class McpPrompts
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'contact_briefing' => [
                'title'       => 'Brief me on a contact',
                'description' => 'Pull everything Mautic knows about one person and summarise it before a call.',
                'arguments'   => [
                    ['name' => 'contact', 'description' => 'Email address or name of the contact.', 'required' => true],
                ],
                'template' => <<<'TEXT'
                    Find the Mautic contact matching "{contact}".

                    Then build a short briefing:
                    1. Who they are: name, company, job title, country, owner.
                    2. Engagement: point score, stage, the segments and campaigns they are in.
                    3. Recent behaviour: the last ten timeline events, grouped by what they mean.
                    4. Two or three things worth saying on a call, based on the above.

                    Keep it under 250 words. Say plainly when a piece of information is missing
                    rather than guessing.
                    TEXT,
            ],
            'campaign_health' => [
                'title'       => 'Check campaign health',
                'description' => 'Review a campaign and say whether it is working.',
                'arguments'   => [
                    ['name' => 'campaign', 'description' => 'Name of the campaign.', 'required' => true],
                ],
                'template' => <<<'TEXT'
                    Look up the Mautic campaign called "{campaign}".

                    Report on:
                    1. Its structure: the source segments and the sequence of steps.
                    2. Its size: how many contacts are in it right now.
                    3. The emails it sends, and for each one the sent, opened and clicked counts.
                    4. Anything that looks wrong: steps nobody reaches, emails with no opens,
                       unpublished pieces, or a decision with no path out.

                    End with the single change most likely to improve the result, and say what
                    evidence points to it.
                    TEXT,
            ],
            'segment_audit' => [
                'title'       => 'Audit the segments',
                'description' => 'List the segments and flag the ones that no longer earn their place.',
                'arguments'   => [],
                'template' => <<<'TEXT'
                    List every Mautic segment with its name, its filters and how it is used.

                    Then group them:
                    - Segments that feed a campaign or an email.
                    - Segments that nothing uses.
                    - Segments whose filters overlap heavily with another segment.

                    Recommend which ones to keep, merge or archive, with a one line reason each.
                    Do not change anything in Mautic; this is a review.
                    TEXT,
            ],
            'weekly_marketing_report' => [
                'title'       => 'Weekly marketing report',
                'description' => 'Summarise the last seven days of marketing activity.',
                'arguments'   => [],
                'template' => <<<'TEXT'
                    Report on the last seven days in Mautic.

                    Cover:
                    1. New contacts: how many, and where they came from.
                    2. Email: how many were sent, the open and click rates, the best and worst
                       performing email.
                    3. Forms: submissions per form.
                    4. Landing pages: hits per page.

                    Compare each number with the seven days before it and say whether it moved.
                    Finish with the two things that most deserve attention next week.
                    TEXT,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function render(string $template, array $arguments): string
    {
        foreach ($arguments as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace('{' . $key . '}', (string) $value, $template);
            }
        }

        return $template;
    }
}
