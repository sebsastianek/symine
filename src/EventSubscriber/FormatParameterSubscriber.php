<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Rails takes the response format from the "format" parameter as well as from the path suffix:
 * /projects?format=atom is /projects.atom. Symine routes carry the format in the path, so a request with
 * "?format=<fmt>" is rewritten to the suffixed path when (and only when) the action has that representation.
 * Runs before the router, so routing, the firewalls (KeyAuthScope) and the controllers all see one form.
 */
final class FormatParameterSubscriber implements EventSubscriberInterface
{
    private const array FORMATS = ['json', 'xml', 'atom', 'csv', 'pdf', 'txt', 'png'];

    public function __construct(private readonly RouterInterface $router)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // just before the RouterListener (32)
        return [KernelEvents::REQUEST => ['onRequest', 33]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->has('_controller')) {
            return;
        }
        $format = $request->query->get('format');
        if (!\is_string($format) || !\in_array($format, self::FORMATS, true)) {
            return;
        }
        $path = $request->getPathInfo();
        if ('/' === $path || str_ends_with($path, '/') || str_ends_with($path, '.'.$format)) {
            return;
        }
        $suffixed = $path.'.'.$format;
        try {
            $this->router->getContext()->fromRequest($request);
            $parameters = $this->router->match($suffixed);
        } catch (ExceptionInterface) {
            return;
        }
        if (($parameters['_format'] ?? null) !== $format) {
            return;
        }
        $server = $request->server->all();
        $queryString = (string) ($server['QUERY_STRING'] ?? '');
        if ('' === $queryString) {
            $queryString = http_build_query($request->query->all());
        }
        $server['REQUEST_URI'] = $request->getBaseUrl().$suffixed.('' !== $queryString ? '?'.$queryString : '');
        unset($server['PATH_INFO'], $server['ORIG_PATH_INFO']);
        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent(),
        );
    }
}
