<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * The platform operator (owner of transevrecharge.com), also an operator of its own Trans EV stations.
 * PAN is derived from the GSTIN (characters 3–12). Bank details are placeholders: set them in Admin → Companies.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, LookupSeeder::class, BusinessRulesSeeder::class, PageSeeder::class, SiteContentSeeder::class]);

        $company = Company::query()->updateOrCreate(['slug' => 'trans-ev'], [
            'legal_name' => 'TRANZACTA PRIVATE LIMITED',
            'trade_name' => 'Trans EV',
            'gstin' => '10AANCT1689C1ZG',
            'pan' => 'AANCT1689C',
            'address_line1' => 'Floor No 04, Flat No 402, C/O Rajpati Devi, Vatika, 70 Feet Road',
            'address_line2' => 'Bishnupur, Near Pakri, Beur',
            'city' => 'Patna',
            'state' => 'Bihar',
            'state_code' => '10',
            'pincode' => '800002', // provided as "80002"; Beur, Patna is 800002 — confirm
            'email' => 'support@transevrecharge.com',
            'phone' => '+91-9523095958',
            'support_email' => 'support@transevrecharge.com',
            'support_phone' => '+91-9523095958',
            'website' => 'https://transevrecharge.com',
            'invoice_prefix' => 'TEV',
            'bank_details' => null,
            'social_links' => [
                'facebook' => 'https://facebook.com/',
                'instagram' => 'https://instagram.com/',
                'x' => 'https://x.com/',
                'linkedin' => 'https://linkedin.com/',
                'youtube' => 'https://youtube.com/',
                'whatsapp' => 'https://wa.me/919523095958',
            ],
            'invoice_terms' => 'This is a computer-generated invoice. EV charging is billed as a service (SAC 998714).',
            'sabpaisa_env' => 'staging',
        ]);
        $company->forceFill(['is_platform' => true, 'status' => 'active', 'onboarded_at' => $company->onboarded_at ?? now(), 'plan_id' => \App\Models\Plan::where('slug', 'premium')->value('id')])->save();
    }
}
