<?php

namespace App\Http\Controllers\Staff;

use App\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateTeamMemberStatusRequest;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;

/**
 * Approve a join request, suspend a member or reactivate them.
 */
class TeamMemberStatusController extends Controller
{
    public function update(UpdateTeamMemberStatusRequest $request, User $member): RedirectResponse
    {
        abort_unless($member->role->isStaff(), 404);

        if ($member->is($request->user())) {
            return back()->withErrors(['team' => 'لا يمكنك تغيير حالة حسابك أنت.']);
        }

        $newStatus = $request->enum('status', AccountStatus::class);
        $wasPending = $member->status === AccountStatus::Pending;

        $member->update(['status' => $newStatus]);

        if ($wasPending && $newStatus === AccountStatus::Active && ! $member->password) {
            $member->notify(new TeamInvitationNotification(Password::createToken($member)));

            return back()->with('status', "فُعّل حساب {$member->name} وأُرسلت إليه دعوة تعيين كلمة المرور.");
        }

        return back()->with('status', match ($newStatus) {
            AccountStatus::Suspended => "أُوقف حساب {$member->name}، ولن يستطيع الدخول حتى يُعاد تفعيله.",
            default => "فُعّل حساب {$member->name}.",
        });
    }
}
