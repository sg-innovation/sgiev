<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Database\Seeder;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['name' => 'EV Basics', 'slug' => 'ev-basics', 'color' => 'sky', 'description' => 'Charging explained simply: kW, kWh, connectors and more.'],
            ['name' => 'Guides', 'slug' => 'guides', 'color' => 'emerald', 'description' => 'Step-by-step help for using Trans EV chargers.'],
            ['name' => 'Costs & Savings', 'slug' => 'costs-and-savings', 'color' => 'amber', 'description' => 'What EV charging costs in India and how to save.'],
            ['name' => 'Business & Fleets', 'slug' => 'business-and-fleets', 'color' => 'violet', 'description' => 'Charging, invoicing and GST for companies and fleets.'],
            ['name' => 'Road Trips', 'slug' => 'road-trips', 'color' => 'teal', 'description' => 'Plan longer journeys with confidence.'],
            ['name' => 'Company News', 'slug' => 'company-news', 'color' => 'rose', 'description' => 'Updates from the Trans EV team.'],
        ])->mapWithKeys(fn ($c, $i) => [$c['slug'] => BlogCategory::query()->updateOrCreate(['slug' => $c['slug']], $c + ['sort' => $i])]);

        $tag = fn (string $name) => BlogTag::query()->firstOrCreate(['slug' => \Illuminate\Support\Str::slug($name)], ['name' => $name])->id;
        $author = User::query()->where('email', 'admin@transevrecharge.test')->first() ?? User::query()->orderBy('id')->first();

        foreach ($this->posts() as $i => $p) {
            $post = BlogPost::query()->withTrashed()->firstOrNew(['slug' => $p['slug']]);
            $post->fill([
                'title' => $p['title'],
                'slug' => $p['slug'],
                'excerpt' => $p['excerpt'],
                'content' => $p['content'],
                'blog_category_id' => $categories[$p['category']]->id,
                'author_id' => $author?->id,
                'status' => 'published',
                'published_at' => now()->subDays(($i + 1) * 4)->setTime(10, 0),
                'is_featured' => $p['featured'] ?? false,
                'meta_title' => mb_substr($p['title'], 0, 70),
                'meta_description' => mb_substr($p['excerpt'], 0, 170),
            ])->save();
            $post->tags()->sync(collect($p['tags'])->map($tag));
        }
    }

    private function posts(): array
    {
        return [
            [
                'slug' => 'introducing-trans-ev',
                'title' => 'Introducing Trans EV: EV charging made simple',
                'category' => 'company-news',
                'featured' => true,
                'tags' => ['Trans EV', 'Announcements', 'Booking'],
                'excerpt' => 'Find a charger, book a guaranteed slot, pay online and get a GST invoice. Here is what Trans EV does and why we built it.',
                'content' => <<<'HTML'
<p>Electric vehicles are now part of everyday life in India, but charging still feels uncertain for many drivers. Will the charger work? Is someone already using it? What will it cost? <strong>Trans EV</strong> was built to remove that uncertainty.</p>
<h2>What you can do with Trans EV</h2>
<ul>
<li><strong>Find the nearest charger</strong> on a live map that sorts stations by distance from your location.</li>
<li><strong>See real-time status</strong> for every station and charger: online, offline or under maintenance.</li>
<li><strong>Book a guaranteed slot</strong> for the charger, date and time that suit you, up to seven days ahead.</li>
<li><strong>Pay securely online</strong> with UPI, cards or net-banking.</li>
<li><strong>Download a GST tax invoice</strong> for every session, with your GSTIN if you are a business.</li>
</ul>
<h2>Transparent by design</h2>
<p>Every station page shows the price per kWh, the booking fee, the idle fee and the GST rate before you book. During booking you see an estimate based on your vehicle's battery and how much you want to charge, so the amount you pay is never a surprise.</p>
<h2>Safety first</h2>
<p>Before each booking you read and accept the station rules. They cover safe connection, idle time and what to do if something looks wrong. Our stations are monitored, and every status change is logged so problems are fixed quickly.</p>
<h2>Where we are</h2>
<p>We are headquartered in Patna and are opening hubs in major cities and along busy highways. Use the <a href="/stations">station finder</a> to see the network, and follow this blog for new locations and tips.</p>
HTML,
            ],
            [
                'slug' => 'ac-vs-dc-charging-explained',
                'title' => 'AC vs DC charging: which one should you use?',
                'category' => 'ev-basics',
                'tags' => ['AC charging', 'DC fast charging', 'Connectors'],
                'excerpt' => 'AC chargers are gentle and cheaper; DC chargers are fast. Learn how they differ and when to use each.',
                'content' => <<<'HTML'
<p>Every EV battery stores energy as direct current (DC), but the grid supplies alternating current (AC). The difference between AC and DC charging is simply <em>where</em> that conversion happens.</p>
<h2>AC charging</h2>
<p>With AC charging, the charger passes AC power to your car and the car's <strong>on-board charger</strong> converts it to DC. Because on-board chargers are small, speeds are typically 3.3 kW to 22 kW.</p>
<ul>
<li><strong>Best for:</strong> long stops at offices, malls, hotels, and two- or three-wheelers.</li>
<li><strong>Connectors:</strong> Type 2 for cars; Bharat AC-001 and Type 6 sockets for smaller vehicles.</li>
<li><strong>Cost:</strong> usually the lowest per kWh.</li>
</ul>
<h2>DC fast charging</h2>
<p>A DC charger converts power inside the charging station and feeds DC directly to the battery. This bypasses the car's on-board charger and allows much higher power, from 30 kW to 180 kW or more at highway hubs.</p>
<ul>
<li><strong>Best for:</strong> quick top-ups in the city and road trips.</li>
<li><strong>Connector:</strong> CCS2 is the standard for most new Indian EVs.</li>
<li><strong>Speed:</strong> most cars can go from 20% to 80% in about 30 to 60 minutes.</li>
</ul>
<h2>Why does charging slow down after 80%?</h2>
<p>To protect the battery, cars reduce charging power as the battery fills. On a DC charger, the last 20% can take almost as long as the first 60%. For most trips, charging to 80% and moving on saves time.</p>
<h2>Which should you choose?</h2>
<p>If you have time, AC is gentle and economical. If you need range quickly, choose DC. On Trans EV, each station page lists its AC and DC chargers, their power and connectors, so you can pick the right one when you <a href="/stations">book</a>.</p>
HTML,
            ],
            [
                'slug' => 'ev-charging-cost-india-guide',
                'title' => 'How much does EV charging cost in India? A 2026 guide',
                'category' => 'costs-and-savings',
                'featured' => true,
                'tags' => ['Pricing', 'GST', 'Savings'],
                'excerpt' => 'From per-kWh rates to booking fees and GST, here is how public charging costs are calculated, with a worked example.',
                'content' => <<<'HTML'
<p>Public EV charging in India is priced mainly per <strong>kilowatt-hour (kWh)</strong>, the unit of energy delivered to your car. Here is what makes up the price you pay.</p>
<h2>1. Energy charge</h2>
<p>Typical public rates in 2026, before GST, are around:</p>
<ul>
<li>AC 3.3–7.4 kW: ₹10–18 per kWh</li>
<li>AC 22 kW: ₹14–18 per kWh</li>
<li>DC 30–60 kW: ₹18–24 per kWh</li>
<li>DC 120 kW and above: ₹22–26 per kWh</li>
</ul>
<p>Each Trans EV station shows its exact rate on the station page.</p>
<h2>2. Booking fee</h2>
<p>A small fixed fee per session covers reserving the charger for you. At Trans EV it is ₹20.</p>
<h2>3. Idle fee</h2>
<p>If your car stays plugged in after charging finishes, an idle fee per minute applies after a short grace period. It keeps chargers free for the next driver, so move your car promptly to avoid it.</p>
<h2>4. GST</h2>
<p>Charging is billed as a service and GST is added to the taxable value (energy plus booking fee). Your tax invoice shows CGST and SGST, or IGST, depending on where the station and the supplier are registered.</p>
<h2>Worked example</h2>
<p>A car with a 40 kWh battery charges from 20% to 80% on a DC fast charger at ₹21 per kWh:</p>
<ul>
<li>Energy needed: 40 × 60% = 24 kWh, plus about 10% charging losses ≈ 26.7 kWh</li>
<li>Energy charge: 26.7 × ₹21 ≈ ₹560</li>
<li>Booking fee: ₹20</li>
<li>GST at 18%: ≈ ₹104</li>
<li><strong>Total: about ₹684</strong> for roughly 165 km of range</li>
</ul>
<p>That works out to around ₹4 per km. Try different numbers with the cost calculator on our <a href="/">home page</a>.</p>
HTML,
            ],
            [
                'slug' => 'how-to-book-ev-charging-slot',
                'title' => 'How to book an EV charging slot on Trans EV (step by step)',
                'category' => 'guides',
                'tags' => ['Booking', 'How-to', 'Trans EV'],
                'excerpt' => 'Reserve a charger in under a minute: choose a station, pick a slot, add your vehicle, accept the rules and pay.',
                'content' => <<<'HTML'
<p>Booking ahead means your charger is waiting for you when you arrive. Here is how it works.</p>
<h2>Step 1: Find a station</h2>
<p>Open <a href="/stations">Find stations</a> and tap <strong>Near me</strong> to sort stations by distance, or search by city, area or PIN code. Filters let you choose AC or DC, connector type, minimum power and stations that are open now.</p>
<h2>Step 2: Choose charger, date and time</h2>
<p>On the booking page, pick a charger, a date up to seven days ahead and how long you need. Available start times are shown in green; booked or closed times are greyed out.</p>
<h2>Step 3: Add your vehicle</h2>
<p>Enter your car's make, model, registration number and battery size, then set your current and target charge. You will see the estimated energy, time and price straight away. Tick <em>Save this vehicle</em> to book faster next time.</p>
<h2>Step 4: Your details</h2>
<p>Your name, email and mobile number appear on your receipt and tax invoice. Businesses can add a GSTIN to claim input tax credit where eligible.</p>
<h2>Step 5: Accept the station rules</h2>
<p>Read the station rules to the end and accept them. Your acceptance is saved with the booking.</p>
<h2>Step 6: Reserve and pay</h2>
<p>Your slot is held for 10 minutes while you pay. Once payment succeeds, your booking is confirmed and your invoice is ready in <strong>My bookings</strong>.</p>
<h2>On the day</h2>
<p>Arrive on time: if charging does not start within 10 minutes of your slot, it may be released to other drivers. Need to cancel? You can do it from <strong>My bookings</strong>, subject to the <a href="/refund-policy">refund policy</a>.</p>
HTML,
            ],
            [
                'slug' => 'ev-charging-etiquette',
                'title' => 'Charging etiquette: 8 habits every EV driver should know',
                'category' => 'guides',
                'tags' => ['Safety', 'Etiquette', 'Idle fees'],
                'excerpt' => 'Simple habits that keep charging stations safe, fair and fast for everyone.',
                'content' => <<<'HTML'
<p>Public chargers are shared spaces. These habits keep them working well for everyone.</p>
<ol>
<li><strong>Book, then be on time.</strong> A booking holds a charger for you; arriving late keeps it idle for others.</li>
<li><strong>Charge, don't park.</strong> Charging bays are for vehicles that are charging. Move your car when you are done.</li>
<li><strong>Stop at 80% when others are waiting.</strong> The last 20% is slow on DC chargers and holds up the queue.</li>
<li><strong>Handle cables with care.</strong> Coil them back on the holder and never leave them on the ground where they can be driven over.</li>
<li><strong>Use the right connector.</strong> Never force a plug. If it does not fit easily, it is not the right one.</li>
<li><strong>Report problems.</strong> If a charger shows damage, sparks or a burning smell, stop and call the helpline.</li>
<li><strong>Keep the area safe.</strong> No smoking or open flames near chargers, and keep children and pets away from equipment.</li>
<li><strong>Be kind.</strong> A friendly word to the next driver goes a long way while EV infrastructure grows.</li>
</ol>
<p>Many of these points are part of our <a href="/safety">charging safety guide</a> and the station rules you accept when booking.</p>
HTML,
            ],
            [
                'slug' => 'gst-invoice-ev-charging-business',
                'title' => 'GST invoices for EV charging: a guide for businesses and fleets',
                'category' => 'business-and-fleets',
                'tags' => ['GST', 'Invoices', 'Fleets'],
                'excerpt' => 'How to get a GST tax invoice with your GSTIN, what it contains, and what to check with your accountant.',
                'content' => <<<'HTML'
<p>If your company pays for EV charging, a proper tax invoice matters for accounting and audits. Here is what Trans EV provides and how to use it.</p>
<h2>Getting an invoice with your GSTIN</h2>
<p>Enter your company's GSTIN in the <em>Your details</em> step when you book. It is validated for format and printed on the invoice together with your registered name.</p>
<h2>What the invoice contains</h2>
<ul>
<li>Supplier name, address and GSTIN, and a unique invoice number for the financial year</li>
<li>Your name, GSTIN (if provided) and the place of supply</li>
<li>Service description with SAC code, energy (kWh), rate and taxable value</li>
<li>CGST and SGST, or IGST, shown separately with rates and amounts</li>
<li>Payment reference and date</li>
</ul>
<h2>Refunds and credit notes</h2>
<p>If a booking is cancelled or the final amount is lower than the estimate, a credit note is issued against the original invoice so your books stay balanced.</p>
<h2>Can you claim input tax credit?</h2>
<p>Whether GST paid on charging can be claimed as input tax credit depends on how the vehicle is used in your business, because the GST law restricts credit for some motor-vehicle-related expenses. Please confirm with your chartered accountant for your specific case.</p>
<h2>Fleet accounts</h2>
<p>Running cabs, delivery vehicles or a corporate fleet? We offer centralised billing and priority booking at highway hubs. <a href="/contact">Contact our team</a> and choose “Fleet / business charging”.</p>
HTML,
            ],
            [
                'slug' => 'planning-ev-road-trip-india',
                'title' => 'Planning an EV road trip in India: a practical checklist',
                'category' => 'road-trips',
                'tags' => ['Road trips', 'Highway charging', 'Planning'],
                'excerpt' => 'Range planning, charging stops and booking tips for stress-free highway journeys in an electric car.',
                'content' => <<<'HTML'
<p>Long trips in an EV are easy with a little planning. Use this checklist before you set off.</p>
<h2>Before you leave</h2>
<ul>
<li><strong>Know your real range.</strong> Highway speeds, air-conditioning and luggage reduce range. Plan with about 75–80% of the claimed figure.</li>
<li><strong>Start full.</strong> Charge to 100% at home or on an AC charger the night before.</li>
<li><strong>Map your stops.</strong> Aim to arrive at each charger with 15–20% battery left, not close to zero.</li>
</ul>
<h2>Charging on the way</h2>
<ul>
<li><strong>Use DC ultra-fast hubs.</strong> Highway hubs with 120 kW or more get you back on the road fastest.</li>
<li><strong>Book ahead on busy days.</strong> Weekends and holidays are peak times. A booking guarantees your charger.</li>
<li><strong>Charge to 80%, not 100%.</strong> Above 80% charging slows down; two shorter stops are often quicker than one long one.</li>
<li><strong>Combine stops.</strong> Plan charging with meals and rest breaks. Our highway hubs have food courts and restrooms.</li>
</ul>
<h2>Example: Mumbai to Pune</h2>
<p>The Mumbai–Pune Expressway is about 150 km. Most modern EVs can make the trip on a single charge. If you start below 60%, a 20-minute stop at our expressway hub near Lonavala adds plenty of buffer.</p>
<h2>Useful links</h2>
<p>Find highway hubs with the <a href="/stations">station finder</a> (filter for 120 kW+), and check prices on each station page before you go.</p>
HTML,
            ],
        ];
    }
}
