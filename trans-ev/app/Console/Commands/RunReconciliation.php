<?php

namespace App\Console\Commands;

use App\Services\ReconciliationService;
use Illuminate\Console\Command;

/** B8: cross-check gateway, payments, bookings, refunds, GST documents and wallets; fill the exceptions queue. */
class RunReconciliation extends Command
{
    protected $signature = 'reconciliation:run {--no-enquiry : Skip gateway enquiries (database checks only)}';

    protected $description = 'Run payment reconciliation and update the exceptions queue';

    public function handle(ReconciliationService $service): int
    {
        $run = $service->run(enquire: ! $this->option('no-enquiry'));
        if ($run->error) {
            $this->error('Reconciliation failed: '.$run->error);

            return self::FAILURE;
        }
        $this->info("Run #{$run->id}: {$run->new_exceptions} new, {$run->cleared_exceptions} cleared, {$run->open_exceptions} open.");

        return self::SUCCESS;
    }
}
