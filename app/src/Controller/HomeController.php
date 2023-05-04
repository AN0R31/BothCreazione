<?php

namespace App\Controller;

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
use Symfony\Component\Routing\Annotation\Route;
use function PHPUnit\Framework\containsIdentical;

class HomeController extends AbstractController
{
    #[Route(path: '/', name: 'app_home')]
    public function home(): Response
    {
         if ($this->getUser()) {
             return $this->redirectToRoute('store');
         }

        return $this->render('home/landing.html.twig');
    }

    #[Route(path: '/panel', name: 'app_panel')]
    public function panel(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        if (!in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            return $this->redirectToRoute('store');
        }

        return $this->render('home/panel.html.twig');
    }

    #[Route(path: '/store', name: 'store')]
    public function store(EntityManagerInterface $entityManager): Response
    {
        $products = $entityManager->getRepository(Product::class)->findAll();

        return $this->render('home/store.html.twig', [
            'products' => $products,
        ]);
    }

    #[Route(path: '/latest', name: 'latest')]
    public function latest(EntityManagerInterface $entityManager): Response
    {
        $collections = $entityManager->getRepository(Collection::class)->findBy([], [
            'ordering' => 'DESC'
        ]);

        $latestCollection = $collections[0];

        $products = $entityManager->getRepository(Product::class)->findBy([
            'collection' => $latestCollection,
        ]);

        return $this->render('home/store.html.twig', [
            'products' => $products,
        ]);
    }

    #[Route(path: '/favourites', name: 'favourites', methods: ['GET'])]
    public function favourites(EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $savedItems = $entityManager->getRepository(UserProductFavourite::class)->findBy([
            'user' => $this->getUser()
        ], ['id' => 'DESC']);

        return $this->render('home/favourites.html.twig', [
            'savedItems' => $savedItems,
        ]);
    }

