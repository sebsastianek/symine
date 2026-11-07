<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Token;
use App\Entity\User;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Redmine REST API authentication: "key" parameter, "X-Redmine-API-Key" header or HTTP Basic
 * (login/password or api key as username). Atom/ICS feeds accept the user's feeds key.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly UserProvider $users,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return (new ApiRequestMatcher())->matches($request);
    }

    public function authenticate(Request $request): Passport
    {
        $format = ApiRequestMatcher::format($request);
        if ('atom' === $format || 'ics' === $format) {
            $key = (string) $request->query->get('key');

            return new SelfValidatingPassport(new UserBadge('feeds:'.$key, fn () => $this->userByToken('feeds', $key)));
        }
        if (!$this->settings->restApiEnabled()) {
            throw new CustomUserMessageAuthenticationException('REST API is disabled');
        }
        $key = $request->query->get('key') ?? $request->headers->get('X-Redmine-API-Key');
        if (null !== $key && '' !== $key) {
            return new SelfValidatingPassport(new UserBadge('api:'.$key, fn () => $this->userByToken('api', (string) $key)));
        }
        $login = (string) $request->getUser();
        $password = (string) $request->getPassword();
        $user = $this->users->findByLogin($login);
        if (null !== $user && '' !== $password) {
            return new Passport(new UserBadge($user->login, fn () => $user), new PasswordCredentials($password));
        }

        return new SelfValidatingPassport(new UserBadge('api:'.$login, fn () => $this->userByToken('api', $login)));
    }

    private function userByToken(string $action, string $value): User
    {
        $token = $this->em->getRepository(Token::class)->findOneBy(['action' => $action, 'value' => $value]);
        if (null === $token || !$token->user->isActive()) {
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }

        return $token->user;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $response = new JsonResponse(null, Response::HTTP_UNAUTHORIZED);
        $response->headers->set('WWW-Authenticate', 'Basic realm="Redmine API"');

        return $response;
    }
}
