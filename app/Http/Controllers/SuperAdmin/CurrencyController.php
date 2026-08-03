<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Services\CurrencyCatalogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    public function __construct(private readonly CurrencyCatalogService $catalog) {}

    public function index(Request $request)
    {
        $query = Currency::query()->orderBy('sort_order')->orderBy('name');
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(fn ($builder) => $builder->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        return response()->json(['success' => 1, 'currencies' => $query->get()]);
    }

    public function active()
    {
        return response()->json(['success' => 1, 'currencies' => $this->catalog->active()->values()]);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->code))]);
        $currency = Currency::create($this->validated($request));
        $this->catalog->forget();
        return response()->json(['success' => 1, 'message' => 'Currency created successfully', 'currency' => $currency], 201);
    }

    public function update(Request $request, Currency $currency)
    {
        $currency->fill($this->validated($request, $currency))->save();
        $this->catalog->forget();
        return response()->json(['success' => 1, 'message' => 'Currency updated successfully', 'currency' => $currency->fresh()]);
    }

    public function updateStatus(Request $request, Currency $currency)
    {
        $currency->update($request->validate(['is_active' => ['required', 'boolean']]));
        $this->catalog->forget();
        return response()->json(['success' => 1, 'message' => $currency->is_active ? 'Currency activated successfully' : 'Currency deactivated successfully', 'currency' => $currency->fresh()]);
    }

    private function validated(Request $request, ?Currency $currency = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:12'],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
            'symbol_position' => ['required', Rule::in(['before', 'after'])],
            'is_active' => ['required', 'boolean'],
            'exchange_enabled' => ['required', 'boolean'],
            'stripe_enabled' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
        if (! $currency) {
            $rules['code'] = ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::unique('central.currencies', 'code')];
        }
        return $request->validate($rules);
    }
}
