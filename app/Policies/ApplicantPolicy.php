<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\User;

/**
 * All active HR users can view and manage all applicants (confirmed client
 * requirement: no assignment restrictions). Applicants never hold a User
 * account, so they can never pass these checks.
 */
class ApplicantPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isDisabled();
    }

    public function view(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled();
    }

    public function create(User $user): bool
    {
        return ! $user->isDisabled();
    }

    public function update(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled();
    }

    public function invite(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled() && $applicant->transferred_at === null;
    }

    public function review(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled();
    }

    public function download(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled();
    }

    public function transfer(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled();
    }

    public function delete(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled() && ! $applicant->legal_hold;
    }

    public function manageLegalHold(User $user, Applicant $applicant): bool
    {
        return ! $user->isDisabled();
    }
}
