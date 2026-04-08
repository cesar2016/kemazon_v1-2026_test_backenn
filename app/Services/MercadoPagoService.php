<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoService
{
    protected string $accessToken;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
        MercadoPagoConfig::setAccessToken($accessToken);
        // MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL); // Optional
    }

    public function createPreference(Order $order): array
    {
        $maskedToken = substr($this->accessToken, 0, 8) . '...' . substr($this->accessToken, -4);
        \Log::info("Creating MercadoPago preference for Order {$order->id}", ['token' => $maskedToken]);

        $client = new PreferenceClient();

        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                "id" => (string) $item->product_id,
                "title" => $item->product->name,
                "quantity" => (int) $item->quantity,
                "unit_price" => (float) $item->price,
                "currency_id" => "ARS",
            ];
        }

        $request = [
            "items" => $items,
            "back_urls" => [
                "success" => config('app.frontend_url') . "/checkout/success",
                "failure" => config('app.frontend_url') . "/checkout/failure",
                "pending" => config('app.frontend_url') . "/checkout/pending",
            ],
            "external_reference" => (string) $order->order_number,
            "statement_descriptor" => "KEMAZON",
        ];

        \Log::debug("MercadoPago Preference Request Payload:", $request);

        try {
            $preference = $client->create($request);
            return [
                'id' => $preference->id,
                'init_point' => $preference->init_point,
                'sandbox_init_point' => $preference->sandbox_init_point,
            ];
        } catch (MPApiException $e) {
            \Log::error("MercadoPago Preference Creation Error: " . $e->getMessage(), [
                'response' => $e->getApiResponse()->getContent() ?? 'No content',
            ]);
            throw $e;
        } catch (\Exception $e) {
            \Log::error("General Error during MP Preference Creation: " . $e->getMessage());
            throw $e;
        }
    }
}
