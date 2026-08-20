<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('organizations'));

        $this->assertTrue(Schema::hasColumns('organizations', [
            'id',
            'name',
            'slug',
            'email',
            'phone',
            'address',
            'logo_path',
            'status',
            'trial_ends_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_organization_can_be_created(): void
    {
        $organization = Organization::create([
            'name' => 'مؤسسة تجريبية وجدة',
            'slug' => 'oujda-demo',
            'email' => 'contact@example.test',
            'phone' => '0600000000',
            'status' => Organization::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'مؤسسة تجريبية وجدة',
            'slug' => 'oujda-demo',
            'status' => Organization::STATUS_TRIAL,
        ]);

        $this->assertInstanceOf(
            Carbon::class,
            $organization->trial_ends_at
        );
    }

    public function test_status_defaults_to_trial(): void
    {
        $organization = Organization::create([
            'name' => 'مؤسسة بدون حالة',
            'slug' => 'default-status',
        ])->refresh();

        $this->assertSame(
            Organization::STATUS_TRIAL,
            $organization->status
        );
    }

    public function test_organization_slug_must_be_unique(): void
    {
        Organization::create([
            'name' => 'المؤسسة الأولى',
            'slug' => 'same-slug',
        ]);

        $this->expectException(QueryException::class);

        Organization::create([
            'name' => 'المؤسسة الثانية',
            'slug' => 'same-slug',
        ]);
    }
}
