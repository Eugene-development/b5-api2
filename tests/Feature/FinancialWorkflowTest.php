<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\AcceptProject;
use App\GraphQL\Mutations\CreateBonusPaymentRequest;
use App\GraphQL\Mutations\UpdateBonusPaymentRequestStatus;
use App\GraphQL\Mutations\UpdateContract;
use App\GraphQL\Mutations\UpdateContractStatus;
use App\GraphQL\Mutations\UpdateOrder;
use App\GraphQL\Mutations\UpdateProject;
use App\Models\AgentPayment;
use App\Models\Bonus;
use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use App\Models\BonusStatus;
use App\Models\Contract;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\BonusCalculationService;
use App\Services\BonusPaymentService;
use App\Services\BonusService;
use App\Services\FinancialLedger;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function statusId(string $table, string $slug): string
    {
        return DB::table($table)->where('slug', $slug)->value('id');
    }

    private function user(string $role = 'agent'): User
    {
        $key = (string) Str::ulid();

        return User::create(['key' => $key, 'name' => 'Test', 'email' => "$key@example.invalid", 'password' => 'password',
            'status_id' => $this->statusId('user_statuses', $role), 'is_active' => true, 'ban' => false]);
    }

    private function fixture(float $amount = 100000, bool $referral = false): array
    {
        $user = $this->user();
        if ($referral) {
            $referrer = $this->user();
            DB::table('users')->where('id', $user->id)->update(['referrer_key' => $referrer->key]);
        }
        $companyId = (string) Str::ulid();
        DB::table('companies')->insert(['id' => $companyId, 'name' => 'Test', 'status_id' => DB::table('company_statuses')->value('id')]);
        $project = Project::create(['value' => 'Test', 'user_id' => $user->id, 'is_active' => true, 'status_id' => $this->statusId('project_statuses', 'new-project')]);
        $contract = Contract::create(['project_id' => $project->id, 'company_id' => $companyId, 'contract_amount' => $amount,
            'contract_date' => '2026-09-01', 'planned_completion_date' => '2026-10-01', 'agent_percentage' => 3, 'curator_percentage' => 2,
            'status_id' => $this->statusId('contract_statuses', 'completed')]);
        $contract->partner_payment_status_id = DB::table('partner_payment_statuses')->where('code', 'paid')->value('id');
        $contract->save();

        return [$user, $project, $contract->fresh()];
    }

    private function request(User $user, float $amount, string $type = 'agent'): BonusPaymentRequest
    {
        return FinancialLedger::transaction(function () use ($user, $amount, $type) {
            $request = BonusPaymentRequest::create(['agent_id' => $user->id, 'requester_type' => $type, 'amount' => $amount,
                'payment_method' => 'other', 'contact_info' => 'Test', 'status_id' => BonusPaymentStatus::findByCode('requested')->id]);
            app(BonusPaymentService::class)->linkBonusesToPaymentRequest($request, $user->id, $amount, $type);

            return $request;
        });
    }

    private function transition(BonusPaymentRequest $request, string $code): BonusPaymentRequest
    {
        return (new UpdateBonusPaymentRequestStatus)(null, ['request_id' => $request->id, 'status_code' => $code]);
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe operation was accepted');
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function graphql(User $user, string $query, array $variables = []): array
    {
        Auth::guard('api')->setUser($user);
        config(['lighthouse.schema_cache.enable' => false]);
        $request = \Illuminate\Http\Request::create('/graphql', 'POST');
        $request->setUserResolver(fn () => $user);

        return app(\Nuwave\Lighthouse\GraphQL::class)->executeQueryString($query, new \Nuwave\Lighthouse\Execution\HttpGraphQLContext($request), $variables);
    }

    public function test_partial_payment_and_unrelated_edit_preserve_paid_and_total_amounts(): void
    {
        [$u, $p, $c] = $this->fixture();
        $r = $this->request($u, 1000);
        $this->transition($r, 'paid');
        (new UpdateContract)(null, ['input' => ['id' => $c->id, 'value' => 'new number']]);
        $this->assertEquals(3000, $c->bonuses()->sum('commission_amount'));
        $this->assertEquals(1000, $c->bonuses()->whereNotNull('paid_at')->sum('commission_amount'));
        $c->fresh()->update(['contract_amount' => 200000]);
        $this->assertEquals(1000, $c->bonuses()->whereNotNull('paid_at')->sum('commission_amount'));
        $this->assertEquals(5000, $c->bonuses()->whereNull('paid_at')->sum('commission_amount'));
    }

    public function test_reserved_remainder_is_immediately_available_and_stats_agree(): void
    {
        [$u] = $this->fixture();
        $this->request($u, 1000);
        $this->assertEquals(2000, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
        $this->assertEquals(2000, app(BonusService::class)->getAgentStats($u->id)['total_available']);
        $this->request($u, 2000);
        $this->assertEquals(0, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
    }

    public function test_rollback_never_deletes_another_requests_fragment(): void
    {
        [$u] = $this->fixture();
        $a = $this->request($u, 1000);
        $this->transition($a, 'paid');
        $b = $this->request($u, 2000);
        $this->transition($a, 'approved');
        $this->assertCount(1, $b->linkedBonuses()->get());
        $this->transition($b, 'paid');
        $this->assertEquals(2000, Bonus::where('user_id', $u->id)->whereNotNull('paid_at')->sum('commission_amount'));
    }

    public function test_cancelled_request_releases_only_its_fragment_and_can_be_reopened(): void
    {
        [$u] = $this->fixture();
        $r = $this->request($u, 1000);
        $this->transition($r, 'cancelled');
        $this->assertEquals(3000, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
        $this->transition($r, 'approved');
        $this->assertEquals(2000, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
    }

    public function test_request_payment_is_idempotent_including_payment_date(): void
    {
        [$u] = $this->fixture();
        $r = $this->request($u, 1000);
        $r = $this->transition($r, 'paid');
        $date = $r->payment_date;
        $this->travel(1)->day();
        $r = $this->transition($r, 'paid');
        $this->assertTrue($date->equalTo($r->payment_date));
        $this->assertEquals(3000, Bonus::where('user_id', $u->id)->sum('commission_amount'));
    }

    public function test_source_cannot_shrink_below_committed_amount_and_update_rolls_back(): void
    {
        [$u, $p, $c] = $this->fixture();
        $this->request($u, 3000);
        $this->reject(fn () => (new UpdateContract)(null, ['input' => ['id' => $c->id, 'contract_amount' => 10000]]));
        $this->assertEquals(100000, $c->fresh()->contract_amount);
        $this->assertEquals(3000, $c->bonuses()->sum('commission_amount'));
    }

    public function test_refused_project_cannot_be_reserved_or_paid(): void
    {
        [$u, $p] = $this->fixture();
        $r = $this->request($u, 1000);
        (new UpdateProject)(null, ['id' => $p->id, 'status_id' => $this->statusId('project_statuses', 'client-refused')]);
        $this->assertEquals(0, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
        $this->reject(fn () => $this->transition($r, 'paid'));
        $this->assertEquals('requested', $r->fresh()->status->code);
    }

    public function test_direct_payment_cannot_overlap_another_payment_or_request(): void
    {
        [$u, $p, $c] = $this->fixture();
        $service = app(PaymentService::class);
        $id = $c->bonuses()->value('id');
        $payment = $service->createPayment($u->id, [$id], 1);
        $this->reject(fn () => $service->createPayment($u->id, [$id], 1));
        $this->reject(fn () => $this->request($u, 3000));
        $service->completePayment($payment);
        $service->completePayment($payment);
        $this->assertEquals(1, AgentPayment::count());
        $service->failPayment($payment);
        $service->failPayment($payment);
        $this->assertEquals(3000, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
        $this->request($u, 3000);
        $this->reject(fn () => $service->completePayment($payment));
    }

    public function test_direct_payment_requires_source_completion(): void
    {
        [$u, $p, $c] = $this->fixture();
        $c->update(['status_id' => $this->statusId('contract_statuses', 'preparing')]);
        $this->reject(fn () => app(PaymentService::class)->createPayment($u->id, [$c->bonuses()->value('id')], 1));
    }

    public function test_financial_sources_and_curators_cannot_be_deleted_with_commitments(): void
    {
        [$u, $p, $c] = $this->fixture();
        $r = $this->request($u, 3000);
        $this->transition($r, 'paid');
        $this->reject(fn () => $p->delete());
        $this->reject(fn () => $c->delete());
        $this->assertCount(1, $r->linkedBonuses()->get());
        $this->assertDatabaseHas('projects', ['id' => $p->id]);
    }

    public function test_new_beneficiary_cannot_replace_owner_of_existing_accrual(): void
    {
        [$u, $p, $c] = $this->fixture();
        [$u2, $p2] = $this->fixture();
        $this->reject(fn () => (new UpdateContract)(null, ['input' => ['id' => $c->id, 'project_id' => $p2->id]]));
        $this->reject(fn () => (new UpdateProject)(null, ['id' => $p->id, 'user_id' => $u2->id]));
        $this->assertEquals($u->id, $c->bonuses()->value('user_id'));
    }

    public function test_all_recipients_recalculate_using_current_source_amount(): void
    {
        [$u, $p, $c] = $this->fixture(100000, true);
        $curator = $this->user('curator');
        (new AcceptProject)(null, ['projectId' => $p->id, 'userId' => $curator->id, 'role' => 'curator']);
        $c->fresh()->update(['contract_amount' => 200000]);
        $this->assertEquals(1000, $c->bonuses()->where('recipient_type', 'referrer')->sum('commission_amount'));
        $o = Order::create(['company_id' => $c->company_id, 'project_id' => $p->id, 'is_active' => true, 'order_amount' => 100000,
            'agent_percentage' => 5, 'curator_percentage' => 5, 'status_id' => $this->statusId('order_statuses', 'delivered')]);
        (new UpdateOrder)(null, ['input' => ['id' => $o->id, 'order_amount' => 200000]]);
        $this->assertEquals(10000, $o->bonuses()->where('recipient_type', 'curator')->sum('commission_amount'));
        $this->assertEquals(10000, $o->bonuses()->where('recipient_type', 'agent')->sum('commission_amount'));
        $this->assertEquals(1000, $o->bonuses()->where('recipient_type', 'referrer')->sum('commission_amount'));
    }

    public function test_zero_percent_is_preserved_and_zero_order_later_accrues(): void
    {
        [$u, $p, $c] = $this->fixture();
        $c->update(['agent_percentage' => 0, 'curator_percentage' => 0]);
        $this->assertEquals(0, $c->fresh()->agent_percentage);
        $this->assertEquals(0, $c->bonuses()->sum('commission_amount'));
        $o = Order::create(['company_id' => $c->company_id, 'project_id' => $p->id, 'order_amount' => 0, 'is_active' => true, 'agent_percentage' => 0]);
        $this->assertEquals(0, $o->fresh()->agent_percentage);
        (new UpdateOrder)(null, ['input' => ['id' => $o->id, 'order_amount' => 100000, 'agent_percentage' => 5]]);
        $this->assertEquals(5000, $o->bonuses()->sum('commission_amount'));
    }

    public function test_summary_and_graphql_aggregate_fragments(): void
    {
        [$u, $p, $c] = $this->fixture();
        $r = $this->request($u, 1000);
        $this->transition($r, 'paid');
        $summary = app(BonusCalculationService::class)->getProjectBonusSummary($p->id);
        $this->assertEquals(3000, $summary['totalAgentBonus']);
        $this->assertEquals(2000, $summary['totalAvailableBonus']);
        $result = $this->graphql($this->user('admin'), 'query($id:ID!){contract(id:$id){agent_bonus curator_bonus}}', ['id' => $c->id]);
        $this->assertArrayNotHasKey('errors', $result);
        $this->assertEquals(3000, $result['data']['contract']['agent_bonus']);
    }

    public function test_curator_queries_cannot_leak_other_users_bonuses_or_requests(): void
    {
        [$u, $p] = $this->fixture();
        $this->request($u, 1000);
        $curator = $this->user('curator');
        (new AcceptProject)(null, ['projectId' => $p->id, 'userId' => $curator->id, 'role' => 'curator']);
        $this->request($curator, 1000, 'curator');
        $result = $this->graphql($curator, 'query($other:ID!){adminBonuses(filters:{user_id:$other}){user_id recipient_type} adminBonusStats(filters:{user_id:$other}){total_available total_requested} bonusPaymentRequests(first:100){data{agent_id requester_type}}}', ['other' => $u->id]);
        $this->assertArrayNotHasKey('errors', $result);
        foreach ($result['data']['adminBonuses'] as $b) {
            $this->assertEquals($curator->id, $b['user_id']);
        }
        $this->assertEquals(1000, $result['data']['adminBonusStats']['total_available']);
        $this->assertCount(1, $result['data']['bonusPaymentRequests']['data']);
        $this->assertEquals($curator->id, $result['data']['bonusPaymentRequests']['data'][0]['agent_id']);
        $this->reject(fn () => app(BonusService::class)->removeCuratorBonusesForProject($p->id));
    }

    public function test_payment_filters_and_second_page_are_executed_by_graphql(): void
    {
        [$u] = $this->fixture();
        $this->request($u, 1000);
        for ($i = 0; $i < 101; $i++) {
            BonusPaymentRequest::create(['agent_id' => $u->id, 'requester_type' => 'agent', 'amount' => 1000, 'payment_method' => 'other',
                'status_id' => BonusPaymentStatus::findByCode('cancelled')->id]);
        }
        $admin = $this->user('admin');
        $result = $this->graphql($admin, '{bonusPaymentRequests(first:100,page:2){data{id} paginatorInfo{total count}}}');
        $this->assertArrayNotHasKey('errors', $result);
        $this->assertCount(2, $result['data']['bonusPaymentRequests']['data']);
        $result = $this->graphql($admin, '{bonusPaymentRequests(first:100,filters:{requester_type:"curator",date_from:"2099-01-01 00:00:00"}){data{id} paginatorInfo{total}}}');
        $this->assertArrayNotHasKey('errors', $result);
        $this->assertEquals(0, $result['data']['bonusPaymentRequests']['paginatorInfo']['total']);
    }

    public function test_available_timestamp_follows_actual_status(): void
    {
        [$u, $p, $c] = $this->fixture();
        (new UpdateContractStatus)(null, ['contract_id' => $c->id, 'status_slug' => 'signed']);
        $this->assertNull($c->bonuses()->first()->available_at);
        (new UpdateContractStatus)(null, ['contract_id' => $c->id, 'status_slug' => 'completed']);
        $this->assertNotNull($c->bonuses()->first()->available_at);
    }

    public function test_agent_picker_uses_available_balance_and_project_has_one_curator(): void
    {
        [$u, $p] = $this->fixture();
        $agents = (new \App\GraphQL\Queries\AgentsWithAvailableBonusesQuery)(null, []);
        $this->assertCount(1, $agents);
        $this->assertEquals(3000, $agents[0]['available_bonuses_total']);
        (new AcceptProject)(null, ['projectId' => $p->id, 'userId' => $u->id, 'role' => 'curator']);
        $other = $this->user('curator');
        $this->reject(fn () => (new AcceptProject)(null, ['projectId' => $p->id, 'userId' => $other->id, 'role' => 'curator']));
        $this->assertEquals(1, DB::table('project_user')->where('project_id', $p->id)->where('role', 'curator')->count());
    }

    public function test_request_accepts_exact_kopecks_and_rejects_uncovered_payment(): void
    {
        [$u] = $this->fixture(100020);
        Auth::guard('api')->setUser($u);
        $r = (new CreateBonusPaymentRequest)(null, ['input' => ['amount' => 3000.60, 'payment_method' => 'other', 'contact_info' => 'Test']]);
        $this->assertEquals('3000.60', $r->amount);
        $r->linkedBonuses()->delete();
        $this->reject(fn () => $this->transition($r, 'paid'));
    }

    public function test_database_rejects_bonus_without_exactly_one_source(): void
    {
        [$u] = $this->fixture();
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('bonuses_source_xor_check');
        Bonus::create(['user_id' => $u->id, 'commission_amount' => 1000, 'status_id' => BonusStatus::pendingId(), 'accrued_at' => now()]);
    }

    public function test_editing_request_amount_rebuilds_coverage_atomically(): void
    {
        [$u] = $this->fixture();
        $r = $this->request($u, 1000);
        $mutation = app(\App\GraphQL\Mutations\UpdateBonusPaymentRequest::class);
        $r = $mutation(null, ['request_id' => $r->id, 'input' => ['amount' => 2000]]);
        $this->assertEquals(2000, $r->linkedBonuses()->sum('covered_amount'));
        try {
            $mutation(null, ['request_id' => $r->id, 'input' => ['amount' => 4000]]);
            $this->fail('Unfunded edit accepted');
        } catch (\GraphQL\Error\Error $e) {
            $this->assertEquals(2000, $r->fresh()->amount);
        }
        $this->assertEquals(2000, $r->linkedBonuses()->sum('covered_amount'));
        $this->assertEquals(1000, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
    }

    public function test_reconciliation_normalizes_legacy_partial_reservation(): void
    {
        [$u, $p, $c] = $this->fixture();
        $r = BonusPaymentRequest::create(['agent_id' => $u->id, 'requester_type' => 'agent', 'amount' => 1000, 'payment_method' => 'other', 'status_id' => BonusPaymentStatus::findByCode('requested')->id]);
        \App\Models\BonusPaymentRequestBonus::create(['payment_request_id' => $r->id, 'bonus_id' => $c->bonuses()->value('id'), 'covered_amount' => 1000]);
        $this->artisan('bonuses:reconcile-ledger', ['--apply' => true])->assertSuccessful();
        $this->assertEquals(1000, $r->linkedBonuses()->first()->bonus->commission_amount);
        $this->assertEquals(2000, app(BonusPaymentService::class)->calculateAvailableBalance($u->id, 'agent'));
    }

    public function test_type_repair_preserves_paid_amount_and_requires_uniform_owned_links(): void
    {
        [$u, $p] = $this->fixture();
        $curator = $this->user('curator');
        (new AcceptProject)(null, ['projectId' => $p->id, 'userId' => $curator->id, 'role' => 'curator']);
        $r = $this->request($curator, 1000, 'curator');
        $this->transition($r, 'paid');
        $r->update(['requester_type' => 'agent']);
        $this->artisan('bonuses:reconcile-ledger', ['--apply' => true])->assertFailed();
        $this->assertEquals('agent', $r->fresh()->requester_type);
        $this->artisan('bonuses:reconcile-ledger', ['--apply' => true, '--repair-types' => true])->assertSuccessful();
        $this->assertEquals('curator', $r->fresh()->requester_type);
        $this->assertEquals(1000, $r->fresh()->amount);
        $this->assertEquals(1000, $r->linkedBonuses()->first()->bonus->commission_amount);
        $this->assertNotNull($r->linkedBonuses()->first()->bonus->paid_at);
    }
}
