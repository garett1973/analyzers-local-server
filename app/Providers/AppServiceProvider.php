<?php

namespace App\Providers;

use App\Http\Repositories\Interfaces\OrderRepositoryInterface;
use App\Http\Repositories\Interfaces\ResultRepositoryInterface;
use App\Http\Repositories\OrderRepository;
use App\Http\Repositories\ResultRepository;
use App\Http\Services\ImportService;
use App\Http\Services\Interfaces\ImportServiceInterface;
use App\Http\Services\Interfaces\OrderServiceInterface;
use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Http\Services\OrderService;
use App\Http\Services\ResultService;
use App\Jobs\SendNewResultToMainServer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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

        $this->app->bind(
            ResultServiceInterface::class,
            ResultService::class
        );

        $this->app->bind(
            ResultRepositoryInterface::class,
            ResultRepository::class
        );

        $this->app->bind(
            ImportServiceInterface::class,
            ImportService::class
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
