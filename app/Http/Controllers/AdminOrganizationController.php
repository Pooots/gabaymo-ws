<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Notifications\OrganizationInviteNotification;
use App\Services\Billing\SubscriptionService;
use App\Support\Paginates;
use App\Support\PhilippineRegions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/** Group plans: members of an active organization get its plan until its period_end. */
class AdminOrganizationController extends Controller
{
    use Paginates;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request)
    {
        $query = Organization::query()->with('plan')->latest('id');
        $this->search($request, $query, ['name', 'region']);

        return response()->json($this->paginate($request, $query, fn (Organization $o) => $o->present()));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        $organization = Organization::query()->create($data);
        AdminOverviewController::record($request, 'organization.create', 'Created organization '.$organization->name.' ('.$organization->seats.' seats)');

        return response()->json(['organization' => $organization->fresh('plan')->present()], 201);
    }

    public function update(Request $request, Organization $organization)
    {
        $data = $this->validated($request, false);
        if (isset($data['seats']) && $data['seats'] < $organization->seatsUsed()) {
            return response()->json(['message' => 'Remove members or invites before lowering the seats below what is used.', 'errors' => ['seats' => ['Seats cannot go below the seats in use.']]], 422);
        }
        $organization->update($data);
        $this->subscriptions->syncOrganization($organization->fresh('plan'));
        AdminOverviewController::record($request, 'organization.update', 'Updated organization '.$organization->name);

        return response()->json(['organization' => $organization->fresh('plan')->present()]);
    }

    /** Detach every member (each falls back to their own or the free plan), drop invites, then delete. */
    public function destroy(Request $request, Organization $organization)
    {
        $name = $organization->name;
        $detached = 0;
        $organization->members()->select('id')->chunkById(200, function ($members) use (&$detached) {
            foreach ($members as $member) {
                $this->subscriptions->leaveOrganization($member);
                $detached++;
            }
        });
        DB::transaction(function () use ($organization) {
            $organization->invites()->delete();
            $organization->delete();
        });
        AdminOverviewController::record($request, 'organization.delete', 'Deleted organization '.$name.' ('.$detached.' '.($detached === 1 ? 'member' : 'members').' detached)');

        return response()->json(['ok' => true, 'members_detached' => $detached]);
    }

    /** Attach an existing member, or invite an email that has no account yet. */
    public function addMember(Request $request, Organization $organization)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:120']]);
        $email = mb_strtolower(trim($data['email']));

        $result = DB::transaction(function () use ($organization, $email) {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user && ! $user->isCustomer()) {
                return ['error' => 'Only member accounts can join an organization.'];
            }
            if ($user && (int) $user->organization_id === (int) $locked->id) {
                return ['error' => 'This member is already in the organization.'];
            }
            if ($user && $user->organization_id) {
                return ['error' => 'This member belongs to another organization.'];
            }
            if (! $user && $locked->invites()->where('email', $email)->exists()) {
                return ['error' => 'This email is already invited.'];
            }
            if ($locked->seatsUsed() >= $locked->seats) {
                return ['error' => 'All '.$locked->seats.' seats are in use.'];
            }
            if ($user) {
                return ['user' => $user];
            }
            OrganizationInvite::query()->create(['organization_id' => $locked->id, 'email' => $email]);

            return ['invited' => $email];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error'], 'errors' => ['email' => [$result['error']]]], 422);
        }

        if (isset($result['user'])) {
            $this->subscriptions->joinOrganization($result['user'], $organization->fresh('plan'));
            AdminOverviewController::record($request, 'organization.member_add', 'Added '.$result['user']->email.' to '.$organization->name);
            $status = 'attached';
        } else {
            try {
                Notification::route('mail', $result['invited'])->notify(new OrganizationInviteNotification($organization));
            } catch (\Throwable $e) {
                report($e);
            }
            AdminOverviewController::record($request, 'organization.member_invite', 'Invited '.$result['invited'].' to '.$organization->name);
            $status = 'invited';
        }

        return response()->json(['status' => $status, 'organization' => $organization->fresh('plan')->present()], 201);
    }

    public function removeMember(Request $request, Organization $organization, User $user)
    {
        if ((int) $user->organization_id !== (int) $organization->id) {
            return response()->json(['message' => 'This member is not in the organization.'], 404);
        }
        $this->subscriptions->leaveOrganization($user);
        AdminOverviewController::record($request, 'organization.member_remove', 'Removed '.$user->email.' from '.$organization->name);

        return response()->json(['organization' => $organization->fresh('plan')->present()]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'type' => [$required, Rule::in(Organization::TYPES)],
            'region' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', Rule::in(PhilippineRegions::NAMES)],
            'seats' => [$required, 'integer', 'min:1', 'max:100000'],
            'subscription_plan_id' => [$required, 'integer', Rule::exists('subscription_plans', 'id')->where('active', true)->where(fn ($q) => $q->where('price', '>', 0))],
            'period_end' => [$required, 'date', 'after:now'],
        ]);
    }
}
