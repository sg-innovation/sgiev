<?php

namespace Database\Seeders;

use App\Models\RuleSet;
use Illuminate\Database\Seeder;

class RuleSetSeeder extends Seeder
{
    public function run(): void
    {
        $rules = RuleSet::query()->firstOrCreate(
            ['company_id' => null, 'station_id' => null, 'version' => 1],
            [
                'title' => 'Trans EV Charging Station Rules',
                'content' => <<<'HTML'
<ol>
<li>Arrive within <strong>10 minutes</strong> of your slot start time, or the booking may be marked as a no-show.</li>
<li>Use only connectors compatible with your vehicle. Never force a plug into a socket.</li>
<li>Switch the vehicle off and apply the handbrake before connecting. No smoking or open flames within 5 metres of the charger.</li>
<li>Do not leave your vehicle beyond your booked slot. Idle fees apply after the grace period shown in the tariff.</li>
<li>Report any damage, sparks, burning smell or fault to station staff or the helpline immediately and stop charging.</li>
<li>Do not charge in standing water, or with a damaged cable, connector or vehicle charging inlet.</li>
<li>Children and pets must not handle charging equipment.</li>
<li>Trans EV is not liable for damage caused by vehicle defects or misuse. Follow your vehicle manufacturer’s charging guidelines.</li>
<li>Cancellations and refunds follow the published Refund &amp; Cancellation Policy.</li>
<li>The station is under CCTV surveillance. Follow staff instructions at all times.</li>
</ol>
HTML,
            ],
        );

        if (! $rules->is_active) {
            $rules->publish();
        }
    }
}
