<?php

namespace App\Providers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseCuttingLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Policies\BatchItemPolicy;
use App\Policies\BatchPolicy;
use App\Policies\CheeseCuttingLogPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\OrderAllocationPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model-to-policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        Customer::class => CustomerPolicy::class,
        Product::class => ProductPolicy::class,
        ProductVariant::class => ProductVariantPolicy::class,
        Batch::class => BatchPolicy::class,
        BatchItem::class => BatchItemPolicy::class,
        CheeseCuttingLog::class => CheeseCuttingLogPolicy::class,
        Order::class => OrderPolicy::class,
        OrderItem::class => OrderItemPolicy::class,
        OrderAllocation::class => OrderAllocationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Column-level gate used by blade views to hide prices/totals from
        // factory users who share order and product screens with office staff.
        Gate::define('see-financials', fn ($user) => $user->hasRole('admin', 'office'));
    }
}
