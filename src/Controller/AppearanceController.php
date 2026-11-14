<?php

declare(strict_types=1);

namespace App\Controller;

use App\Theme\Form\PersonalAppearanceForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** My account > Appearance, and the quick switcher of the top menu (App\Theme\Form\PersonalAppearanceForm) */
final class AppearanceController extends AbstractController
{
    public function __construct(private readonly PersonalAppearanceForm $form)
    {
    }

    #[Route('/my/appearance', name: 'my_appearance', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $this->requireLogin();
        if (!$this->form->isAvailable()) {
            throw new HttpException(403, $this->l('notice_not_authorized'));
        }

        $rejected = [];
        if ($request->isMethod('POST')) {
            $rejected = $this->form->submit($this->user(), $request);
            if ([] === $rejected) {
                $this->flashNotice('notice_successful_update');

                return $this->redirectToRoute('my_appearance');
            }
        }

        return $this->render('my/appearance.html.twig', $this->form->view($this->user(), $rejected) + [
            'page_title' => $this->l('label_appearance'),
            'body_css' => 'controller-my action-appearance',
        ], new Response('', [] === $rejected ? 200 : 422));
    }

    /** POST option=<name>&value=<value>: sets one personal option of the theme in use */
    #[Route('/appearance', name: 'appearance_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->form->isQuickUpdateAvailable()) {
            throw new HttpException(403, $this->l('notice_not_authorized'));
        }
        if ($this->settings->loginRequired()) {
            $this->requireLogin();
        }

        $response = new JsonResponse();
        $error = $this->form->quickUpdate($this->user(), $request, $response);
        if (null !== $error) {
            return new JsonResponse(['errors' => [$error]], 422);
        }

        return $response->setData($this->form->state());
    }
}
