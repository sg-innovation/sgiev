<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $company = Company::primary();

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? $request->user()->only(['id', 'name', 'email', 'email_verified_at', 'city']) + ['avatar' => $request->user()->avatarUrl()] : null,
                'isStaff' => (bool) $request->user()?->isStaff(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'brand' => [
                'name' => $company?->trade_name ?? 'Trans EV',
                'legalName' => $company?->legal_name,
                'email' => $company?->email,
                'phone' => $company?->phone,
                'address' => $company ? collect([$company->address_line1, $company->address_line2, "{$company->city}, {$company->state} {$company->pincode}"])->filter()->implode(', ') : null,
                'gstin' => $company?->gstin,
                'whatsapp' => $company?->social_links['whatsapp'] ?? null,
                'social' => $company?->social_links ?? [],
            ],
            'layout' => fn () => \App\Support\SiteContent::layout(),
        ]);
    }
}
