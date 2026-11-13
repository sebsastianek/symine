<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\Principal;
use App\Entity\Token;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Account e-mails of Redmine's Mailer: register (activation link), lost_password,
 * account_activation_request (to admins), password_updated (security notification),
 * account_activated / account_information.
 */
final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Absolute URL using Setting.protocol / Setting.host_name, like Redmine's mailer default_url_options */
    public function url(string $route, array $params = []): string
    {
        $path = $this->urls->generate($route, $params);
        $host = trim($this->settings->string('host_name'));
        if ('' === $host) {
            return $this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
        }
        $protocol = $this->settings->string('protocol') ?: 'http';
        // host_name may contain a sub-uri (e.g. example.net/redmine)
        $host = rtrim(preg_replace('#^https?://#', '', $host) ?? $host, '/');
        $base = $this->urls->getContext()->getBaseUrl();
        if ('' !== $base && str_starts_with($path, $base)) {
            $path = substr($path, \strlen($base));
        }

        return $protocol.'://'.$host.$path;
    }

    public function register(User $user, Token $token): void
    {
        $this->send($user->getMail(), $this->i18n->l('mail_subject_register', ['value' => $this->settings->appTitle()]), 'register', [
            'url' => $this->url('account_activate', ['token' => $token->value]),
            'user' => $user,
        ], $user);
    }

    public function lostPassword(User $user, Token $token, ?string $recipient = null): void
    {
        $this->send($recipient ?? $user->getMail(), $this->i18n->l('mail_subject_lost_password', ['value' => $this->settings->appTitle()]), 'lost_password', [
            'url' => $this->url('lost_password', ['token' => $token->value]),
            'user' => $user,
        ], $user);
    }

    /** Sent to all active administrators when a user registers with manual activation */
    public function accountActivationRequest(User $newUser): void
    {
        $admins = $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.admin = true AND u.status = :s AND u NOT INSTANCE OF App\Entity\AnonymousUser')
            ->setParameter('s', Principal::STATUS_ACTIVE)->getResult();
        $url = $this->routeExists('users_index')
            ? $this->url('users_index', ['status' => Principal::STATUS_REGISTERED, 'sort_key' => 'created_on', 'sort_order' => 'desc'])
            : $this->url('home');
        foreach ($admins as $admin) {
            $this->send($admin->getMail(), $this->i18n->l('mail_subject_account_activation_request', ['value' => $this->settings->appTitle()]), 'account_activation_request', [
                'url' => $url,
                'new_user' => $newUser,
            ], $admin);
        }
    }

    /** Mailer.deliver_password_updated: security notification to the user */
    public function passwordUpdated(User $user, User $sender, ?string $remoteIp = null): void
    {
        $this->send($user->getMail(), '['.$this->settings->appTitle().'] '.$this->i18n->l('mail_subject_security_notification'), 'security_notification', [
            'message' => $this->i18n->l('mail_body_password_updated'),
            'url' => $this->url('my_password'),
            'sender' => $sender,
            'remote_ip' => $remoteIp,
            'date' => $this->i18n->formatTime(new \DateTimeImmutable()),
        ], $user);
    }

    /** Mailer.deliver_account_activated */
    public function accountActivated(User $user): void
    {
        $this->send($user->getMail(), $this->i18n->l('mail_subject_register', ['value' => $this->settings->appTitle()]), 'account_activated', [
            'url' => $this->url('signin'),
            'user' => $user,
        ], $user);
    }

    private function routeExists(string $route): bool
    {
        try {
            $this->urls->generate($route);

            return true;
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param array<string, mixed> $context */
    private function send(?string $to, string $subject, string $template, array $context, ?User $recipient = null): void
    {
        if (null === $to || '' === $to) {
            return;
        }
        // e-mails are written in the recipient's language
        $previous = $this->i18n->locale();
        if (null !== $recipient && $recipient->language) {
            $this->i18n->setLocale($recipient->language);
        } else {
            $this->i18n->setLocale($this->settings->string('default_language') ?: 'en');
        }
        try {
            $context += ['emails_header' => $this->settings->string('emails_header'), 'emails_footer' => $this->settings->string('emails_footer')];
            $text = $this->twig->render('mailer/account/'.$template.'.txt.twig', $context);
            $email = (new Email())
                ->from(new Address($this->settings->string('mail_from') ?: 'redmine@example.net', $this->settings->appTitle()))
                ->to($to)
                ->subject($subject)
                ->text($text);
            if (!$this->settings->bool('plain_text_mail')) {
                $email->html($this->twig->render('mailer/account/'.$template.'.html.twig', $context));
            }
            $email->getHeaders()->addTextHeader('X-Mailer', 'Redmine');
            $email->getHeaders()->addTextHeader('X-Redmine-Host', $this->settings->string('host_name'));
            $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger?->error('Email delivery error: '.$e->getMessage());
        } finally {
            $this->i18n->setLocale($previous);
        }
    }
}
