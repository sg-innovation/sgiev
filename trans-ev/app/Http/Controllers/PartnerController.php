<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Plan;
use App\Models\User;
use App\Support\IndianStates;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Public self-registration for charging-station owners (SGI EV SRS §5.1). */
class PartnerController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('partner/register', [
            'states' => collect(IndianStates::STATES)->map(fn ($n, $c) => ['value' => (string) $c, 'label' => $n])->values(),
            'docTypes' => self::registrationDocuments()->map(fn ($l) => [
                'value' => $l->value, 'label' => $l->label, 'required' => (bool) ($l->meta['required'] ?? false), 'hint' => $l->description,
            ])->values(),
            'freeFeatures' => collect(Plan::FREE_FEATURES)->map(fn ($k) => Plan::FEATURES[$k])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (filled($request->input('website_url'))) {
            return to_route('partner.thanks'); // honeypot
        }

        $request->merge([
            'gstin' => $request->filled('gstin') ? strtoupper(trim((string) $request->input('gstin'))) : null,
            'pan' => strtoupper(trim((string) $request->input('pan'))),
            'ifsc' => strtoupper(trim((string) $request->input('ifsc'))),
            'phone' => preg_replace('/\D/', '', (string) $request->input('phone')),
            'account_number' => preg_replace('/\s/', '', (string) $request->input('account_number')),
        ]);

        $doc = File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(CompanyDocument::maxKb());
        $noTags = 'not_regex:/[<>]/';
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200', $noTags],
            'trade_name' => ['required', 'string', 'max:120', $noTags],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', new \App\Rules\ValidGstin, 'unique:companies,gstin'],
            'pan' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'address_line1' => ['required', 'string', 'max:200', $noTags],
            'city' => ['required', 'string', 'max:80', $noTags],
            'state_code' => ['required', Rule::in(array_keys(IndianStates::STATES))],
            'pincode' => ['required', 'regex:/^[1-9][0-9]{5}$/'],
            'expected_stations' => ['nullable', 'integer', 'between:1,10000'],

            'name' => ['required', 'string', 'max:120', $noTags],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],

            'account_name' => ['required', 'string', 'max:120', $noTags],
            'account_number' => ['required', 'regex:/^\d{9,18}$/'],
            'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank_name' => ['required', 'string', 'max:120', $noTags],

            'documents' => ['required', 'array'],
            ...self::registrationDocuments()->mapWithKeys(fn ($l) => ['documents.'.$l->value => [
                ($l->meta['required'] ?? false) ? 'required' : 'nullable',
                ...($l->value === 'gst_certificate' ? ['required_with:gstin'] : []),
                $doc,
            ]])->all(),

            'terms' => ['accepted'],
        ], [
            'gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'pan.regex' => 'Enter a valid PAN, e.g. ABCDE1234F.',
            'ifsc.regex' => 'Enter a valid IFSC, e.g. SBIN0001234.',
            'account_number.regex' => 'Enter a valid account number (9–18 digits).',
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'documents.gst_certificate.required_with' => 'Upload your GST certificate since you entered a GSTIN.',
            'gstin.unique' => 'A company with this GSTIN is already registered. Please contact support.',
        ]);

        if ($data['gstin'] && substr($data['gstin'], 2, 10) !== $data['pan']) {
            return back()->withErrors(['pan' => 'The PAN must match characters 3–12 of your GSTIN.'])->withInput();
        }

        $stored = [];
        try {
            $user = DB::transaction(function () use ($data, $request, &$stored) {
                $company = new Company([
                    'legal_name' => $data['legal_name'], 'trade_name' => $data['trade_name'], 'gstin' => $data['gstin'], 'pan' => $data['pan'],
                    'address_line1' => $data['address_line1'], 'city' => $data['city'], 'state_code' => $data['state_code'],
                    'state' => IndianStates::name($data['state_code']), 'pincode' => $data['pincode'],
                    'email' => strtolower($data['email']), 'phone' => $data['phone'], 'support_email' => strtolower($data['email']), 'support_phone' => $data['phone'],
                    'invoice_prefix' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $data['trade_name']), 0, 4)) ?: 'INV',
                    'bank_details' => ['account_name' => $data['account_name'], 'account_no' => $data['account_number'], 'ifsc' => $data['ifsc'], 'bank' => $data['bank_name']],
                    'sabpaisa_env' => 'staging',
                ]);
                $company->forceFill([
                    'status' => 'pending', 'is_platform' => false, 'billing_mode' => in_array(\App\Support\BusinessRules::get('platform_fees.default_billing_mode'), ['wallet', 'invoice'], true) ? \App\Support\BusinessRules::get('platform_fees.default_billing_mode') : 'wallet', 'submitted_at' => now(), 'plan_id' => Plan::default()?->id,
                    'terms_accepted_at' => now(), 'terms_version' => \App\Support\BusinessRules::get('partner.terms_version'),
                ])->save();

                $user = User::create([
                    'company_id' => $company->id, 'name' => $data['name'], 'email' => strtolower($data['email']),
                    'phone' => $data['phone'], 'password' => Hash::make($data['password']),
                ]);
                $user->assignRole(Role::CompanyOwner->value);

                foreach ($request->file('documents', []) as $type => $file) {
                    if ($file && array_key_exists($type, CompanyDocument::types())) {
                        $doc = CompanyDocument::storeFor($company, $type, $file, $user->id);
                        $stored[] = $doc->path;
                    }
                }
                activity()->performedOn($company)->causedBy($user)
                    ->withProperties(['expected_stations' => $data['expected_stations'] ?? null, 'ip' => $request->ip()])
                    ->log('partner registered');

                return $user;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path); // don't leave orphan KYC files behind
            }
            throw $e;
        }

        event(new Registered($user)); // email verification link

        return to_route('partner.thanks')->with('partner_email', $user->email);
    }

    /** KYC documents asked on the sign-up form (Lists & options → KYC document types, “on registration”). */
    private static function registrationDocuments(): \Illuminate\Support\Collection
    {
        return \App\Models\Lookup::list('kyc_document')->filter(fn ($l) => (bool) ($l->meta['on_registration'] ?? false))->values();
    }

    public function thanks(Request $request): Response
    {
        return Inertia::render('partner/thanks', ['email' => $request->session()->get('partner_email')]);
    }
}
