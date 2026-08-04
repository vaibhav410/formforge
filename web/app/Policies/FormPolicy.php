<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Form;
use App\Models\User;

/**
 * Ownership is the tenant boundary: every form-scoped action requires
 * the authenticated user to own the form. Auto-discovered by Laravel.
 */
class FormPolicy
{
    public function view(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }

    public function update(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }

    public function delete(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }

    public function publish(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }

    public function viewSubmissions(User $user, Form $form): bool
    {
        return $form->user_id === $user->id;
    }
}
