<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;

/** Editors (blog.manage) write and submit; publishers (blog.publish) can publish, schedule and delete permanently. */
class BlogPostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformStaff() && ($user->can('blog.view') || $user->can('blog.manage'));
    }

    public function view(User $user, BlogPost $post): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->editor($user);
    }

    public function update(User $user, BlogPost $post): bool
    {
        return $this->editor($user);
    }

    public function publish(User $user, ?BlogPost $post = null): bool
    {
        return $user->isPlatformStaff() && $user->can('blog.publish');
    }

    public function delete(User $user, BlogPost $post): bool
    {
        return $this->editor($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->editor($user);
    }

    public function restore(User $user, BlogPost $post): bool
    {
        return $this->editor($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->editor($user);
    }

    public function forceDelete(User $user, BlogPost $post): bool
    {
        return $this->publish($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->publish($user);
    }

    /** The blog belongs to the marketplace: only platform-company staff edit it. */
    private function editor(User $user): bool
    {
        return $user->isPlatformStaff() && $user->can('blog.manage');
    }
}
