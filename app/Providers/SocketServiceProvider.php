<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SocketManager;

class SocketServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(SocketManager::class, function ($app) {
            return new SocketManager();
        });
    }

    public function boot()
    {
        //
    }
}

