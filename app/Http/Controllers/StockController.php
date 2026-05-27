<?php

namespace App\Http\Controllers;

use App\Services\StockOverviewService;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(StockOverviewService $service): View
    {
        return view('stock.overview', $service->build());
    }
}
