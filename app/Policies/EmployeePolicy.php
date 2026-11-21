<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeePolicy
{
    /**
     * An employee sees only their own agenda; an admin sees every agenda.
     * Customers never see an agenda (they see free slots, ISSUE-11/15).
     *
     * Answers 404 instead of 403, so agendas cannot be probed by id.
     */
    public function viewAgenda(User $user, Employee $employee): Response
    {
        $allowed = $user->isAdmin()
            || ($user->isEmployee() && (int) $employee->user_id === (int) $user->id);

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }
}
