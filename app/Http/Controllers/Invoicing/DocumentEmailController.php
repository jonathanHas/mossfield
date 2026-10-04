<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\DocumentEmailKind;
use App\Enums\DocumentEmailStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DocumentEmail;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The communications log — every invoice, credit note, statement, reminder and
 * docket we've sent, and every one that failed.
 */
class DocumentEmailController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DocumentEmail::class);

        $query = DocumentEmail::query()
            ->with(['customer', 'sender'])
            ->latest('id');

        if ($request->filled('kind')) {
            $query->where('kind', $request->kind);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        return view('invoicing.emails.index', [
            'emails' => $query->paginate(50)->withQueryString(),
            'kinds' => DocumentEmailKind::cases(),
            'statuses' => DocumentEmailStatus::cases(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['kind', 'status', 'customer_id']),
        ]);
    }
}
