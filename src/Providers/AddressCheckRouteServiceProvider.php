<?php

namespace HeistaAddressCheck\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class AddressCheckRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        $router->post(
            'address-check/callback',
            'HeistaAddressCheck\\Controllers\\CallbackController@receive'
        );
    }
}
