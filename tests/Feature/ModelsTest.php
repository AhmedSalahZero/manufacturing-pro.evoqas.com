<?php

namespace Tests\Feature;

use App\Models\BalanceSheet;
use App\Models\BusinessSector;
use App\Models\CashAndBankOpeningBalance;
use App\Models\CashInOutStatement;
use App\Models\EquityOpeningBalance;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetOpeningBalance;
use App\Models\IncomeStatement;
use App\Models\LoanSchedulePayment;
use App\Models\LongTermLoanOpeningBalance;
use App\Models\Manpower;
use App\Models\OtherCreditsOpeningBalance;
use App\Models\OtherDebtorsOpeningBalance;
use App\Models\OtherLongTermLiabilitiesOpeningBalance;
use App\Models\Product;
use App\Models\ProductExpenseAllocation;
use App\Models\Project;
use App\Models\RawMaterial;
use App\Models\Seasonality;
use App\Models\Sensitivity;
use App\Models\SensitivityLoanSchedulePayment;
use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use App\Models\SupplierPayableOpeningBalance;
use App\Models\User;
use App\Models\VatAndCreditWithholdTaxOpeningBalance;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// The models match the tables and each other. Run:  php artisan test --filter=ModelsTest
class ModelsTest extends TestCase
{
    use RefreshDatabase;

    /** Every model and the relations it offers. */
    private function models(): array
    {
        return [
        BusinessSector::class => ['projects'],
        Project::class => ['user', 'businessSector', 'products', 'rawMaterials', 'manpowers', 'expenses', 'fixedAssets', 'fixedAssetOpeningBalances', 'cashAndBankOpeningBalances', 'otherDebtorsOpeningBalances', 'supplierPayableOpeningBalances', 'otherCreditsOpeningBalances', 'otherLongTermLiabilitiesOpeningBalances', 'equityOpeningBalances', 'vatAndCreditWithholdTaxOpeningBalances', 'longTermLoanOpeningBalances', 'productExpenseAllocations', 'seasonalities', 'loanSchedulePayments', 'sensitivityLoanSchedulePayments', 'sharingLinks', 'sensitivity', 'incomeStatement', 'balanceSheet', 'cashInOutStatement'],
        Sensitivity::class => ['project', 'user'],
        Product::class => ['project', 'rawMaterials', 'expenseAllocations', 'seasonality'],
        RawMaterial::class => ['project', 'products'],
        Manpower::class => ['project'],
        BalanceSheet::class => ['project'],
        CashAndBankOpeningBalance::class => ['project'],
        CashInOutStatement::class => ['project'],
        EquityOpeningBalance::class => ['project'],
        Expense::class => ['project'],
        FixedAssetOpeningBalance::class => ['project'],
        FixedAsset::class => ['project', 'loanSchedulePayments', 'sensitivityLoanSchedulePayments'],
        IncomeStatement::class => ['project'],
        LoanSchedulePayment::class => ['fixedAsset', 'project'],
        LongTermLoanOpeningBalance::class => ['project'],
        OtherCreditsOpeningBalance::class => ['project'],
        OtherDebtorsOpeningBalance::class => ['project'],
        OtherLongTermLiabilitiesOpeningBalance::class => ['project'],
        ProductExpenseAllocation::class => ['project', 'product'],
        Seasonality::class => ['project'],
        SensitivityLoanSchedulePayment::class => ['fixedAsset', 'project'],
        SharingLink::class => ['user', 'project', 'visitors'],
        SharingLinkVisitor::class => ['link'],
        SupplierPayableOpeningBalance::class => ['project'],
        VatAndCreditWithholdTaxOpeningBalance::class => ['project'],
        User::class => ['projects', 'sharingLinks'],
        ];
    }

    public function test_every_model_points_to_a_real_table_and_only_casts_real_columns(): void
    {
        foreach (array_keys($this->models()) as $class) {
            $model = new $class();
            $this->assertTrue(Schema::hasTable($model->getTable()), "{$class}: table {$model->getTable()} is missing");

            $casts = array_keys($model->getCasts());
            foreach ($casts as $column) {
                $this->assertTrue(Schema::hasColumn($model->getTable(), $column), "{$class}: cast column {$column} does not exist");
            }
        }
    }

    public function test_every_relation_uses_columns_that_exist(): void
    {
        foreach ($this->models() as $class => $relations) {
            $model = new $class();

            foreach ($relations as $name) {
                $relation = $model->$name();
                $this->assertInstanceOf(Relation::class, $relation, "{$class}::{$name}");

                if ($relation instanceof BelongsTo) {
                    $this->assertTrue(Schema::hasColumn($model->getTable(), $relation->getForeignKeyName()), "{$class}::{$name}: foreign key");
                } elseif ($relation instanceof HasMany || $relation instanceof HasOne) {
                    $related = $relation->getRelated();
                    $key = $relation->getForeignKeyName();
                    $this->assertTrue(Schema::hasColumn($related->getTable(), $key), "{$class}::{$name}: foreign key {$key}");
                } elseif ($relation instanceof BelongsToMany) {
                    $this->assertTrue(Schema::hasTable($relation->getTable()), "{$class}::{$name}: pivot table");
                }
            }
        }
    }

