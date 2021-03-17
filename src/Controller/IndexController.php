<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IndexController extends AbstractController
{
    private CurlHttpClient $httpClient;

    public function __construct()
    {
        $this->httpClient = new CurlHttpClient();
    }

    #[Route('/index', name: 'index')]
    public function index(): Response
    {
        return $this->render('index/index.html.twig', [
            'controller_name' => 'IndexController',
        ]);
    }

    #[Route('/api/apple-pay/session', name: 'create_apple_pay_session', methods: ['POST'])]
    public function createApplePaySession(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['validation_url'])) {
            return $this->json(['error' => 'Missing parameter gateway_url in request data.'], 400);
        }

        $paymentSessionEndpoint = "{$data['validation_url']}/paymentservices/paymentSession";

        try {
            $response = $this->httpClient->request('POST', $paymentSessionEndpoint, [
                'json' => [
                    'merchantIdentifier' => 'merchant.com.payone.ecom.applepayontheweb',
                    'displayName' => 'Susis Wollige Wuschelstube',
                    'initiative' => 'web',
                    'initiativeContext' => 'apldemo.patsy.one',
                ],
                'verify_peer' => false,
                'local_cert'  => __DIR__ . '/../../cert/merchant_id.pem',
                'local_pk'    => __DIR__ . '/../../cert/merchant_id.key',
                'passphrase'  => 'Payone1!',
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => "Error while establishing connection to Apple Pay service: {$e->getMessage()}",
            ], 500);
        }

        if ($response->getStatusCode() !== 200) {
            return $this->json([
                'error' => sprintf(
                    'Apple Pay merchant validation / session creation failed, received status %d from Apple Pay service.',
                    $response->getStatusCode(),
                ),
                //'apple_service_response' => json_decode($response->getContent(), true),
            ], 500);
        }

        $merchantSession = json_decode($response->getContent(), true);

        return $this->json($merchantSession, 201);
    }

    #[Route('/api/apple-pay/payment', name: 'create_apple_pay_payment', methods: ['POST'])]
    public function createApplePayPayment(Request $request): JsonResponse
    {
        // Stubbed

        return $this->json([
            'success' => true,
            'got_data' => $request->toArray(),
        ], 201);
    }
}
