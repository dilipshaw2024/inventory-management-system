<?php

namespace Tests\Feature\Auth;

use App\Services\PasswordBreachScreeningService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasswordBreachScreeningTest extends TestCase
{
    public function test_k_anonymity_response_detects_only_matching_hash_suffix(): void
    {
        config(['erp.password_breach_screening.enabled' => true, 'erp.password_breach_screening.endpoint' => 'https://breach.test/range']);
        $hash = strtoupper(sha1('UniqueStrongPassword1!'));
        Http::fake(['https://breach.test/range/*' => Http::response(substr($hash, 5).':42\nOTHER:1', 200)]);

        self::assertTrue(app(PasswordBreachScreeningService::class)->isCompromised('UniqueStrongPassword1!'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), substr($hash, 0, 5)) && !str_contains($request->url(), $hash));
    }

    public function test_screening_fails_open_when_provider_is_unavailable_by_default(): void
    {
        config(['erp.password_breach_screening.enabled' => true, 'erp.password_breach_screening.fail_closed' => false]);
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response([], 503)]);

        self::assertFalse(app(PasswordBreachScreeningService::class)->isCompromised('AnotherUniqueStrongPassword1!'));
    }
}
