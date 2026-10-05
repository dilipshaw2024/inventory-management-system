<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\CompanyErpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_company_password_is_redirected_until_changed(): void
    {
        $company = Company::create(['name' => 'Password Rotation Co', 'code' => 'PASSWORD-ROTATION']);
        CompanyErpSetting::create(['company_id' => $company->id, 'key' => 'password_expiry_days', 'value' => '30', 'value_type' => 'int']);
        $user = User::factory()->create(['company_id' => $company->id, 'password_changed_at' => Carbon::now()->subDays(31)]);

        $this->actingAs($user)->get('/admin/profile')->assertRedirect(route('change.password'));
    }

    public function test_password_change_refreshes_rotation_timestamp(): void
    {
        $company = Company::create(['name' => 'Password Refresh Co', 'code' => 'PASSWORD-REFRESH']);
        CompanyErpSetting::create(['company_id' => $company->id, 'key' => 'password_expiry_days', 'value' => '30', 'value_type' => 'int']);
        $user = User::factory()->create(['company_id' => $company->id, 'password_changed_at' => Carbon::now()->subDays(31)]);

        $this->actingAs($user)->post('/update/password', [
            'oldpassword' => 'password',
            'newpassword' => 'NewStrongPassword1!',
            'confirm_password' => 'NewStrongPassword1!',
        ])->assertRedirect();

        self::assertNotNull($user->fresh()->password_changed_at);
        self::assertTrue($user->fresh()->password_changed_at->greaterThan(Carbon::now()->subMinute()));
    }
}
