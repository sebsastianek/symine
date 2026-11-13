<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Rails 8's Rails::HealthController (GET /up, route rails_health_check). */
final class HealthController extends AbstractController
{
    #[Route('/up.{_format}', name: 'rails_health_check', requirements: ['_format' => 'html|json'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function show(Request $request, string $_format = 'html'): Response
    {
        try {
            $this->em->getConnection()->executeQuery('SELECT 1')->fetchOne();
            $up = true;
        } catch (\Throwable) {
            $up = false;
        }
        $status = $up ? 200 : 503;
        $json = 'json' === $_format || str_contains((string) $request->headers->get('Accept'), 'application/json');
        if ($json) {
            return new JsonResponse(['status' => $up ? 'up' : 'down', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')], $status);
        }
        $html = '<!DOCTYPE html><html><body style="background-color: '.($up ? 'green' : 'red').'"></body></html>';

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Vary' => 'Accept']);
    }
}
