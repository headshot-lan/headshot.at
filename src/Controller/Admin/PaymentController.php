<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserGamer;
use App\Exception\GamerLifecycleException;
use App\Form\UserSelectType;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Service\DogtagService;
use App\Service\GamerService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/payment', name: 'payment')]
class PaymentController extends AbstractController
{
    private const CSRF_TOKEN_PAYMENT = 'paymentToken';

    private readonly GamerService $gamerService;
    private readonly DogtagService $dogtagService;
    private readonly IdmRepository $userRepo;

    public function __construct(GamerService $gamerService,
                                DogtagService $dogtagService,
                                IdmManager $manager)
    {
        $this->gamerService = $gamerService;
        $this->dogtagService = $dogtagService;
        $this->userRepo = $manager->getRepository(User::class);
    }

    private function createUserSelectForm(): FormInterface
    {
        $form = $this->createFormBuilder();
        $form->add('user', UserSelectType::class);

        return $form->getForm();
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $gamers = $this->gamerService->getGamers();
        $printDogTags = intval($request->query->get('dogtags')) === 1;
        if ($printDogTags) {
            $dogtagGamers = array_map(fn ($g) => [
              'id' => $g['user']->getId(),
              'uuid' => $g['user']->getUuid()->toString(),
              'nickname' => $g['user']->getNickname(),
              'paid' => $g['status']->hasPaid(),
            ], $gamers);

            return $this->render('admin/payment/dogtags.html.twig', [
                'gamers' => $dogtagGamers,
            ]);
        }

        $generatePngs = intval($request->query->get('png')) === 1;
        if ($generatePngs) {
          $options = [
            'lan' => '1. KaiserLAN',
            'date' => '25.10.-27.10.2024',
          ];
          $ids = $request->query->get('ids') ?? [];
          return $this->dogtagService->generateDogTagMatrix($options, $ids);
        }

        return $this->render('admin/payment/index.html.twig', [
            'gamers' => $gamers,
            'form_add' => $this->createUserSelectForm()->createView(),
        ]);
    }

    #[Route(path: '', name: '_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $form = $this->createUserSelectForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData()['user'];
            if (empty($user)) {
                $this->addFlash('error', 'Ungültigen User ausgewählt.');
            } elseif ($this->gamerService->gamerHasRegistered($user)) {
                $this->addFlash('warning', "User {$user->getNickname()} ist schon registriert.");
            } else {
                try {
                    $this->gamerService->gamerRegister($user);
                    $this->addFlash('success', "User {$user->getNickname()} wurde zur Veranstaltung registriert.");
                } catch (GamerLifecycleException) {
                    $this->addFlash('error', "User {$user->getNickname()}  konnte nicht registriert werden.");
                }
            }
        }

        return $this->redirectToRoute('admin_payment');
    }

    #[Route(path: '/{uuid}', name: '_update', methods: ['POST'])]
    public function update(Request $request, string $uuid): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAYMENT, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token presented');
        }

        $user = $this->userRepo->findOneById($uuid);
        if (empty($user)) {
            throw $this->createNotFoundException('User not found');
        }

        $action = $request->request->get('action');
        try {
            switch ($action) {
                case 'register':
                    $this->gamerService->gamerRegister($user);
                    break;
                case 'unregister':
                    $this->gamerService->gamerUnregister($user);
                    break;
                case 'pay':
                    $this->gamerService->gamerPay($user);
                    break;
                case 'unpay':
                    $this->gamerService->gamerUnPay($user);
                    break;
                case 'pay_toastflat':
                    $this->gamerService->gamerPayToastflat($user);
                    break;
                case 'unpay_toastflat':
                    $this->gamerService->gamerUnPayToastflat($user);
                    break;
                case 'checkin':
                    $this->gamerService->gamerCheckIn($user);
                    break;
                case 'checkout':
                    $this->gamerService->gamerCheckOut($user);
                    break;
                default:
                    $this->addFlash('error', 'Invalid action specified.');

                    return $this->redirectToRoute('admin_payment');
            }
        } catch (GamerLifecycleException $exception) {
            $this->addFlash('error', "Aktion konnte nicht durchgeführt werden ({$exception->getMessage()}).");

            return $this->redirectToRoute('admin_payment');
        }
        $this->addFlash('success', "Änderung an User {$user->getNickname()} erfolgreich.");

        return $this->redirectToRoute('admin_payment');
    }

    #[Route(path: '/{uuid}', name: '_show', methods: ['GET'])]
    public function show(string $uuid): Response
    {
        $user = $this->userRepo->findOneById($uuid);

        if (empty($user)) {
            throw $this->createNotFoundException('User not found');
        }

        $gamer = $this->gamerService->gamerGetStatus($user);

        return $this->render('admin/payment/show.html.twig', [
            'user' => $user,
            'gamer' => $gamer,
            'csrf_token' => self::CSRF_TOKEN_PAYMENT,
        ]);
    }
}
