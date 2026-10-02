<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class JwtSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => str_repeat('test-session-signing-key-', 3)]);
        Route::get('/api/test/session', fn (Request $request) => ['id' => $request->user('api')->id])
            ->middleware(['auth.cookie', 'auth:api', 'active.user']);
    }

    public function test_current_session_is_accepted_and_remember_token_is_not_exposed(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $token = JWTAuth::fromUser($user);
        $claims = JWTAuth::setToken($token)->getPayload()->toArray();

        $this->assertSame(hash('sha256', $user->getRememberToken()), $claims['session_version']);
        $this->assertNotContains($user->getRememberToken(), $claims);
        $this->getSession($token)->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_a_rotated_session_version_rejects_the_pre_reset_token(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $oldToken = JWTAuth::fromUser($user);
        $this->getSession($oldToken)->assertOk();

        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->getSession($oldToken)->assertUnauthorized();
        $this->getSession(JWTAuth::fromUser($user))->assertOk();
    }

    public function test_legacy_sessions_are_rejected_after_the_first_reset(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $claims = JWTAuth::setToken(JWTAuth::fromUser($user))->getPayload()->toArray();
        unset($claims['session_version']);
        $legacyToken = app('tymon.jwt.provider.jwt')->encode($claims);

        $this->getSession($legacyToken)->assertOk();

        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->getSession($legacyToken)->assertUnauthorized();
    }

    public function test_malformed_session_claim_is_rejected(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $claims = JWTAuth::setToken(JWTAuth::fromUser($user))->getPayload()->toArray();
        $claims['session_version'] = ['invalid'];
        $token = app('tymon.jwt.provider.jwt')->encode($claims);

        $this->getSession($token)->assertUnauthorized();
    }

    public function test_a_refreshed_current_session_remains_usable(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $token = JWTAuth::fromUser($user);
        $refreshedToken = JWTAuth::setToken($token)->refresh();

        $this->assertSame(
            $user->getJWTSessionVersion(),
            JWTAuth::setToken($refreshedToken)->getPayload()->get('session_version')
        );
        $this->getSession($refreshedToken)->assertOk()->assertJsonPath('id', $user->id);
    }

    private function getSession(string $token): TestResponse
    {
        Auth::forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();

        return $this->getJson('/api/test/session', ['Authorization' => 'Bearer '.$token]);
    }
}
