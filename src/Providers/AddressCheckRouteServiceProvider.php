<?php

namespace HeistaAddressCheck\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router;

class AddressCheckRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router, ApiRouter $apiRouter): void
    {
        // Public REST route — served by the PlentyONE REST layer at
        // /rest/heista/address-check/callback. This is the primary callback
        // target: it is reachable even when the storefront is the headless
        // PlentyONE Shop (Nuxt PWA), which does NOT serve plentyShop frontend
        // routes (the legacy `address-check/callback` route below 404s on a PWA
        // shop). `['middleware' => []]` registers it WITHOUT the `oauth` guard,
        // so our SaaS can call it with only the `X-Heista-Secret` shared secret
        // (no PlentyONE OAuth token). Auth is enforced in CallbackController.
        $apiRouter->version(['v1'], ['middleware' => []], function ($router) {
            $router->post(
                'heista/address-check/callback',
                ['uses' => 'HeistaAddressCheck\\Controllers\\CallbackController@receive']
            );
        });

        // Legacy plentyShop frontend route — kept for backward compatibility
        // with classic (Twig/Ceres) storefronts. Unreachable on a PWA shop.
        $router->post(
            'address-check/callback',
            'HeistaAddressCheck\\Controllers\\CallbackController@receive'
        );
    }
}
