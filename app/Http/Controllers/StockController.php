<?php

namespace App\Http\Controllers;

use App\Models\BatchItem;
use App\Services\StockOverviewService;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(StockOverviewService $service): View
    {
        $this->authorize('viewAny', BatchItem::class);

        return view('stock.overview', $service->build());
    }
}