    public function test_a_person_owns_projects_and_json_and_dates_come_back_as_php_values(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id'         => $user->id,
            'name'            => 'Plant A',
            'operation_dates' => ['2026-01' => 1, '2026-02' => 2],
            'start_date'      => '2026-01-01',
            'tax_rate'        => 22.5,
        ])->fresh();

        $this->assertSame(['2026-01' => 1, '2026-02' => 2], $project->operation_dates);
        $this->assertSame('2026-01-01', $project->start_date->toDateString());
        $this->assertFalse($project->is_completed);
        $this->assertSame('22.50', $project->tax_rate);
        $this->assertTrue($user->projects->first()->is($project));
        $this->assertTrue($project->user->is($user));
    }

    public function test_a_deleted_project_is_kept_and_can_be_restored(): void
    {
        $project = Project::create(['user_id' => User::factory()->create()->id, 'name' => 'Plant A']);

        $project->delete();

        $this->assertSame(0, Project::count());
        $this->assertSame(1, Project::withTrashed()->count());

        $project->restore();

        $this->assertSame(1, Project::count());
    }

    public function test_products_and_raw_materials_are_linked_with_a_percentage(): void
    {
        $project = Project::create(['user_id' => User::factory()->create()->id, 'name' => 'Plant A']);
        $product = Product::create(['project_id' => $project->id, 'name' => 'Bottle']);
        $material = RawMaterial::create(['project_id' => $project->id, 'name' => 'Plastic', 'rm_inventory_coverage_days' => 30]);

        $product->rawMaterials()->attach($material->id, ['percentages' => json_encode(['value' => 80]), 'project_id' => $project->id]);

        $this->assertSame(1, $product->rawMaterials()->count());
        $this->assertSame(1, $material->products()->count());
        $this->assertSame('Plastic', $product->rawMaterials->first()->name);
        $this->assertSame(1, $project->products()->count());
        $this->assertSame(1, $project->rawMaterials()->count());
        $this->assertTrue($product->project->is($project));
    }

    public function test_a_product_has_one_monthly_sales_pattern(): void
    {
        $project = Project::create(['user_id' => User::factory()->create()->id, 'name' => 'Plant A']);
        $product = Product::create(['project_id' => $project->id, 'name' => 'Bottle']);
        $other = Product::create(['project_id' => $project->id, 'name' => 'Cap']);

        $pattern = Seasonality::create([
            'model_id'                => $product->id,
            'model_name'              => 'Product',
            'percentages'             => ['q1' => 25],
            'distributed_percentages' => [8.33, 8.33],
        ]);

        $this->assertTrue($product->fresh()->seasonality->is($pattern));
        $this->assertSame('flat', $product->fresh()->seasonality->type);
        $this->assertSame([8.33, 8.33], $pattern->fresh()->distributed_percentages);
        $this->assertNull($other->fresh()->seasonality);
    }

    public function test_a_project_reaches_its_people_costs_and_results(): void
    {
        $project = Project::create(['user_id' => User::factory()->create()->id, 'name' => 'Plant A']);

        Manpower::create([
            'project_id' => $project->id, 'type' => 'direct', 'position' => 'Operator',
            'avg_salary' => '5000', 'existing_count' => '3', 'hirings' => ['2026-03' => 2],
        ]);
        Expense::create(['project_id' => $project->id, 'name' => 'Rent', 'collection_statements' => ['a' => 1]]);
        IncomeStatement::create(['project_id' => $project->id]);

        $this->assertSame(1, $project->manpowers()->count());
        $this->assertSame(['2026-03' => 2], $project->manpowers->first()->hirings);
        $this->assertSame(1, $project->expenses()->count());
        $this->assertNotNull($project->incomeStatement);
        $this->assertNull($project->balanceSheet);
    }

    public function test_a_sharing_link_knows_who_opened_it(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'name' => 'Plant A']);
        $link = SharingLink::create(['user_id' => $user->id, 'project_id' => $project->id, 'name' => 'For the bank', 'link_code' => 'abc123']);
        SharingLinkVisitor::create(['ip' => '10.0.0.1', 'link_code' => 'abc123']);
        SharingLinkVisitor::create(['ip' => '10.0.0.2', 'link_code' => 'other']);

        $this->assertSame(1, $link->visitors()->count());
        $this->assertTrue($link->visitors->first()->link->is($link));
        $this->assertSame(1, $project->sharingLinks()->count());
    }
}
