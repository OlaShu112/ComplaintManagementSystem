<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\OrganizationAdminController;
use App\Http\Controllers\StaffComplaintController;
use App\Http\Controllers\ComplaintAssignmentController;
use App\Http\Controllers\ReportController;

// Public routes
Route::get('/', function () {
    return view('index');
});

// Guest complaint routes (no authentication required)
Route::prefix('guest')->name('guest.')->group(function () {
    Route::get('/complaint', [App\Http\Controllers\GuestComplaintController::class, 'create'])->name('complaint.create');
    Route::post('/complaint', [App\Http\Controllers\GuestComplaintController::class, 'store'])->name('complaint.store');
    Route::get('/complaint/success', [App\Http\Controllers\GuestComplaintController::class, 'success'])->name('complaint.success');
    Route::get('/complaint/track', [App\Http\Controllers\GuestComplaintController::class, 'track'])->name('complaint.track');
    Route::get('/complaint/{token}', [App\Http\Controllers\GuestComplaintController::class, 'show'])->name('complaint.show');
    Route::post('/complaint/{token}/feedback', [App\Http\Controllers\GuestComplaintController::class, 'feedback'])->name('complaint.feedback');
});

// Authentication routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected routes
Route::middleware('auth')->group(function () {
    // Main dashboard - redirects based on role
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Consumer routes
    Route::prefix('consumer')->middleware('role:consumer')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('consumer.dashboard');
    });

    // Help Desk Agent routes
    Route::prefix('agent')->middleware('role:helpdesk_agent')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('agent.dashboard');
    });

    // Support Person routes
    Route::prefix('support')->middleware('role:support_person')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('support.dashboard');
    });

    // Help Desk Manager routes
    Route::prefix('manager')->middleware('role:helpdesk_manager')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('manager.dashboard');

        // User Management (Organization Only)
        Route::get('/users', [ManagerController::class, 'users'])->name('manager.users');
        Route::get('/users/create', [ManagerController::class, 'createUser'])->name('manager.users.create');
        Route::post('/users', [ManagerController::class, 'storeUser'])->name('manager.users.store');
        Route::get('/users/{user}/edit', [ManagerController::class, 'editUser'])->name('manager.users.edit');
        Route::put('/users/{user}', [ManagerController::class, 'updateUser'])->name('manager.users.update');
        Route::post('/users/{user}/toggle', [ManagerController::class, 'toggleUserStatus'])->name('manager.users.toggle');
        Route::delete('/users/{user}', [ManagerController::class, 'deleteUser'])->name('manager.users.delete');

        // Organization Statistics
        Route::get('/statistics', [ManagerController::class, 'statistics'])->name('manager.statistics');
    });

    // Organization Admin routes
    Route::prefix('organization-admin')->middleware('role:organization_admin')->group(function () {
        Route::get('/dashboard', [OrganizationAdminController::class, 'dashboard'])->name('organization-admin.dashboard');

        // User Management (Organization Only)
        Route::get('/users', [OrganizationAdminController::class, 'users'])->name('organization-admin.users');
        Route::get('/users/create', [OrganizationAdminController::class, 'createUser'])->name('organization-admin.users.create');
        Route::post('/users', [OrganizationAdminController::class, 'storeUser'])->name('organization-admin.users.store');
        Route::get('/users/{user}/edit', [OrganizationAdminController::class, 'editUser'])->name('organization-admin.users.edit');
        Route::put('/users/{user}', [OrganizationAdminController::class, 'updateUser'])->name('organization-admin.users.update');
        Route::post('/users/{user}/toggle', [OrganizationAdminController::class, 'toggleUserStatus'])->name('organization-admin.users.toggle');
        Route::delete('/users/{user}', [OrganizationAdminController::class, 'deleteUser'])->name('organization-admin.users.delete');

        // Complaints Management
        Route::get('/complaints', [OrganizationAdminController::class, 'complaints'])->name('organization-admin.complaints');

        // Reports
        Route::get('/reports', [OrganizationAdminController::class, 'reports'])->name('organization-admin.reports');

        // Add these routes to the organization-admin route group (around line 80):
Route::get('/statistics', [OrganizationAdminController::class, 'statistics'])->name('organization-admin.statistics');
Route::get('/settings', [OrganizationAdminController::class, 'settings'])->name('organization-admin.settings');
Route::post('/settings', [OrganizationAdminController::class, 'updateSettings'])->name('organization-admin.settings.update');
    });

    // System Admin routes
    Route::prefix('admin')->middleware('role:system_admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

        // Organization Management
        Route::get('/organizations', [AdminController::class, 'organizations'])->name('admin.organizations');
        Route::get('/organizations/create', [AdminController::class, 'createOrganization'])->name('admin.organizations.create');
        Route::post('/organizations', [AdminController::class, 'storeOrganization'])->name('admin.organizations.store');
        Route::get('/organizations/{organization}/edit', [AdminController::class, 'editOrganization'])->name('admin.organizations.edit');
        Route::put('/organizations/{organization}', [AdminController::class, 'updateOrganization'])->name('admin.organizations.update');

        // User Management
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('/users/create', [AdminController::class, 'createUser'])->name('admin.users.create');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
        Route::get('/users/{user}/edit', [AdminController::class, 'editUser'])->name('admin.users.edit');
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
        Route::post('/users/{user}/toggle', [AdminController::class, 'toggleUserStatus'])->name('admin.users.toggle');
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('admin.users.delete');

        // System Statistics
        Route::get('/statistics', [AdminController::class, 'statistics'])->name('admin.statistics');

        // Comprehensive Reports
        Route::get('/reports', [AdminController::class, 'reports'])->name('admin.reports');

        // All Complaints
        Route::get('/complaints', [AdminController::class, 'complaints'])->name('admin.complaints');

        // System Analytics
        Route::get('/analytics', [AdminController::class, 'analytics'])->name('admin.analytics');

        // System Settings
        Route::get('/settings', [AdminController::class, 'settings'])->name('admin.settings');
        Route::post('/settings', [AdminController::class, 'updateSettings'])->name('admin.settings.update');
    });

    // Complaint routes (accessible by all authenticated users)
    Route::resource('complaints', ComplaintController::class);
    Route::post('/complaints/{complaint}/feedback', [ComplaintController::class, 'provideFeedback'])->name('complaints.feedback');

    // Complaint assignment routes
    Route::get('/complaints/{complaint}/assignment', [ComplaintAssignmentController::class, 'showAssignment'])->name('complaints.assignment');
    Route::post('/complaints/{complaint}/assign', [ComplaintAssignmentController::class, 'assign'])->name('complaints.assign');
    Route::get('/complaints/{complaint}/auto-assign', [ComplaintAssignmentController::class, 'autoAssign'])->name('complaints.auto-assign');
    Route::get('/complaints/{complaint}/escalate', [ComplaintAssignmentController::class, 'escalate'])->name('complaints.escalate');
    Route::post('/complaints/{complaint}/reassign', [ComplaintAssignmentController::class, 'reassign'])->name('complaints.reassign');

    // Staff complaint management routes
    Route::middleware('auth')->group(function () {
        Route::get('/staff/complaints/create', [StaffComplaintController::class, 'create'])->name('staff.complaints.create');
        Route::post('/staff/complaints', [StaffComplaintController::class, 'store'])->name('staff.complaints.store');
        Route::post('/staff/complaints/{complaint}/update-status', [StaffComplaintController::class, 'updateStatus'])->name('staff.complaints.update-status');
        Route::post('/staff/complaints/{complaint}/assign', [StaffComplaintController::class, 'assign'])->name('staff.complaints.assign');
        Route::post('/staff/find-user', [StaffComplaintController::class, 'findOrCreateUser'])->name('staff.find-user');
    });

    // Reports routes
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
});
