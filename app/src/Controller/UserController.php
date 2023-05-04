<?php

namespace App\Controller;

use App\Entity\Accessory;
use App\Entity\Collection;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Entity\UserProductCart;
use App\Entity\UserProductFavourite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use function PHPUnit\Framework\containsIdentical;

class UserController extends AbstractController
{
    #[Route(path: '/user', name: 'user')]
    public function panel(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->get('user_id') == null) {
            if (!$this->getUser()) {
                return $this->redirectToRoute('app_login');
            }
            $user = $this->getUser();
        } else {
            $user = $entityManager->getRepository(User::class)->findOneBy([
                'id' => $request->get('user_id'),
            ]);
        }

        $orders = $entityManager->getRepository(Order::class)->findBy([
            'user' => $user,
        ]);
        if ($orders == null) {
            $noOrders = 0;
        } else {
            $noOrders = sizeof($orders);
        }

        $userReviews = $entityManager->getRepository(Review::class)->findBy([
            'user' => $user,
        ]);
        if ($userReviews == null) {
            $noReviews = 0;
        } else {
            $noReviews = sizeof($userReviews);
        }

        $userProductFavourites = $entityManager->getRepository(UserProductFavourite::class)->findBy([
            'user' => $user,
        ]);
        if ($userProductFavourites == null) {
            $noSaved = 0;
        } else {
            $noSaved = sizeof($userProductFavourites);
        }

        return $this->render('user/page.html.twig', [
            'user' => $user,
            'noOrders' => $noOrders,
            'noReviews' => $noReviews,
            'noSaved' => $noSaved,
        ]);
    }

    #[Route(path: '/user/edit', name: 'user_edit', methods: ['GET'])]
    public function edit_render(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->getUser();

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'source' => $request->get('source'),
        ]);
    }

    #[Route(path: '/user/edit', methods: ['POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager, KernelInterface $kernel): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->getUser();
        $user->setFirstName($request->request->get('firstName'));
        $user->setLastName($request->request->get('lastName'));
        $user->setCountry('Romania');
        $user->setCounty($request->request->get('county'));
        $user->setCity($request->request->get('city'));
        $user->setAddress($request->request->get('address'));
        $user->setZipCode($request->request->get('zipCode'));
        $user->setPhoneNumber($request->request->get('phoneNumber'));

        if ($request->files->get('profileImage') != null) {
            $image = $request->files->get('profileImage');
            $extension = explode('.', $image->getClientOriginalName());
            $extension = end($extension);
            $fileName = $user->getId()  . '.' . $extension;
            $image->move($kernel->getProjectDir() . '/public/assets/images/user/profile', $fileName);
            $user->setProfileImage($fileName);
        }

        if ($request->files->get('backgroundImage') != null) {
            $image = $request->files->get('backgroundImage');
            $extension = explode('.', $image->getClientOriginalName());
            $extension = end($extension);
            $fileName = $user->getId()  . '.' . $extension;
            $image->move($kernel->getProjectDir() . '/public/assets/images/user/background', $fileName);
            $user->setBackgroundImage($fileName);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        if ($request->request->get('source') === 'PROFILE') {
            return $this->redirectToRoute('user');
        } elseif ($request->request->get('source') === 'ORDER') {
            return $this->redirectToRoute('cart');
        }

        return $this->redirectToRoute('app_home');
    }
}
