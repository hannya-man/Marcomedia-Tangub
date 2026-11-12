<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsWithTwoFactor;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Support\EmailRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use ConfirmsWithTwoFactor;

    public function index(Request $request)
    {
        $users = User::when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('email', 'like', '%' . $request->search . '%');
                });
            })
            ->orderBy('name')->get();
        $editingUser = $request->filled('edit') ? User::find($request->query('edit')) : null;
        $emailRecovery = EmailRecovery::available();

        return view('pages.users', compact('users', 'editingUser', 'emailRecovery'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'role' => 'required|in:admin,cashier',
            'password' => ['required', 'string', Password::defaults()],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('users.index')->with('success', "Account made for {$validated['name']} (" . ($validated['role'] === 'admin' ? 'owner' : 'cashier') . ').');
    }

    // Handles editing ANY user's name/email/role/password, including the
    // logged-in admin editing their own account from this screen.
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email,' . $user->id,
            'role' => 'required|in:admin,cashier',
            'password' => ['nullable', 'string', Password::defaults()],
        ]);

        // The last owner can't become a cashier: no one could reach the dashboard or User
        // Management again to undo it.
        if ($user->role === 'admin' && $validated['role'] !== 'admin' && User::where('role', 'admin')->count() <= 1) {
            return back()->withInput()->with('error', "{$user->name} is the only owner account. Make someone else an owner first.");
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        // A new email isn't confirmed yet: links to log back in (EmailRecovery) wait until it is.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->role = $validated['role'];
        $user->save();
        // A forgotten password, set by the owner: like the emailed link, it logs them out everywhere else.
        if (! empty($validated['password'])) {
            $user->changePassword($validated['password']);
        }

        return redirect()->route('users.index')->with('success', "{$user->name}'s account updated."
            . (empty($validated['password']) ? '' : ' The new password works now, and any other device they were logged in on is logged out.'));
    }

    // Deletes a cashier's account, confirmed with the owner's authenticator code. The account is
    // only marked deleted: it can't log in and leaves this list, while the sales, shifts and stock
    // history it made keep its name. Its email is freed for a new account.
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', "You can't delete your own account.");
        }
        if ($user->role !== 'cashier') {
            return back()->with('error', "{$user->name} is an owner. Only cashier accounts can be deleted: make them a cashier first.");
        }

        $this->confirmWithTwoFactor($request, 'code', 'deleteUser');

        DB::transaction(function () use ($user) {
            // What they were still waiting on can't be answered to anyone now.
            ApprovalRequest::where('requested_by', $user->id)->where('status', 'pending')->update([
                'status' => 'cancelled', 'decision_note' => 'The account was deleted.', 'decided_at' => now(),
            ]);
            $user->forceFill([
                'email' => Str::limit('deleted-' . $user->id . '-' . $user->email, 250, ''),
                'remember_token' => null,
            ])->save();
            $user->delete();
            // Logged in somewhere right now: logged out (logins kept in the database, on Vercel).
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });

        return redirect()->route('users.index')->with('success', "{$user->name}'s account was deleted. Their past sales keep their name.");
    }

    // Turns off someone's two-step verification (they lost their phone), confirmed with the owner's
    // own code. They log in with the password alone until they set it up again; an owner is asked
    // to set it up again right away.
    public function resetTwoFactor(Request $request, User $user, TwoFactor $twoFactor)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Change your own two-step verification in Profile.');
        }

        $this->confirmWithTwoFactor($request, 'code', 'resetTwoFactor');
        $twoFactor->disable($user);

        return redirect()->route('users.index', ['edit' => $user->id])
            ->with('success', "Two-step verification is off for {$user->name}. They can set it up again after logging in.");
    }
}
