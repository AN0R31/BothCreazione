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
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use function PHPUnit\Framework\containsIdentical;

class PanelController extends AbstractController
{
    #[Route(path: '/panel', name: 'app_panel')]
    public function panel(EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $collections = $entityManager->getRepository(Collection::class)->findAll();
        $products = $entityManager->getRepository(Product::class)->findAll();

        return $this->render('panel/panel.html.twig', [
            'collections' => $collections,
            'products' => $products,
        ]);
    }

    #[Route(path: '/panel/orders', name: 'orders')]
    public function orders(EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $orders = $entityManager->getRepository(Order::class)->findAll();

        $products = $entityManager->getRepository(Product::class)->findAll();
        $aux = [];
        foreach ($products as $product) {
            $aux[$product->getId()] = $product;
        }
        $products = $aux;

        return $this->render('panel/orders.html.twig', [
            'orders' => $orders,
            'products' => $products,
        ]);
    }

    #[Route(path: '/panel/orders/updateStatus/{order_id}/{status}', name: 'update_status', methods: ['POST'])]
    public function updateStatus(int $order_id, string $status, MailerInterface $mailer, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $order = $entityManager->getRepository(Order::class)->findOneBy([
            'id' => $order_id,
        ]);

        $order->setStatus($status);

        if ($status === 'SENT') {
            $order->setSentAt(new \DateTimeImmutable());
            $this->sendGeneralEmail(
                $order->getUser(),
                'Comanda ta a fost predata curierului',
                'Vesti bune! Comanda ta (ORDER_' . $order->getId() . ') a fost predata curierului, si este in curs de livrare.',
                'Fi pregatit, curierul va ajunge la tine in aproximativ 2 zile lucratoare.',
                false,
                '',
                '',
            );
        }

        if ($status === 'COMPLETED') {
            $order->setCompletedAt(new \DateTimeImmutable());
            $this->sendGeneralEmail(
                $order->getUser(),
                'Comanda ta a fost finalizata!',
                'Comanda ta (ORDER_' . $order->getId() . ') a fost finalizata. Te mai asteptam si data viitoare!',
                'Pana atunci, nu uita sa lasi un review produselor cumparate.',
                false,
                '',
                '',
            );
        }

        $entityManager->persist($order);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null,
        ]);
    }

    #[Route(path: '/panel/statistics', name: 'statistics')]
    public function statistics(EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $orders = $entityManager->getRepository(Order::class)->findAll();
        $completedOrders = $entityManager->getRepository(Order::class)->findBy(['status' => 'COMPLETED']);
        $reviews = $entityManager->getRepository(Review::class)->findAll();
        $users = $entityManager->getRepository(User::class)->findAll();
        $activeUsers = $entityManager->getRepository(User::class)->findBy(['isVerified' => 1]);
        $reviewAverage = 0;
        foreach ($reviews as $review) {
            $reviewAverage += $review->getGrade();
        }
        $reviewAverage = $reviewAverage/sizeof($reviews);

        return $this->render('panel/statistics.html.twig', [
            'orders' => $orders,
            'reviews' => $reviews,
            'reviewAverage' => $reviewAverage,
            'users' => $users,
            'activeUsers' => $activeUsers,
            'completedOrders' => $completedOrders,
        ]);
    }

    #[Route(path: '/panel/collection/create', name: 'app_panel_collection_create')]
    public function collectionCreate(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->getMethod() === 'POST') {
            $collections = $entityManager->getRepository(Collection::class)->findBy([], [
                'ordering' => 'DESC',
            ]);
            $latestCollection = $collections[0];

            $collection = new Collection();
            $collection->setName($request->request->get('name'));
            $collection->setCreatedAt(new \DateTimeImmutable());
            $collection->setOrdering($latestCollection->getOrdering() + 1);

            $entityManager->persist($collection);
            $entityManager->flush();

            return $this->redirectToRoute('app_panel');
        }

        return $this->render('panel/collection.html.twig');
    }

    #[Route(path: '/panel/collection/edit/{collection_id}', name: 'app_panel_collection_edit')]
    public function collectionEdit(int $collection_id, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $collection = $entityManager->getRepository(Collection::class)->findOneBy([
            'id' => $collection_id,
        ]);

        if ($request->getMethod() === 'POST') {
            $collection->setName($request->request->get('name'));

            $entityManager->persist($collection);
            $entityManager->flush();

            return $this->redirectToRoute('app_panel');
        }

        return $this->render('panel/collection.html.twig', [
            'collection' => $collection,
        ]);
    }

    #[Route(path: '/panel/collection/remove/{collection_id}', name: 'app_panel_collection_remove')]
    public function collectionRemove(int $collection_id, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $collection = $entityManager->getReference(Collection::class, [
            'id' => $collection_id,
        ]);

        $products = $entityManager->getRepository(Product::class)->findBy([
            'collection' => $collection,
        ]);

        foreach ($products as $product) {
            $userProductCart = $entityManager->getRepository(UserProductCart::class)->findBy([
                'product' => $product,
            ]);

            foreach ($userProductCart as $cartItem) {
                $cartItem = $entityManager->getReference(UserProductCart::class, [
                    'id' => $cartItem->getId(),
                ]);
                $entityManager->remove($cartItem);
            }

            $userProductFavourite = $entityManager->getRepository(UserProductFavourite::class)->findBy([
                'product' => $product,
            ]);

            foreach ($userProductFavourite as $savedItem) {
                $savedItem = $entityManager->getReference(UserProductFavourite::class, [
                    'id' => $savedItem->getId(),
                ]);
                $entityManager->remove($savedItem);
            }

            $entityManager->remove($product);
        }

        $entityManager->remove($collection);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null,
        ]);
    }

    #[Route(path: '/panel/product/create', name: 'app_panel_product_create')]
    public function productCreate(Request $request, EntityManagerInterface $entityManager, KernelInterface $kernel): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->getMethod() === 'POST') {
            $collection = $entityManager->getRepository(Collection::class)->findOneBy([
                'id' => $request->request->get('collection'),
            ]);

            $latestProductId = $entityManager->getRepository(Product::class)->findBy([], [
                'id' => 'DESC',
            ]);

            $latestProductId = $latestProductId[0]->getId();

            $product = new Product();
            $product->setCollection($collection);
            $product->setAccessory(null);
            $product->setName($request->request->get('name'));
            $product->setType($request->request->get('type'));
            $product->setPrice(intval($request->request->get('price')));
            $product->setDescription($request->request->get('description'));

            $sizes = [];
            if ($request->request->get('XS')) {
                array_push($sizes, 'XS');
            }
            if ($request->request->get('S')) {
                array_push($sizes, 'S');
            }
            if ($request->request->get('M')) {
                array_push($sizes, 'M');
            }
            if ($request->request->get('L')) {
                array_push($sizes, 'L');
            }
            if ($request->request->get('XL')) {
                array_push($sizes, 'XL');
            }

            $product->setSizes($sizes);
            $product->setMaterial($request->request->get('material'));

            $params = $request->request->all();
            $product->setColors($params['colors']);

            $images = [];
            foreach ($request->files->get('images') as $key => $image) {
                $extension = explode('.', $image->getClientOriginalName());
                $extension = end($extension);
                $fileName = $latestProductId + 1 . '_' . $key + 1 . '.' . $extension;
                $image->move($kernel->getProjectDir() . '/public/assets/images/collection/' . $collection->getName(), $fileName);
                array_push($images, '/' . $collection->getName() . '/' . $fileName);
            }
            $product->setImages($images);

            $entityManager->persist($product);
            $entityManager->flush();

            return $this->redirectToRoute('app_panel');
        }

        $collections = $entityManager->getRepository(Collection::class)->findAll();

        return $this->render('panel/product.html.twig', [
            'collections' => $collections,
        ]);
    }

    #[Route(path: '/panel/product/edit/{product_id}', name: 'app_panel_product_edit')]
    public function productEdit(int $product_id, Request $request, EntityManagerInterface $entityManager, KernelInterface $kernel): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $product = $entityManager->getRepository(Product::class)->findOneBy([
            'id' => $product_id,
        ]);

        if ($request->getMethod() === 'POST') {
            $collection = $entityManager->getRepository(Collection::class)->findOneBy([
                'id' => $request->request->get('collection'),
            ]);

            $product->setCollection($collection);
            $product->setName($request->request->get('name'));
            $product->setType($request->request->get('type'));
            $product->setPrice(intval($request->request->get('price')));
            $product->setDescription($request->request->get('description'));

            $sizes = [];
            if ($request->request->get('XS')) {
                array_push($sizes, 'XS');
            }
            if ($request->request->get('S')) {
                array_push($sizes, 'S');
            }
            if ($request->request->get('M')) {
                array_push($sizes, 'M');
            }
            if ($request->request->get('L')) {
                array_push($sizes, 'L');
            }
            if ($request->request->get('XL')) {
                array_push($sizes, 'XL');
            }

            $product->setSizes($sizes);
            $product->setMaterial($request->request->get('material'));

            $params = $request->request->all();
            $product->setColors($params['colors']);

            $entityManager->persist($product);
            $entityManager->flush();

            return $this->redirectToRoute('app_panel');
        }

        $collections = $entityManager->getRepository(Collection::class)->findAll();

        return $this->render('panel/product.html.twig', [
            'collections' => $collections,
            'product' => $product,
        ]);
    }

    #[Route(path: '/panel/product/remove/{product_id}', name: 'app_panel_product_remove')]
    public function productRemove(int $product_id, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('app_home');
        }

        $product = $entityManager->getReference(Product::class, [
            'id' => $product_id,
        ]);

        if ($product->getAccessory()) {
            $accessory = $entityManager->getReference(Accessory::class, [
                'id' => $product->getAccessory()->getId(),
            ]);
            $entityManager->remove($accessory);
        }

        $userProductCart = $entityManager->getRepository(UserProductCart::class)->findBy([
            'product' => $product,
        ]);

        foreach ($userProductCart as $cartItem) {
            $cartItem = $entityManager->getReference(UserProductCart::class, [
                'id' => $cartItem->getId(),
            ]);
            $entityManager->remove($cartItem);
        }

        $userProductFavourite = $entityManager->getRepository(UserProductFavourite::class)->findBy([
            'product' => $product,
        ]);

        foreach ($userProductFavourite as $savedItem) {
            $savedItem = $entityManager->getReference(UserProductFavourite::class, [
                'id' => $savedItem->getId(),
            ]);
            $entityManager->remove($savedItem);
        }

        $entityManager->remove($product);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null,
        ]);
    }

    public static function sendGeneralEmail(User $user, string $subject, string $message, string $additionalInfo, bool $hasButton, string $buttonText, string $buttonLink) {
        $mailer = new Mailer(Transport::fromDsn($_ENV['SMTP_DNS']));
        $email = (new TemplatedEmail())
            ->from(new Address('support@bothcreazione.com', 'S. Both Creazione'))
            ->to($user->getEmail())
            ->priority(Email::PRIORITY_HIGH)
            ->subject($subject)
            ->htmlTemplate('mail/general.html.twig')
            ->context([
                'user' => $user,
                'message' => $message,
                'additionalInfo' => $additionalInfo,
                'hasButton' => $hasButton,
                'buttonText' => $buttonText,
                'buttonLink' => $buttonLink,
            ]);

        $loader = new FilesystemLoader('templates', '/app');
        $twigEnv = new Environment($loader);
        $twigBodyRenderer = new BodyRenderer($twigEnv);
        $twigBodyRenderer->render($email);

        $mailer->send($email);
    }
}
