<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Query\ProjectAdminQuery;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Service\Activity\ProjectLastActivity;
use App\Entity\User;
use App\Entity\Webhook;
use App\Service\Admin\DefaultDataLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AdminController (admin index, projects, plugins, webhooks, info, test email, default data). */
final class AdminController extends AbstractController
{
    public const string VERSION = '0.1.0';

    #[Route('/admin', name: 'admin_index', methods: ['GET'])]
    public function index(DefaultDataLoader $loader): Response
    {
        $this->requireAdmin();

        return $this->render('admin/admin/index.html.twig', [
            'no_configuration_data' => $loader->noData(),
            'languages' => $this->i18n->languageOptions(),
            'current_language' => $this->i18n->locale(),
            'page_title' => $this->l('label_administration'),
            'body_css' => 'controller-admin action-index',
        ]);
    }

    #[Route('/admin/default_configuration', name: 'admin_default_configuration', methods: ['POST'])]
    public function defaultConfiguration(Request $request, DefaultDataLoader $loader): Response
    {
        $this->requireAdmin();
        try {
            $loader->load((string) $request->request->get('lang', 'en'));
            $this->flashNotice('notice_default_data_loaded');
        } catch (\Throwable $e) {
            $this->flashError($this->l('error_can_t_load_default_data', ['value' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_index');
    }

    #[Route('/admin/projects', name: 'admin_projects', methods: ['GET'])]
    public function projects(Request $request, QueryFactory $queries, ProjectLastActivity $lastActivity): Response
    {
        $this->requireAdmin();
        // retrieve_query(ProjectAdminQuery, false)
        try {
            $query = $queries->retrieve($request, 'ProjectAdminQuery', null, false);
        } catch (QueryError) {
            $query = $queries->create('ProjectAdminQuery');
        }
        \assert($query instanceof ProjectAdminQuery);
        $vars = [
            'query' => $query,
            'admin_menu_selected' => 'projects',
            'page_title' => $this->l('label_project_plural'),
            'body_css' => 'controller-admin action-projects',
            'clear_url' => $this->generateUrl('admin_projects', ['set_filter' => 1, 'sort' => '']),
            'new_query_url' => $this->generateUrl('query_new'),
            'can_save_queries' => $this->auth->allowedTo('save_queries', null, null, true),
            'extra_query_params' => [],
            'entries' => [],
        ];
        if ($query->isValid()) {
            try {
                $count = $query->resultCount();
                $vars['pager'] = $this->paginate($request, $count);
                $vars['entries'] = $query->results($vars['pager']['offset'], $vars['pager']['limit']);
            } catch (StatementInvalid) {
                $query->errors[] = $this->l('error_query_statement_invalid');
                $vars['entries'] = [];
            }
            if ($query->hasColumn('last_activity_date') && [] !== $vars['entries']) {
                $query->setLastActivityDates($lastActivity->lastActivityDates($this->user(), array_map(static fn ($p) => (int) $p->id, $vars['entries'])));
            }
        }
        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/admin/_projects.html.twig', $vars);
        }

        return $this->render('admin/admin/projects.html.twig', $vars);
    }

    #[Route('/admin/plugins', name: 'admin_plugins', methods: ['GET'])]
    public function plugins(\App\Plugin\PluginRegistry $registry): Response
    {
        $this->requireAdmin();

        return $this->render('admin/admin/plugins.html.twig', [
            'plugins' => array_values($registry->all()),
            'admin_menu_selected' => 'plugins',
            'page_title' => $this->l('label_plugins'),
            'body_css' => 'controller-admin action-plugins',
        ]);
    }

    #[Route('/admin/webhooks', name: 'admin_webhooks', methods: ['GET'])]
    public function webhooks(): Response
    {
        $this->requireAdmin();
        $webhooks = $this->em->createQuery('SELECT w, u FROM App\Entity\Webhook w JOIN w.user u ORDER BY u.firstname, u.lastname, w.url')->getResult();

        return $this->render('admin/admin/webhooks.html.twig', [
            'webhooks' => $webhooks,
            'webhooks_enabled' => $this->settings->bool('webhooks_enabled'),
            'admin_menu_selected' => 'webhooks',
            'page_title' => $this->l('label_webhook_plural'),
            'body_css' => 'controller-admin action-webhooks',
        ]);
    }

    #[Route('/admin/test_email', name: 'admin_test_email', methods: ['POST'])]
    public function testEmail(MailerInterface $mailer): Response
    {
        $this->requireAdmin();
        $user = $this->user();
        $to = $user->getMail();
        try {
            if (null === $to || '' === $to) {
                throw new \RuntimeException('no email address');
            }
            $from = $this->settings->string('mail_from') ?: 'redmine@example.net';
            $url = $this->generateUrl('home', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
            $text = $this->l('mail_body_test_email');
            $email = (new Email())
                ->from(Address::create($from))
                ->to(new Address($to, $user->getName()))
                ->subject('['.$this->settings->appTitle().'] '.$this->l('mail_subject_test_email'))
                ->text($text."\n\n".$url);
            if (!$this->settings->bool('plain_text_mail')) {
                $email->html('<p>'.htmlspecialchars($text).'</p><p><a href="'.htmlspecialchars($url).'">'.htmlspecialchars($url).'</a></p>');
            }
            $mailer->send($email);
            $this->flashNotice('notice_email_sent', ['value' => $to]);
        } catch (\Throwable $e) {
            $this->flashError($this->l('notice_email_error', ['value' => $e->getMessage()]));
        }

        return $this->redirectToRoute('settings_index', ['tab' => 'notifications']);
    }

    #[Route('/admin/info', name: 'admin_info', methods: ['GET'])]
    public function info(UserPasswordHasherInterface $hasher): Response
    {
        $this->requireAdmin();
        $filesPath = (string) $this->getParameter('symine.files_path');
        $admin = $this->em->getRepository(User::class)->findOneBy(['login' => 'admin', 'status' => User::STATUS_ACTIVE]);
        $defaultAdminChanged = true;
        if ($admin instanceof User && null !== $admin->getPassword()) {
            try {
                $defaultAdminChanged = !$hasher->isPasswordValid($admin, 'admin');
            } catch (\Throwable) {
            }
        }
        $checklist = [
            ['text_default_administrator_account_changed', $defaultAdminChanged],
            ['text_file_repository_writable', is_dir($filesPath) ? is_writable($filesPath) : is_writable(\dirname($filesPath))],
            ['text_minimagick_available', \extension_loaded('imagick') || \extension_loaded('gd')],
            ['text_convert_available', self::commandAvailable('convert') || self::commandAvailable('magick')],
            ['text_gs_available', self::commandAvailable('gs')],
        ];
        $conn = $this->em->getConnection();
        $platform = (new \ReflectionClass($conn->getDatabasePlatform()))->getShortName();
        $environment = [
            'Symine version' => self::VERSION.' (Redmine 7.0 compatible)',
            'PHP version' => \PHP_VERSION.' ('.\PHP_SAPI.')',
            'Symfony version' => Kernel::VERSION,
            'Environment' => $this->getParameter('kernel.environment'),
            'Database adapter' => preg_replace('/Platform$/', '', $platform),
            'Mailer delivery' => self::mailerScheme(),
            'Files directory' => $filesPath,
        ];

        return $this->render('admin/admin/info.html.twig', [
            'checklist' => $checklist,
            'environment' => $environment,
            'versioned_name' => 'Symine '.self::VERSION,
            'admin_menu_selected' => 'info',
            'page_title' => $this->l('label_information_plural'),
            'body_css' => 'controller-admin action-info',
        ]);
    }

    private static function commandAvailable(string $cmd): bool
    {
        if (!\function_exists('shell_exec')) {
            return false;
        }
        $out = @shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null');

        return \is_string($out) && '' !== trim($out);
    }

    private static function mailerScheme(): string
    {
        $dsn = (string) ($_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '');

        return '' === $dsn ? 'n/a' : (string) (parse_url($dsn, \PHP_URL_SCHEME) ?: strtok($dsn, ':'));
    }
}
