<?php

namespace App\Http\Controllers;

use App\Rules\ValidGstin;
use App\Services\FranchiseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** G3: public "Become a franchise partner" page and application form. Trans EV reviews it in Admin → Franchises. */
class FranchiseApplicationController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('franchise', [
            'states' => array_values(array_filter(array_map(fn ($c) => \App\Support\IndianStates::name((string) $c), array_keys(\App\Support\IndianStates::options())))),
        ]);
    }

    public function store(Request $request, FranchiseService $franchises): RedirectResponse
    {
        if (filled($request->input('website'))) { // honeypot
            return back()->with('success', 'Thank you! We have received your application.');
        }
        $request->merge(['phone' => preg_replace('/\D/', '', (string) $request->input('phone')) ?: null, 'gstin' => strtoupper(trim((string) $request->input('gstin'))) ?: null]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['required', 'string', 'max:60'],
            'region' => ['required', 'string', 'max:120'],
            'gstin' => ['nullable', new ValidGstin],
            'message' => ['nullable', 'string', 'max:3000'],
            'consent' => ['accepted'],
        ], [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'consent.accepted' => 'Please allow us to contact you about this application.',
        ]);
        $f = $franchises->apply($data);

        return back()->with('success', "Thank you! Your application {$f->code} has been received. Our partnerships team will contact you within a few working days.");
    }
}
