<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use App\Notifications\DepartmentMessageNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Sends an inter-department message (FR DEPT-2): a bell notification to every
 * member of the target department, except the sender. Returns the recipient
 * count.
 */
class DepartmentMessageService
{
    public function send(Department $to, User $from, string $body): int
    {
        $fromDept = $from->departments()->first()?->name;
        $label = $from->name.($fromDept ? " ({$fromDept})" : '');

        $recipients = $to->members()
            ->where('users.id', '!=', $from->id)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new DepartmentMessageNotification($body, $label));
        }

        return $recipients->count();
    }
}
