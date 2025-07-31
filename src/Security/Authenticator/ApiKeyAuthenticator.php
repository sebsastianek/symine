<?php

declare(strict_types=1);

namespace App\Security\Authenticator;

use App\Entity\Token;
use App\Repository\TokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Psr\Log\LoggerInterface;

/**
 * API Key Authenticator for Redmine-compatible API authentication
 *
 * Supports multiple API key authentication methods:
 * 1. X-Redmine-API-Key header
 * 2. ?key=API_KEY URL parameter
 * 3. HTTP Basic auth with API key as username
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private TokenRepository $tokenRepository,
        private LoggerInterface $logger
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Check if this is an API request
        if (!$this->isApiRequest($request)) {
            return false;
        }

        // Check if any API key is provided
        return $this->extractApiKey($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $apiKey = $this->extractApiKey($request);

        if (!$apiKey) {
            throw new CustomUserMessageAuthenticationException('No API key provided');
        }

        // Validate API key format
        if (!Token::isValidTokenFormat($apiKey)) {
            $this->logger->warning('Invalid API key format provided', [
                'key_prefix' => substr($apiKey, 0, 8) . '...',
                'ip' => $request->getClientIp()
            ]);
            throw new CustomUserMessageAuthenticationException('Invalid API key format');
        }

        return new SelfValidatingPassport(
            new UserBadge($apiKey, [$this, 'loadUserByApiKey'])
        );
    }

    public function loadUserByApiKey(string $apiKey): \App\Entity\User
    {
        $user = $this->tokenRepository->findUserByApiKey($apiKey);

        if (!$user) {
            $this->logger->warning('API key authentication failed - user not found', [
                'key_prefix' => substr($apiKey, 0, 8) . '...'
            ]);
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }

        $this->logger->info('API key authentication successful', [
            'user_id' => $user->getId(),
            'user_login' => $user->getLogin()
        ]);

        return $user;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // For API requests, we don't redirect - just let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->logger->warning('API key authentication failed', [
            'exception' => $exception->getMessage(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'path' => $request->getPathInfo()
        ]);

        $data = [
            'error' => 'Authentication failed',
            'message' => 'Invalid or missing API key'
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Extract API key from request using multiple methods
     */
    private function extractApiKey(Request $request): ?string
    {
        // Method 1: X-Redmine-API-Key header (primary method)
        $apiKey = $request->headers->get('X-Redmine-API-Key');
        if ($apiKey) {
            return trim($apiKey);
        }

        // Method 2: ?key=API_KEY URL parameter
        $apiKey = $request->query->get('key');
        if ($apiKey) {
            return trim($apiKey);
        }

        // Method 3: HTTP Basic authentication with API key as username
        $authorizationHeader = $request->headers->get('Authorization');
        if ($authorizationHeader && str_starts_with($authorizationHeader, 'Basic ')) {
            $credentials = base64_decode(substr($authorizationHeader, 6));
            if ($credentials && str_contains($credentials, ':')) {
                [$username, $password] = explode(':', $credentials, 2);

                // If username looks like an API key (40 char hex), use it
                if (Token::isValidTokenFormat($username)) {
                    return $username;
                }
            }
        }
        return null;
    }

    /**
     * Check if this is an API request
     */
    private function isApiRequest(Request $request): bool
    {
        // Check for API-specific paths
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        // Check for API content types
        $contentType = $request->headers->get('Content-Type');
        if ($contentType && (
            str_contains($contentType, 'application/json') ||
            str_contains($contentType, 'application/xml') ||
            str_contains($contentType, 'text/xml')
        )) {
            return true;
        }

        // Check for API accept headers
        $accept = $request->headers->get('Accept');
        if ($accept && (
            str_contains($accept, 'application/json') ||
            str_contains($accept, 'application/xml') ||
            str_contains($accept, 'text/xml')
        )) {
            return true;
        }

        // Check for explicit API key presence
        if ($this->extractApiKey($request)) {
            return true;
        }

        return false;
    }

    /**
     * Check if REST API is enabled (would be configurable)
     */
    private function isRestApiEnabled(): bool
    {
        // This would check application settings
        // For now, assume it's always enabled
        return true;
    }

    /**
     * Get authentication method used for logging
     */
    private function getAuthenticationMethod(Request $request): string
    {
        if ($request->headers->has('X-Redmine-API-Key')) {
            return 'header';
        }

        if ($request->query->has('key')) {
            return 'query_parameter';
        }

        if ($request->headers->has('Authorization')) {
            return 'http_basic';
        }

        return 'unknown';
    }

    /**
     * Extract user switching header (for admin users)
     */
    private function extractUserSwitchHeader(Request $request): ?string
    {
        return $request->headers->get('X-Redmine-Switch-User');
    }

    /**
     * Validate API key against rate limiting (if implemented)
     */
    private function checkRateLimit(string $apiKey, Request $request): bool
    {
        // This would implement rate limiting logic
        return true;
    }
}
