<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;
use App\Models\User;
use App\Models\Complaint;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create organizations
        $org1 = Organization::create([
            'name' => 'TechCorp Solutions',
            'email' => 'admin@techcorp.com',
            'phone' => '+1-555-0123',
            'address' => '123 Tech Street, Silicon Valley, CA 94000',
            'status' => 'active'
        ]);

        $org2 = Organization::create([
            'name' => 'Global Services Inc',
            'email' => 'contact@globalservices.com',
            'phone' => '+1-555-0456',
            'address' => '456 Business Ave, New York, NY 10001',
            'status' => 'active'
        ]);

        // Create system admin
        User::firstOrCreate(
            ['email' => 'admin@cms.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'role' => 'system_admin',
                'phone' => '+1-555-0000',
                'address' => 'System Admin Address',
                'is_active' => true
            ]
        );

        // Create organization admin for TechCorp
        User::firstOrCreate(
            ['email' => 'admin@techcorp.com'],
            [
                'name' => 'TechCorp Admin',
                'password' => Hash::make('password'),
                'role' => 'organization_admin',
                'organization_id' => $org1->id,
                'phone' => '+1-555-0001',
                'address' => 'TechCorp Admin Address',
                'is_active' => true
            ]
        );

        // Create organization admin for Global Services
        User::firstOrCreate(
            ['email' => 'admin@globalservices.com'],
            [
                'name' => 'Global Services Admin',
                'password' => Hash::make('password'),
                'role' => 'organization_admin',
                'organization_id' => $org2->id,
                'phone' => '+1-555-0002',
                'address' => 'Global Services Admin Address',
                'is_active' => true
            ]
        );

        // Create help desk manager
        User::firstOrCreate(
            ['email' => 'manager@techcorp.com'],
            [
                'name' => 'John Manager',
                'password' => Hash::make('password'),
                'role' => 'helpdesk_manager',
                'organization_id' => $org1->id,
                'phone' => '+1-555-0003',
                'address' => 'Manager Address',
                'is_active' => true
            ]
        );

        // Create help desk agents
        User::firstOrCreate(
            ['email' => 'sarah@techcorp.com'],
            [
                'name' => 'Sarah Agent',
                'password' => Hash::make('password'),
                'role' => 'helpdesk_agent',
                'organization_id' => $org1->id,
                'phone' => '+1-555-0004',
                'address' => 'Agent Address',
                'is_active' => true
            ]
        );

        User::firstOrCreate(
            ['email' => 'mike@techcorp.com'],
            [
                'name' => 'Mike Agent',
                'password' => Hash::make('password'),
                'role' => 'helpdesk_agent',
                'organization_id' => $org1->id,
                'phone' => '+1-555-0005',
                'address' => 'Agent Address',
                'is_active' => true
            ]
        );

        // Create support persons
        User::firstOrCreate(
            ['email' => 'alex@techcorp.com'],
            [
                'name' => 'Alex Support',
                'password' => Hash::make('password'),
                'role' => 'support_person',
                'organization_id' => $org1->id,
                'phone' => '+1-555-0006',
                'address' => 'Support Address',
                'is_active' => true
            ]
        );

        // Create consumers
        $consumer1 = User::firstOrCreate(
            ['email' => 'alice@techcorp.com'],
            [
                'name' => 'Alice Consumer',
                'password' => Hash::make('password'),
                'role' => 'consumer',
                'organization_id' => $org1->id,
                'phone' => '+1-555-0007',
                'address' => 'Consumer Address',
                'is_active' => true
            ]
        );

        $consumer2 = User::firstOrCreate(
            ['email' => 'bob@globalservices.com'],
            [
                'name' => 'Bob Consumer',
                'password' => Hash::make('password'),
                'role' => 'consumer',
                'organization_id' => $org2->id,
                'phone' => '+1-555-0008',
                'address' => 'Consumer Address',
                'is_active' => true
            ]
        );

        // Create sample complaints
        Complaint::create([
            'title' => 'Login Issues',
            'description' => 'Unable to login to the system. Getting authentication error.',
            'category' => 'technical',
            'subcategory' => 'authentication',
            'priority' => 'high',
            'consumer_id' => $consumer1->id,
            'assigned_agent_id' => 3, // Sarah Agent
            'status' => 'in_progress'
        ]);

        Complaint::create([
            'title' => 'Billing Discrepancy',
            'description' => 'Charged twice for the same service this month.',
            'category' => 'billing',
            'subcategory' => 'double_charge',
            'priority' => 'medium',
            'consumer_id' => $consumer2->id,
            'assigned_agent_id' => 4, // Mike Agent
            'status' => 'open'
        ]);

        Complaint::create([
            'title' => 'Product Defect',
            'description' => 'Received damaged product. Need replacement.',
            'category' => 'product',
            'subcategory' => 'damage',
            'priority' => 'urgent',
            'consumer_id' => $consumer1->id,
            'assigned_support_id' => 5, // Alex Support
            'status' => 'resolved',
            'resolution_notes' => 'Replacement product shipped. Tracking number: TRK123456',
            'resolved_at' => now()->subDays(2)
        ]);
    }
}
