<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMcpBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the MCP endpoint speaking JSON, whatever goes wrong.
 *
 * Without this, a listener that runs before the controller (FOSRestBundle's body decoder,
 * for instance, when a client posts broken JSON) makes Mautic render its HTML error page.
 * An MCP client cannot read that, and reports an unhelpful "invalid response" to the user.
 */
class McpExceptionSubscriber implements EventSubscriberInterface
{
    private const PARSE_ERROR    = -32700;
    private const INTERNAL_ERROR = -32603;

    public static function getSubscribedEvents(): array
    {
        return [
            // Above Mautic's own ExceptionListener, which sits at 253 and renders HTML.
            KernelEvents::EXCEPTION => ['onKernelException', 512],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isMcpRequest($request)) {
            return;
        }

        $exception = $event->getThrowable();
        $status    = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        // 4xx on this endpoint is almost always a body the client got wrong.
        $code = $status >= 400 && $status < 500 ? self::PARSE_ERROR : self::INTERNAL_ERROR;

        $event->setResponse(new JsonResponse(
            [
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => [
                    'code'    => $code,
                    'message' => $exception->getMessage(),
                ],
            ],
            $status,
            ['Access-Control-Allow-Origin' => '*']
        ));

        $event->stopPropagation();
    }

    private function isMcpRequest(Request $request): bool
    {
        $path = $request->getPathInfo();

        return '/mcp' === $path || str_starts_with($path, '/mcp/');
    }
}
