<?php

namespace App\Providers;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseConversionLog;
use App\Models\CheeseCuttingLog;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Statement;
use App\Models\StockWriteOff;
use App\Models\User;
use App\Models\WriteOffReason;
use App\Policies\BatchItemPolicy;
use App\Policies\BatchPolicy;
use App\Policies\CheeseConversionLogPolicy;
use App\Policies\CheeseCuttingLogPolicy;
use App\Policies\CreditNotePolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DeliveryRunPolicy;
use App\Policies\DocumentEmailPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrderAllocationPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\SettingPolicy;
use App\Policies\StatementPolicy;
use App\Policies\StockWriteOffPolicy;
use App\Policies\UserPolicy;
use App\Policies\WriteOffReasonPolicy;
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
        Setting::class => SettingPolicy::class,
        Customer::class => CustomerPolicy::class,
        DeliveryRun::class => DeliveryRunPolicy::class,
        Product::class => ProductPolicy::class,
        ProductVariant::class => ProductVariantPolicy::class,
        Batch::class => BatchPolicy::class,
        BatchItem::class => BatchItemPolicy::class,
        CheeseCuttingLog::class => CheeseCuttingLogPolicy::class,
        CheeseConversionLog::class => CheeseConversionLogPolicy::class,
        StockWriteOff::class => StockWriteOffPolicy::class,
        WriteOffReason::class => WriteOffReasonPolicy::class,
        Order::class => OrderPolicy::class,
        OrderItem::class => OrderItemPolicy::class,
        OrderAllocation::class => OrderAllocationPolicy::class,
        // Finance — FinancialPolicy narrows BasePolicy so factory can't view.
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
        CreditNote::class => CreditNotePolicy::class,
        Statement::class => StatementPolicy::class,
        DocumentEmail::class => DocumentEmailPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Column-level gate used by blade views to hide prices/totals from
        // factory users who share order and product screens with office staff.
        Gate::define('see-financials', fn ($user) => $user->hasRole('admin', 'office'));
    }
}
