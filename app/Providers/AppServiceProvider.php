<?php

namespace App\Providers;

use App\Http\Repositories\Interfaces\OrderRepositoryInterface;
use App\Http\Repositories\OrderRepository;
use App\Http\Services\Interfaces\OrderServiceInterface;
use App\Http\Services\OrderService;
use App\Services\SocketManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public $singletons = [
        SocketManager::class => SocketManager::class,
        ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            OrderRepositoryInterface::class,
            OrderRepository::class
        );

        $this->app->bind(
            OrderServiceInterface::class,
            OrderService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
