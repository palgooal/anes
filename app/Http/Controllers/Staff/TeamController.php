<?php

namespace App\Http\Controllers\Staff;

use App\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreTeamMemberRequest;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\PhoneNumber;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Administration: the team list, adding a member and rejecting join requests.
 */
class TeamController extends Controller
{
    public function index(Request $request): View
    {
        $status = AccountStatus::tryFrom($request->string('status')->toString());
        $search = trim($request->string('q')->toString());
        $searchDigits = ltrim(PhoneNumber::digits($search), '0');

        $members = User::staff()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search, $searchDigits) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->when(strlen($searchDigits) >= 3, fn ($query) => $query->orWhere('phone', 'like', "%{$searchDigits}%"));
            }))
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        /** @var array<string, int> $counts */
        $counts = User::staff()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return view('staff.team.index', [
            'members' => $members,
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
            'roles' => array_values(array_filter(UserRole::cases(), fn (UserRole $role) => $role->isStaff())),
            'countries' => PhoneNumber::allowedCountries(),
        ]);
    }

    /**
     * Add a member directly: the account is active and an invitation to set a password is emailed.
     */
    public function store(StoreTeamMemberRequest $request): RedirectResponse
    {
        $member = User::create([...$request->validated(), 'status' => AccountStatus::Active]);

        $member->notify(new TeamInvitationNotification(Password::createToken($member)));

        return redirect()->route('staff.team')
            ->with('status', "أُضيف {$member->name} وأُرسلت إليه دعوة تعيين كلمة المرور على {$member->email}.");
    }

    /**
     * Reject a join request. Only pending applications can be deleted.
     */
    public function destroy(User $member): RedirectResponse
    {
        abort_unless($member->role->isStaff(), 404);

        if ($member->status !== AccountStatus::Pending) {
            return back()->withErrors(['team' => 'لا يمكن حذف عضو مفعّل أو موقوف. استخدم «إيقاف» بدلاً من ذلك.']);
        }

        $member->delete();

        return redirect()->route('staff.team')->with('status', "رُفض طلب انضمام {$member->name}.");
    }
}
