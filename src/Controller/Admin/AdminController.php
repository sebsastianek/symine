<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Mailer\NotificationMailer;
use App\Plugin\PluginRegistry;
use App\Service\Admin\DefaultDataLoader;
use App\Service\Admin\ProjectAdminList;
use App\Service\Admin\SystemInfo;
use App\Service\Admin\WebhookManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's AdminController (admin index, projects, plugins, webhooks, info, test email, default data). */
final class AdminController extends AbstractController
{
    public const string VERSION = SystemInfo::VERSION;

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
    public function projects(Request $request, ProjectAdminList $list): Response
    {
        $this->requireAdmin();
        $vars = $list->view($request, $this->paginate(...));
        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/admin/_projects.html.twig', $vars);
        }

        return $this->render('admin/admin/projects.html.twig', $vars);
    }

    #[Route('/admin/plugins', name: 'admin_plugins', methods: ['GET'])]
    public function plugins(PluginRegistry $registry): Response
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
    public function webhooks(WebhookManager $webhooks): Response
    {
        $this->requireAdmin();

        return $this->render('admin/admin/webhooks.html.twig', [
            'webhooks' => $webhooks->allByOwner(),
            'webhooks_enabled' => $webhooks->enabled(),
            'admin_menu_selected' => 'webhooks',
            'page_title' => $this->l('label_webhook_plural'),
            'body_css' => 'controller-admin action-webhooks',
        ]);
    }

    #[Route('/admin/test_email', name: 'admin_test_email', methods: ['POST'])]
    public function testEmail(NotificationMailer $notifications): Response
    {
        $this->requireAdmin();
        $user = $this->user();
        $to = $user->getMail();
        try {
            // Mailer.deliver_test_email: the regular mailer (layout, headers, configured host name), delivered synchronously
            $notifications->deliverTestEmail($user);
            $this->flashNotice('notice_email_sent', ['value' => $to]);
        } catch (\Throwable $e) {
            $this->flashError($this->l('notice_email_error', ['value' => $e->getMessage()]));
        }

        return $this->redirectToRoute('settings_index', ['tab' => 'notifications']);
    }

    #[Route('/admin/info', name: 'admin_info', methods: ['GET'])]
    public function info(SystemInfo $info): Response
    {
        $this->requireAdmin();

        return $this->render('admin/admin/info.html.twig', [
            'checklist' => $info->checklist(),
            'environment' => $info->environment(),
            'versioned_name' => $info->versionedName(),
            'admin_menu_selected' => 'info',
            'page_title' => $this->l('label_information_plural'),
            'body_css' => 'controller-admin action-info',
        ]);
    }
}
