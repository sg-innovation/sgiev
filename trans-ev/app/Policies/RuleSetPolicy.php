<?php

namespace App\Policies;

use App\Models\RuleSet;
use App\Models\User;
use App\Support\Tenancy;

class RuleSetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rules.view');
    }

    public function view(User $user, RuleSet $ruleSet): bool
    {
        return $user->can('rules.view');
    }

    public function create(User $user): bool
    {
        return $user->can('rules.manage');
    }

    /** A published version is frozen (customers accepted that exact text). Publish a new version instead. */
    public function update(User $user, RuleSet $ruleSet): bool
    {
        return $this->manages($user, $ruleSet) && $ruleSet->published_at === null;
    }

    public function delete(User $user, RuleSet $ruleSet): bool
    {
        return $this->manages($user, $ruleSet) && $ruleSet->published_at === null;
    }

    /** Publish or create a new version. */
    public function publish(User $user, RuleSet $ruleSet): bool
    {
        return $this->manages($user, $ruleSet);
    }

    /** Platform default rules (no company) are managed only by platform admins. */
    private function manages(User $user, RuleSet $ruleSet): bool
    {
        if (! $user->can('rules.manage')) {
            return false;
        }

        return $ruleSet->company_id === null ? Tenancy::isPlatformAdmin($user) : Tenancy::owns($ruleSet->company_id, $user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