    #[Route(path: '/favourites/add/{product_id}', name: 'favourites_add', methods: ['POST'])]
    public function addProductToFavourites(int $product_id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $userProductFavourite = new UserProductFavourite();
        $userProductFavourite->setUser($this->getUser());
        $userProductFavourite->setProduct($entityManager->getRepository(Product::class)->findOneBy(['id' => $product_id]));
        $userProductFavourite->setCreatedAt(new \DateTimeImmutable());

        $entityManager->persist($userProductFavourite);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null
        ]);
    }

    #[Route(path: '/favourites/remove/{product_id}', name: 'favourites_remove', methods: ['POST'])]
    public function removeProductFromFavourites(int $product_id, EntityManagerInterface $entityManager): Response
    {
        $userProductFavourite = $entityManager->getRepository(UserProductFavourite::class)->findOneBy([
            'user' => $this->getUser(),
            'product' => $entityManager->getRepository(Product::class)->findOneBy(['id' => $product_id]),
        ]);

        $userProductFavourite = $entityManager->getReference(UserProductFavourite::class, ['id' => $userProductFavourite->getId()]);

        $entityManager->remove($userProductFavourite);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null
        ]);
    }

    #[Route(path: '/favourites/remove', name: 'product_remove_from_favourites', methods: ['POST'])]
    public function productRemoveFromFavourites(Request $request, EntityManagerInterface $entityManager): Response
    {
        $userProductFavourite = $entityManager->getReference(UserProductFavourite::class, [
            'id' => $request->request->get('savedItemId'),
        ]);

        $entityManager->remove($userProductFavourite);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null
        ]);
    }

    #[Route(path: '/cart', name: 'cart')]
    public function cart(EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $cartItems = $entityManager->getRepository(UserProductCart::class)->findBy([
            'user' => $this->getUser()
        ], ['id' => 'DESC']);

        return $this->render('home/cart.html.twig', [
            'cartItems' => $cartItems,
        ]);
    }

    #[Route(path: '/product/{product_id}', name: 'product', methods: ['GET'])]
    public function product(int $product_id, EntityManagerInterface $entityManager): Response
    {
        $product = $entityManager->getRepository(Product::class)->findOneBy(['id' => $product_id]);

        $ratings = $entityManager->getRepository(Review::class)->findBy([
            'product' => $product,
        ]);

        $totalStars = 0;
        foreach ($ratings as $rating) {
            $totalStars += $rating->getGrade();
        }

        if (sizeof($ratings) === 0) {
            $rating = 'null';
        } else {
            $rating = round($totalStars/sizeof($ratings));
        }

        if (!$this->getUser()) {
            $isFavourite = false;
            $isInCart = false;
        } else {
            $userProductFavourite = $entityManager->getRepository(UserProductFavourite::class)->findOneBy([
                'user' => $this->getUser(),
                'product' => $product,
            ]);
            if (!$userProductFavourite) {
                $isFavourite = false;
            } else {
                $isFavourite = true;
            }

            $userProductCart = $entityManager->getRepository(UserProductCart::class)->findOneBy([
                'user' => $this->getUser(),
                'product' => $product
            ]);
            if (!$userProductCart) {
                $isInCart = false;
            } else {
                $isInCart = true;
            }
        }

        $reviews = $entityManager->getRepository(Review::class)->findBy([
            'product' => $product,
        ]);

        return $this->render('home/product.html.twig', [
            'product' => $product,
            'isFavourite' => $isFavourite,
            'isInCart' => $isInCart,
            'rating' => $rating,
            'reviews' => $reviews,
        ]);
    }

    #[Route(path: '/product/{product_id}', name: 'product_add_to_cart', methods: ['POST'])]
    public function addProductToCart(int $product_id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $product = $entityManager->getRepository(Product::class)->findOneBy(['id' => $product_id]);
        $user = $this->getUser();

        $userProductCart = new UserProductCart();
        if ($request->request->get('hasAccessory') == 0) {
            $userProductCart->setHasAccessory(false);
        } else {
            $userProductCart->setHasAccessory(true);
        }
        $userProductCart->setUser($user);
        $userProductCart->setProduct($product);
        $userProductCart->setColor($request->request->get('color'));
        $userProductCart->setSize($request->request->get('size'));
        $userProductCart->setCreatedAt(new \DateTimeImmutable());

        $entityManager->persist($userProductCart);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null
        ]);
    }

    #[Route(path: '/cart/remove', name: 'product_remove_from_cart', methods: ['POST'])]
    public function productRemoveFromCart(Request $request, EntityManagerInterface $entityManager): Response
    {
        $userProductCart = $entityManager->getReference(UserProductCart::class, [
            'id' => $request->request->get('cartItemId'),
        ]);

        $entityManager->remove($userProductCart);
        $entityManager->flush();

        return new JsonResponse([
            'status' => true,
            'error' => null
        ]);
    }

    #[Route(path: '/order', name: 'order', methods: ['POST'])]
    public function order(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $userProductCarts = $entityManager->getRepository(UserProductCart::class)->findBy([
            'user' => $user,
        ]);

        $products = [];
        $productSizes = [];
        $productColors = [];
        $productAccessories = [];
        $price = 0;
        foreach ($userProductCarts as $key => $userProductCart) {
            $products[$key] = $userProductCart->getProduct()->getId();
            $productSizes[$key] = $userProductCart->getSize();
            $productColors[$key] = $userProductCart->getColor();

            $price += $userProductCart->getProduct()->getPrice();
            if ($userProductCart->isHasAccessory()) {
                $productAccessories[$key] = $userProductCart->getProduct()->getAccessory()->getId();
                $price += $userProductCart->getProduct()->getAccessory()->getPrice();
            } else {
                $productAccessories[$key] = null;
            }

            $userProductCart = $entityManager->getReference(UserProductCart::class, [
                'id' => $userProductCart->getId()
            ]);
            $entityManager->remove($userProductCart);
        }

        $order = new Order();
        $order->setUser($user);
        $order->setProducts($products);
        $order->setProductSizes($productSizes);
        $order->setProductColors($productColors);
        $order->setProductAccessories($productAccessories);
        $order->setStatus('PLACED');
        $order->setPrice($price);
        $order->setCountry($user->getCountry());
        $order->setCounty($user->getCounty());
        $order->setCity($user->getCity());
        $order->setAddress($user->getAddress());
        $order->setPhoneNumber($user->getPhoneNumber());
        $order->setZipCode($user->getZipCode());
        $order->setCreatedAt(new \DateTimeImmutable());

        $entityManager->persist($order);
        $entityManager->flush();

        PanelController::sendGeneralEmail(
            $order->getUser(),
            'Comanda ta a fost plasata cu succes!',
            'Vesti bune! Comanda ta (ORDER_' . $order->getId() . ') in valoare de ' . $order->getPrice() . ' RON a fost plasata!',
            'De regula, comenzile ajung la tine in aproximativ 7 zile lucratoare.',
            false,
            '',
            '',
        );

        return new JsonResponse([
            'status' => true,
            'error' => null,
        ]);
    }

    #[Route(path: '/review/{product_id}', name: 'review', methods: ['POST'])]
    public function review(int $product_id, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->getUser()) {
            return new JsonResponse([
                'status' => false,
                'error' => 'Trebuie sa fi autentificat pentru a evalua produse!',
            ]);
        }

        $user = $this->getUser();
        $product = $entityManager->getRepository(Product::class)->findOneBy([
            'id' => $product_id,
        ]);

        $possibleReview = $entityManager->getRepository(Review::class)->findOneBy([
            'user' => $user,
            'product' => $product,
        ]);

        if ($possibleReview != null) {
            return new JsonResponse([
                'status' => false,
                'error' => 'Deja ai lasat un review la acest produs!',
            ]);
        }

        $review = new Review();
        $review->setUser($user);
        $review->setProduct($product);
        $review->setTitle($request->request->get('title'));
        $review->setDescription($request->request->get('description'));
        $review->setGrade(intval($request->request->get('rating')));

        $entityManager->persist($review);
        $entityManager->flush();

        PanelController::sendGeneralEmail(
            $entityManager->getRepository(User::class)->findOneBy(['id' => $user->getId()]),
            'Multumim pentru Review!',
            'Evaluarea ta de ' . $review->getGrade() . '  stele pentru ' . $product->getType() . ' ' . $product->getName() . ' a fost inregistrata cu succes!',
            '"' . $review->getTitle() . ' ' . $review->getDescription() . '"',
            false,
            '',
            '',
        );

        return new JsonResponse([
            'status' => true,
            'error' => null,
        ]);
    }
}
