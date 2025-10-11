<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Organization;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            // Redirect based on user role
            $user = Auth::user();
            if ($user->isConsumer()) {
                return redirect()->intended('/consumer/dashboard');
            } elseif ($user->isHelpDeskAgent()) {
                return redirect()->intended('/agent/dashboard');
            } elseif ($user->isSupportPerson()) {
                return redirect()->intended('/support/dashboard');
            } elseif ($user->isHelpDeskManager()) {
                return redirect()->intended('/manager/dashboard');
            } elseif ($user->isOrganizationAdmin()) {
                return redirect()->intended('/organization-admin/dashboard');
            } elseif ($user->isSystemAdmin()) {
                return redirect()->intended('/admin/dashboard');
            }
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ]);
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'organization_number' => 'required|string|max:20',
            'account_number' => 'required|string|size:8',
        ], [
            'account_number.size' => 'Account number must be exactly 8 characters.',
        ]);

        // First, find the organization by organization number
        $organization = Organization::where('organization_number', $request->organization_number)
            ->where('status', 'active')
            ->first();

        if (!$organization) {
            return back()->withErrors([
                'organization_number' => 'Invalid organization number. Please check and try again.'
            ])->withInput();
        }

        // Find registered user by email, account number, AND organization
        $existingUser = User::where('email', $request->email)
            ->where('consumer_number', $request->account_number)
            ->where('organization_id', $organization->id)
            ->where('role', 'consumer')
            ->first();

        if (!$existingUser) {
            return back()->withErrors([
                'account_number' => 'Record not found. Your email and account number do not match our records for ' . $organization->name . '. Please contact your organization administrator.'
            ])->withInput();
        }

        // Check if user already has a password set (already registered)
        if ($existingUser->password && strlen($existingUser->password) > 0) {
            return back()->withErrors([
                'email' => 'This account is already registered. Please login instead.'
            ])->withInput();
        }

        // Update the existing user record with password
        $existingUser->update([
            'password' => Hash::make($request->password),
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        Auth::login($existingUser);

        return redirect('/consumer/dashboard')->with('success', 'Registration successful! Welcome to ' . $existingUser->organization->name);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
