<?php

namespace App\Enums;

/**
 * What a user may do in the system. See docs/erd.md.
 *
 * Self-registration always creates a customer; employees and admins are
 * created by an admin. Authorization per role is handled in ISSUE-6.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case Employee = 'employee';
    case Admin = 'admin';
}
