<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\LdapService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Twig\Environment;

/**
 * Login form authentication of LDAP users (users.auth_source_id set) and on-the-fly
 * registration of unknown logins found in LDAP sources (Redmine's User.try_to_login).
 *
 * It only handles POST /login requests for such logins; every other login is left to the
 * standard form_login authenticator (it declines in supports()). Success / failure are
 * delegated to form_login's handlers so redirects (back_url) and error messages are identical.
 */
final class LdapAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly LdapService $ldap,
        #[Autowire(service: 'security.authentication.success_handler.main.form_login')]
        private readonly AuthenticationSuccessHandlerInterface $successHandler,
        #[Autowire(service: 'security.authentication.failure_handler.main.form_login')]
        private readonly AuthenticationFailureHandlerInterface $failureHandler,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?Environment $twig = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!$request->isMethod('POST') || 'signin' !== $request->attributes->get('_route')) {
            return false;
        }
        $login = trim((string) $request->request->get('username', ''));
        if ('' === $login) {
            return false;
        }
        try {
            $user = $this->ldap->findUserByLogin($login);
            if (null !== $user) {
                // like Redmine, users bound to an auth source always authenticate against it
                return null !== $user->authSourceId;
            }

            return $this->ldap->hasOnTheFlySources();
        } catch (\Throwable) {
            return false;
        }
    }

    public function authenticate(Request $request): Passport
    {
        $login = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $login);
        $authenticated = false;

        $badge = new UserBadge($login, function (string $identifier) use ($password, &$authenticated): User {
            $user = $this->ldap->findUserByLogin($identifier);
            if (null === $user) {
                // on-the-fly registration (only after the CSRF token has been checked)
                $user = $this->tryToLogin($identifier, $password);
                if (null === $user) {
                    throw new UserNotFoundException();
                }
                if (null === $user->id) {
                    throw new LdapAuthenticationException('', $user);
                }
                $authenticated = true;
            }

            return $user;
        });

        $passport = new Passport($badge, new CustomCredentials(function (string $password, User $user) use (&$authenticated): bool {
            if ($authenticated) {
                return true;
            }
            if (null === $user->authSourceId) {
                throw new BadCredentialsException();
            }

            return null !== $this->tryToLogin($user->login, $password);
        }, $password), [
            new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token', '')),
        ]);
        if ($request->request->has('autologin')) {
            $passport->addBadge((new RememberMeBadge())->enable());
        }

        return $passport;
    }

    /** User.try_to_login! (AuthSource errors are reported to the user like AccountController#login) */
    private function tryToLogin(string $login, string $password): ?User
    {
        try {
            return $this->ldap->tryToLoginStrict($login, $password);
        } catch (\RuntimeException $e) {
            $this->logger?->error("An error occurred when authenticating $login: ".$e->getMessage());

            throw new LdapAuthenticationException($e->getMessage(), null, $e);
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof LdapAuthenticationException && null !== $exception->unsavedUser) {
            // onthefly_creation_failed: registration form prefilled with the LDAP attributes
            $user = $exception->unsavedUser;
            $request->getSession()->set('auth_source_registration', ['login' => $user->login, 'auth_source_id' => $user->authSourceId]);
            $request->getSession()->set('auth_source_registration_user', [
                'firstname' => $user->firstname, 'lastname' => $user->lastname, 'mail' => (string) $user->getMail(),
            ]);

            return new RedirectResponse($this->urls->generate('register'));
        }
        if ($exception instanceof LdapAuthenticationException && null !== $this->twig) {
            $response = new Response($this->twig->render('common/error.html.twig', ['status' => 500, 'message' => $exception->getMessage(), 'page_title' => '500']), 500);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        return $this->failureHandler->onAuthenticationFailure($request, $exception);
    }
}
