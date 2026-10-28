<?php

namespace Database\Seeders;

use App\Calculations\OpeningBalanceCalculator;
use App\Http\Requests\UpdateExpensesRequest;
use App\Http\Requests\UpdateFixedAssetsRequest;
use App\Http\Requests\UpdateManpowerRequest;
use App\Http\Requests\UpdateOpeningBalancesRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UpdateRawMaterialPaymentsRequest;
use App\Models\BusinessSector;
use App\Models\Company;
use App\Models\Project;
use App\Models\SharingLink;
use App\Models\User;
use App\Services\CostPlanService;
use App\Services\FixedAssetService;
use App\Services\OpeningBalanceService;
use App\Services\ProductPlanService;
use App\Services\ProjectService;
use App\Services\SalesCalculationService;
use App\Services\SensitivityService;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Demo company, demo user and two complete demo studies
//  Location: database/seeders/DemoSeeder.php
//  Run:  php artisan db:seed --class=DemoSeeder
//
//  Creates (only what is missing, so it can be run again without making copies):
//    · the company "Nile Foods Demo" and its administrator   demo@zavero.test  /  ZDemo@2026
//    · Study 1  "Delta Fresh Foods - New Factory (Demo)"     a NEW company, 5 years from 2027-01 (operation from 2027-04)
//    · Study 2  "Nile Valley Foods - Expansion (Demo)"       an EXISTING company, 5 years from 2026-01, with opening balances
//  Every figure goes through the very same checks and services as the screens (the form requests are run on the data
//  first), so if a number were not accepted by a screen, this seeder would stop and say which one.
//  Everything is in EGP. The opening balance sheet of Study 2 is balanced by its retained earnings.
// ══════════════════════════════════════════════════════════════════

class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@zavero.test';
    public const PASSWORD = 'ZDemo@2026';
    public const COMPANY = 'Nile Foods Demo';
    public const STUDY_NEW = 'Delta Fresh Foods - New Factory (Demo)';
    public const STUDY_EXISTING = 'Nile Valley Foods - Expansion (Demo)';

    private User $user;

    public function run(): void
    {
        $this->call(BusinessSectorSeeder::class);

        $company = Company::query()->firstOrCreate(['name_en' => self::COMPANY], [
            'name_ar'                => 'شركة نايل فودز التجريبية',
            'status'                 => 'active',
            'users_limit'            => 10,
            'subscription_starts_at' => today()->subMonth(),
            'subscription_ends_at'   => today()->addYears(3),
            'contact_email'          => self::EMAIL,
            'notes'                  => 'Demo company with two complete 5-year studies.',
        ]);

        $user = User::query()->where('email', self::EMAIL)->first();
        if ($user === null) {
            $user = User::query()->forceCreate([
                'company_id'        => $company->id,
                'name'              => 'Demo Manager',
                'email'             => self::EMAIL,
                'password'          => self::PASSWORD,
                'role'              => User::ROLE_COMPANY_ADMIN,
                'email_verified_at' => now(),
                'language'          => 'en',
            ]);
        }
        $this->user = $user;

        $this->command?->info('Demo company: '.self::COMPANY.'   login: '.self::EMAIL.'  /  '.self::PASSWORD);

        foreach ([self::STUDY_NEW => 'newFactory', self::STUDY_EXISTING => 'expansion'] as $name => $builder) {
            if (Project::query()->where('company_id', $company->id)->where('name', $name)->exists()) {
                $this->command?->warn("Study \"$name\" already exists - left as it is.");

                continue;
            }

            $project = $this->$builder($name);
            app(SalesCalculationService::class)->recalculate($project->refresh());
            $this->command?->info("Study \"$name\" is ready.");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  Study 1 - a new company: a new food factory
    // ══════════════════════════════════════════════════════════════════

    private function newFactory(string $name): Project
    {
        $project = app(ProjectService::class)->create($this->user, $name);
        $sector = BusinessSector::query()->where('name_en', 'Food & Beverage')->value('id');

        $project = $this->saveStudy($project, [
            'name' => $name, 'new_company' => true, 'main_functional_currency' => 'egp', 'business_sector_id' => $sector,
            'intro_paragraph' => 'A new factory in 10th of Ramadan City making packaged orange juice, butter biscuits, canned fava beans and frozen mixed vegetables for local supermarkets, wholesalers and export customers. Construction and installation take the first three months of 2027; production starts in April 2027.',
            'start_date' => '2027-01', 'duration' => 5, 'operation_start_date' => '2027-04',
            'tax_rate' => 22.5, 'return_rate' => 20, 'perpetual_growth_rate' => 3,
            'products' => [
                ['name' => 'Orange Juice 1L', 'measurement_unit' => 'carton', 'selling_start_date' => '2027-04', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Butter Biscuits', 'measurement_unit' => 'box', 'selling_start_date' => '2027-04', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Canned Fava Beans', 'measurement_unit' => 'carton', 'selling_start_date' => '2027-04', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Frozen Mixed Vegetables', 'measurement_unit' => 'ton', 'selling_start_date' => '2027-07', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials' => [
                ['name' => 'Fruits, vegetables and beans', 'rm_inventory_coverage_days' => 15, 'measurement_unit' => 'ton', 'vat_rate' => 0, 'withhold_tax_rate' => 0],
                ['name' => 'Sugar', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'ton', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Wheat flour', 'rm_inventory_coverage_days' => 45, 'measurement_unit' => 'ton', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Edible oils and butter', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Packaging materials', 'rm_inventory_coverage_days' => 60, 'measurement_unit' => 'piece', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
            ],
        ]);

        $p = $this->idsByName($project->products());
        $m = $this->idsByName($project->rawMaterials());
        $flat = ['type' => 'flat'];
        $juiceMonths = ['type' => 'monthly', 'monthly' => [5, 5, 6, 8, 10, 12, 13, 12, 9, 7, 7, 6]];
        $biscuitQuarters = ['type' => 'quarterly', 'quarterly' => [22, 22, 24, 32]];

        $this->saveProduct($project, $p['Orange Juice 1L'], [
            'capacity' => array_fill(0, 5, 240000), 'target' => [40, 62, 78, 86, 90], 'local' => 90, 'price' => 180, 'export_price' => 210,
            'seasonality' => $juiceMonths, 'coverage' => 30,
            'local_terms' => $this->terms([25, 45, 30, 30, 60], [30, 40, 30, 30, 60]), 'export_terms' => $this->terms([0, 60, 40, 60, 90], [10, 50, 40, 60, 90]),
            'recipe' => [[$m['Fruits, vegetables and beans'], 28], [$m['Sugar'], 8], [$m['Packaging materials'], 10]],
            'comment' => 'Summer is the high season; the monthly pattern follows the cold-drink demand.',
        ]);
        $this->saveProduct($project, $p['Butter Biscuits'], [
            'capacity' => array_fill(0, 5, 480000), 'target' => [38, 60, 76, 85, 90], 'local' => 85, 'price' => 60, 'export_price' => 72,
            'seasonality' => $biscuitQuarters, 'coverage' => 45,
            'local_terms' => $this->terms([20, 50, 30, 30, 60], [25, 45, 30, 30, 60]), 'export_terms' => $this->terms([0, 60, 40, 60, 90], [10, 50, 40, 60, 90]),
            'recipe' => [[$m['Wheat flour'], 18], [$m['Sugar'], 7], [$m['Edible oils and butter'], 14], [$m['Packaging materials'], 8]],
            'comment' => 'Winter and Ramadan quarters carry the largest share of biscuit sales.',
        ]);
        $this->saveProduct($project, $p['Canned Fava Beans'], [
            'capacity' => array_fill(0, 5, 100000), 'target' => [35, 58, 74, 84, 88], 'local' => 70, 'price' => 300, 'export_price' => 360,
            'seasonality' => $flat, 'coverage' => 60,
            'local_terms' => $this->terms([20, 40, 40, 45, 90], [25, 40, 35, 45, 90]), 'export_terms' => $this->terms([0, 50, 50, 60, 120], [0, 60, 40, 60, 120]),
            'recipe' => [[$m['Fruits, vegetables and beans'], 32], [$m['Edible oils and butter'], 3], [$m['Packaging materials'], 14]],
        ]);
        $this->saveProduct($project, $p['Frozen Mixed Vegetables'], [
            'capacity' => array_fill(0, 5, 1800), 'target' => [20, 52, 72, 82, 88], 'local' => 80, 'price' => 25000, 'export_price' => 31000,
            'seasonality' => $flat, 'coverage' => 45,
            'local_terms' => $this->terms([30, 40, 30, 30, 60], [30, 40, 30, 30, 60]), 'export_terms' => $this->terms([0, 60, 40, 60, 90], [0, 60, 40, 60, 90]),
            'recipe' => [[$m['Fruits, vegetables and beans'], 40], [$m['Packaging materials'], 7]],
            'comment' => 'Selling starts in July 2027, after the freezing tunnel is commissioned.',
        ]);

        $this->saveRawPayments($project, [
            $m['Fruits, vegetables and beans'] => $this->terms([60, 40, 0, 30, 60], [60, 40, 0, 30, 60]),
            $m['Sugar']                        => $this->terms([20, 50, 30, 30, 60], [20, 50, 30, 30, 60]),
            $m['Wheat flour']                  => $this->terms([20, 50, 30, 30, 60], [20, 50, 30, 30, 60]),
            $m['Edible oils and butter']       => $this->terms([10, 60, 30, 30, 60], [10, 60, 30, 30, 60]),
            $m['Packaging materials']          => $this->terms([0, 60, 40, 30, 90], [0, 60, 40, 30, 90]),
        ], 'Fresh produce is bought mostly for cash; packaging is bought on 30 and 90 days.');

        // Staff: nobody exists yet (new company). Hirings are filed under the month position of the study (0 = January 2027).
        $this->saveManpower($project, [
            ['direct_labor', 'Production line workers', 7500, 0, [3 => 30, 15 => 10, 27 => 10, 39 => 5]],
            ['direct_labor', 'Line supervisors', 14000, 0, [3 => 4, 27 => 2]],
            ['direct_labor', 'Packing and warehouse workers', 6800, 0, [3 => 12, 15 => 6, 39 => 4]],
            ['manufacturing_overheads', 'Maintenance technicians', 11000, 0, [3 => 4, 27 => 2]],
            ['manufacturing_overheads', 'Quality control engineers', 18000, 0, [3 => 3, 27 => 1]],
            ['manufacturing_overheads', 'Plant manager', 55000, 0, [3 => 1]],
            ['operational_salaries', 'Procurement and logistics officers', 13000, 0, [3 => 3, 27 => 1]],
            ['sales', 'Sales representatives', 12000, 0, [3 => 8, 15 => 6, 27 => 6]],
            ['sales', 'Sales manager', 38000, 0, [3 => 1]],
            ['marketing', 'Marketing specialists', 17000, 0, [3 => 2, 15 => 1]],
            ['general', 'General manager', 80000, 0, [3 => 1]],
            ['general', 'Accountants', 15000, 0, [3 => 3, 27 => 1]],
            ['general', 'HR and admin officers', 13500, 0, [3 => 2]],
        ], [
            'direct_labor' => ['by_revenue' => false, 'allocations' => [$p['Orange Juice 1L'] => 30, $p['Butter Biscuits'] => 25, $p['Canned Fava Beans'] => 20, $p['Frozen Mixed Vegetables'] => 25]],
        ]);

        $all = array_values($p);
        $this->saveExpenses($project, [
            // before operation
            $this->expense('pre-operating-expenses', 'one_time_expense', 'Licences and permits', ['amount' => 1500000, 'start' => '2027-01', 'amortization_months' => 36]),
            $this->expense('pre-operating-expenses', 'one_time_expense', 'Recruitment and training before opening', ['amount' => 800000, 'start' => '2027-03', 'amortization_months' => 24, 'payment_terms' => 'quarterly']),
            $this->expense('start-up-expenses', 'one_time_expense', 'Launch campaign', ['amount' => 1200000, 'start' => '2027-04', 'amortization_months' => 12, 'payment_terms' => 'customize', 'custom' => [['due_days' => 30, 'rate' => 50], ['due_days' => 60, 'rate' => 50]]]),
            // manufacturing
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Electricity, gas and water', ['amount' => 180000, 'start' => '2027-04', 'end' => '2031-12', 'increase_rate' => 12]),
            $this->expense('manufacturing-expenses', 'expense_as_percentage', 'Maintenance and spare parts', ['monthly_percentage' => 1.5, 'start' => '2027-04', 'end' => '2031-12', 'products' => $all]),
            $this->expense('other-operation-expenses', 'fixed_monthly_repeating_amount', 'Laboratory testing and certificates', ['amount' => 40000, 'start' => '2027-04', 'end' => '2031-12', 'increase_rate' => 8, 'payment_terms' => 'quarterly']),
            // selling
            $this->expense('sales', 'cost_per_unit', 'Delivery cost per unit', ['monthly_cost_of_unit' => 3, 'start' => '2027-04', 'end' => '2031-12', 'increase_rate' => 10, 'products' => [$p['Orange Juice 1L'], $p['Butter Biscuits'], $p['Canned Fava Beans']]]),
            $this->expense('sales', 'expense_as_percentage', 'Sales commissions', ['monthly_percentage' => 1.2, 'start' => '2027-04', 'end' => '2031-12', 'products' => $all]),
            $this->expense('marketing', 'expense_as_percentage', 'Advertising and promotions', ['monthly_percentage' => 3, 'start' => '2027-04', 'end' => '2031-12', 'products' => $all]),
            // general
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Head office rent', ['amount' => 60000, 'start' => '2027-01', 'end' => '2031-12', 'increase_rate' => 10, 'payment_terms' => 'quarterly']),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Insurance', ['amount' => 25000, 'start' => '2027-01', 'end' => '2031-12', 'increase_rate' => 8, 'payment_terms' => 'annually']),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Audit, legal and consulting fees', ['amount' => 20000, 'start' => '2027-01', 'end' => '2031-12', 'increase_rate' => 8]),
        ]);

        $this->saveAssets($project, [
            $this->asset('Factory building and land', 1, 24000000, 20, '2027-01', '2027-03', ['admin_depreciation_percentage' => 10, 'equity_funding_rate' => 50, 'interest_rate' => 18, 'grace_period' => 12, 'tenor' => 84, 'installment_interval' => 'quarterly']),
            $this->asset('Juice production line', 1, 14000000, 10, '2027-02', '2027-03', ['equity_funding_rate' => 60, 'interest_rate' => 18, 'grace_period' => 6, 'tenor' => 60, 'installment_interval' => 'quarterly', 'is_as_revenue_percentages' => false, 'allocations' => [$p['Orange Juice 1L'] => 100, $p['Butter Biscuits'] => 0, $p['Canned Fava Beans'] => 0, $p['Frozen Mixed Vegetables'] => 0]]),
            $this->asset('Biscuit production line', 1, 11000000, 10, '2027-02', '2027-03', ['is_as_revenue_percentages' => false, 'allocations' => [$p['Orange Juice 1L'] => 0, $p['Butter Biscuits'] => 100, $p['Canned Fava Beans'] => 0, $p['Frozen Mixed Vegetables'] => 0]]),
            $this->asset('Canning and freezing equipment', 2, 6500000, 10, '2027-02', '2027-03', ['payment_terms' => 'customize', 'custom' => [['due_days' => 0, 'rate' => 30, 'from_total' => false], ['due_days' => 90, 'rate' => 40, 'from_total' => false], ['due_days' => 180, 'rate' => 30, 'from_total' => false]], 'is_as_revenue_percentages' => false, 'allocations' => [$p['Orange Juice 1L'] => 0, $p['Butter Biscuits'] => 0, $p['Canned Fava Beans'] => 40, $p['Frozen Mixed Vegetables'] => 60]]),
            $this->asset('Delivery vehicles', 6, 900000, 5, '2027-03', '2027-03', ['admin_depreciation_percentage' => 0]),
            $this->asset('Laboratory and office equipment', 1, 1500000, 5, '2027-01', '2027-03', ['admin_depreciation_percentage' => 100]),
        ]);

        $this->extras($project, [
            ['slot' => 'first', 'target' => -10, 'rm' => 5, 'days' => 30],
            ['slot' => 'second', 'target' => 10, 'rm' => 0, 'days' => 0],
        ], 'Revenue grows as the lines reach 90% of their capacity by 2031.', 'The factory is funded by the owners and bank loans for the building and the juice line.');

        return $project;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Study 2 - an existing company: expansion of a running food factory (uses the opening balances)
    // ══════════════════════════════════════════════════════════════════

    private function expansion(string $name): Project
    {
        $project = app(ProjectService::class)->create($this->user, $name);
        $sector = BusinessSector::query()->where('name_en', 'Food & Beverage')->value('id');

        $project = $this->saveStudy($project, [
            'name' => $name, 'new_company' => false, 'main_functional_currency' => 'egp', 'business_sector_id' => $sector,
            'intro_paragraph' => 'A running factory in Sadat City that makes tomato paste, pasta and mango juice. The plan adds a new pasta production line and a tomato processing expansion in 2026, a bigger warehouse by early 2027 and renews the delivery fleet in 2028. The opening balances are the figures of the factory on 1 January 2026.',
            'start_date' => '2026-01', 'duration' => 5, 'operation_start_date' => '2026-01',
            'tax_rate' => 22.5, 'return_rate' => 18, 'perpetual_growth_rate' => 3,
            'products' => [
                ['name' => 'Tomato Paste 800g', 'measurement_unit' => 'carton', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Pasta 400g', 'measurement_unit' => 'carton', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Mango Juice 200ml', 'measurement_unit' => 'carton', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials' => [
                ['name' => 'Fresh tomatoes and fruit pulp', 'rm_inventory_coverage_days' => 15, 'measurement_unit' => 'ton', 'vat_rate' => 0, 'withhold_tax_rate' => 0],
                ['name' => 'Durum wheat semolina', 'rm_inventory_coverage_days' => 45, 'measurement_unit' => 'ton', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Sugar and sweeteners', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'ton', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Cans, bottles and cartons', 'rm_inventory_coverage_days' => 60, 'measurement_unit' => 'piece', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
            ],
        ]);

        $p = $this->idsByName($project->products());
        $m = $this->idsByName($project->rawMaterials());

        // Finished goods already in the warehouse on the first day (they are part of the opening balance sheet).
        $mix = ['raw_material_value' => ['percentage' => 70], 'direct_labor_value' => ['percentage' => 12], 'manufacturing_overheads_value' => ['percentage' => 18]];
        $stock = [
            'Tomato Paste 800g' => ['quantity' => 6000, 'value' => 3000000],
            'Pasta 400g'        => ['quantity' => 14000, 'value' => 2500000],
            'Mango Juice 200ml' => ['quantity' => 15000, 'value' => 2000000],
        ];
        $inventory = fn (string $product) => ['existing' => ['fg_inventory_quantity' => $stock[$product]['quantity'], 'fg_inventory_value' => $stock[$product]['value'], 'fg_beginning_inventory_breakdowns' => $mix]];

        $this->saveProduct($project, $p['Tomato Paste 800g'], [
            'capacity' => [150000, 165000, 180000, 180000, 180000], 'target' => [78, 76, 78, 80, 82], 'local' => 85, 'price' => 520, 'export_price' => 600,
            'seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 35, 30, 15]], 'coverage' => 45,
            'local_terms' => $this->terms([20, 50, 30, 30, 60], [25, 45, 30, 30, 60]), 'export_terms' => $this->terms([0, 60, 40, 60, 90], [10, 50, 40, 60, 90]),
            'recipe' => [[$m['Fresh tomatoes and fruit pulp'], 30], [$m['Sugar and sweeteners'], 1], [$m['Cans, bottles and cartons'], 16]],
            'comment' => 'The tomato season (summer) lifts production and sales; capacity grows after the processing expansion is commissioned in late 2026.',
        ] + $inventory('Tomato Paste 800g'));
        $this->saveProduct($project, $p['Pasta 400g'], [
            'capacity' => [400000, 560000, 560000, 560000, 560000], 'target' => [70, 58, 66, 72, 76], 'local' => 95, 'price' => 190, 'export_price' => 210,
            'seasonality' => ['type' => 'flat'], 'coverage' => 30,
            'local_terms' => $this->terms([30, 40, 30, 30, 60], [30, 40, 30, 30, 60]), 'export_terms' => $this->terms([0, 60, 40, 60, 90], [0, 60, 40, 60, 90]),
            'recipe' => [[$m['Durum wheat semolina'], 33], [$m['Cans, bottles and cartons'], 9]],
        ] + $inventory('Pasta 400g'));
        $this->saveProduct($project, $p['Mango Juice 200ml'], [
            'capacity' => array_fill(0, 5, 300000), 'target' => [65, 70, 75, 78, 80], 'local' => 90, 'price' => 135, 'export_price' => 150,
            'seasonality' => ['type' => 'monthly', 'monthly' => [4, 4, 6, 8, 11, 13, 14, 13, 9, 7, 6, 5]], 'coverage' => 45,
            'local_terms' => $this->terms([25, 45, 30, 30, 60], [30, 40, 30, 30, 60]), 'export_terms' => $this->terms([0, 60, 40, 60, 90], [10, 50, 40, 60, 90]),
            'recipe' => [[$m['Fresh tomatoes and fruit pulp'], 24], [$m['Sugar and sweeteners'], 9], [$m['Cans, bottles and cartons'], 13]],
        ] + $inventory('Mango Juice 200ml'));

        $this->saveRawPayments($project, [
            $m['Fresh tomatoes and fruit pulp'] => $this->terms([60, 40, 0, 30, 60], [60, 40, 0, 30, 60]),
            $m['Durum wheat semolina']          => $this->terms([20, 50, 30, 30, 60], [20, 50, 30, 30, 60]),
            $m['Sugar and sweeteners']          => $this->terms([20, 50, 30, 30, 60], [20, 50, 30, 30, 60]),
            $m['Cans, bottles and cartons']     => $this->terms([0, 60, 40, 30, 90], [0, 60, 40, 30, 90]),
        ], 'Long-standing suppliers give 30 to 90 days.');

        $this->saveManpower($project, [
            ['direct_labor', 'Production workers', 7000, 95, [12 => 8, 24 => 8]],
            ['direct_labor', 'Line supervisors', 13000, 9, [24 => 2]],
            ['direct_labor', 'Packing and warehouse workers', 6500, 28, [12 => 4]],
            ['manufacturing_overheads', 'Maintenance technicians', 10500, 7, []],
            ['manufacturing_overheads', 'Quality control engineers', 17000, 4, [12 => 1]],
            ['manufacturing_overheads', 'Plant manager', 52000, 1, []],
            ['operational_salaries', 'Procurement and logistics officers', 12500, 5, []],
            ['sales', 'Sales representatives', 11500, 12, [6 => 3, 18 => 3]],
            ['sales', 'Sales manager', 36000, 1, []],
            ['marketing', 'Marketing specialists', 16000, 3, [12 => 1]],
            ['general', 'General manager', 75000, 1, []],
            ['general', 'Accountants', 14000, 5, []],
            ['general', 'HR and admin officers', 12500, 3, []],
        ], []);

        $all = array_values($p);
        $this->saveExpenses($project, [
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Electricity, gas and water', ['amount' => 150000, 'start' => '2026-01', 'end' => '2030-12', 'increase_rate' => 12]),
            $this->expense('manufacturing-expenses', 'expense_as_percentage', 'Maintenance and spare parts', ['monthly_percentage' => 1.6, 'start' => '2026-01', 'end' => '2030-12', 'products' => $all]),
            $this->expense('other-operation-expenses', 'fixed_monthly_repeating_amount', 'Quality and laboratory testing', ['amount' => 40000, 'start' => '2026-01', 'end' => '2030-12', 'increase_rate' => 8, 'payment_terms' => 'quarterly']),
            $this->expense('sales', 'cost_per_unit', 'Delivery cost per unit', ['monthly_cost_of_unit' => 4, 'start' => '2026-01', 'end' => '2030-12', 'increase_rate' => 10, 'products' => $all]),
            $this->expense('sales', 'expense_as_percentage', 'Sales commissions', ['monthly_percentage' => 1, 'start' => '2026-01', 'end' => '2030-12', 'products' => $all]),
            $this->expense('marketing', 'expense_as_percentage', 'Advertising and promotions', ['monthly_percentage' => 2.5, 'start' => '2026-01', 'end' => '2030-12', 'products' => $all]),
            $this->expense('marketing', 'one_time_expense', 'Trade fairs and exhibitions', ['amount' => 600000, 'start' => '2026-09', 'amortization_months' => 12]),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Head office rent', ['amount' => 55000, 'start' => '2026-01', 'end' => '2030-12', 'increase_rate' => 10, 'payment_terms' => 'quarterly']),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Insurance', ['amount' => 30000, 'start' => '2026-01', 'end' => '2030-12', 'increase_rate' => 8, 'payment_terms' => 'annually']),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Audit and legal fees', ['amount' => 22000, 'start' => '2026-01', 'end' => '2030-12', 'increase_rate' => 8, 'payment_terms' => 'semi-annually']),
            $this->expense('general-expenses', 'one_time_expense', 'ERP system upgrade', ['amount' => 1800000, 'start' => '2026-07', 'amortization_months' => 36, 'payment_terms' => 'customize', 'custom' => [['due_days' => 30, 'rate' => 50], ['due_days' => 60, 'rate' => 50]]]),
        ]);

        $this->saveAssets($project, [
            $this->asset('New pasta production line', 1, 38000000, 10, '2026-03', '2026-08', ['equity_funding_rate' => 50, 'interest_rate' => 17, 'grace_period' => 6, 'tenor' => 72, 'installment_interval' => 'quarterly', 'is_as_revenue_percentages' => false, 'allocations' => [$p['Tomato Paste 800g'] => 0, $p['Pasta 400g'] => 100, $p['Mango Juice 200ml'] => 0]]),
            $this->asset('Tomato processing expansion', 1, 14000000, 10, '2026-04', '2026-08', ['payment_terms' => 'customize', 'custom' => [['due_days' => 0, 'rate' => 40, 'from_total' => false], ['due_days' => 60, 'rate' => 30, 'from_total' => false], ['due_days' => 120, 'rate' => 30, 'from_total' => false]], 'is_as_revenue_percentages' => false, 'allocations' => [$p['Tomato Paste 800g'] => 70, $p['Pasta 400g'] => 0, $p['Mango Juice 200ml'] => 30]]),
            $this->asset('Warehouse expansion', 1, 12000000, 20, '2026-09', '2027-02', ['admin_depreciation_percentage' => 20, 'equity_funding_rate' => 100]),
            $this->asset('Delivery fleet renewal', 4, 950000, 5, '2028-01', '2028-01', []),
        ]);

        $this->saveOpeningBalances($project);

        $this->extras($project, [
            ['slot' => 'first', 'target' => 5, 'rm' => -3, 'days' => 0],
        ], 'The two expansions in 2026 raise the capacity of pasta by 40% and of tomato paste by 20% by 2028.', 'The new pasta line is partly financed by a bank loan; the tomato processing expansion is paid in three instalments.');

        return $project;
    }

    /** The factory on its first day: everything balances, with the retained earnings as the last figure. */
    private function saveOpeningBalances(Project $project): void
    {
        $loans = 20000000.0;
        $installments = [];
        $interests = [];
        $left = $loans;
        foreach ([5, 11, 17, 23, 29, 35, 41, 47, 53, 59] as $month) {
            $installments[$month] = 2000000;
            $interests[$month] = (int) round($left * 0.09);
            $left -= 2000000;
        }

        $assets = [
            ['name' => 'Plant and machinery', 'gross_amount' => 45000000, 'accumulated_depreciation' => 18000000, 'monthly_depreciation' => 375000, 'monthly_counts' => 72, 'admin_depreciation_percentage' => 0, 'is_as_revenue_percentages' => true],
            ['name' => 'Buildings', 'gross_amount' => 30000000, 'accumulated_depreciation' => 6000000, 'monthly_depreciation' => 125000, 'monthly_counts' => 192, 'admin_depreciation_percentage' => 10, 'is_as_revenue_percentages' => true],
            ['name' => 'Vehicles', 'gross_amount' => 4500000, 'accumulated_depreciation' => 3000000, 'monthly_depreciation' => 75000, 'monthly_counts' => 20, 'admin_depreciation_percentage' => 0, 'is_as_revenue_percentages' => true],
            ['name' => 'Land', 'gross_amount' => 10000000, 'accumulated_depreciation' => 0, 'monthly_depreciation' => 0, 'monthly_counts' => 0, 'admin_depreciation_percentage' => 0, 'is_as_revenue_percentages' => true],
        ];

        $netAssets = 62500000;
        $cash = 8000000;
        $receivables = 14000000;
        $inventory = 7500000;             // the finished goods typed on the product forms
        $otherDebtors = 2500000;
        $suppliers = 9000000;
        $otherCreditors = 2500000;
        $vat = 800000;
        $tax = 1200000;
        $otherLongTerm = 3000000;
        $paidUp = 40000000;
        $reserve = 4000000;

        $totalAssets = $netAssets + $cash + $receivables + $inventory + $otherDebtors;
        $retained = $totalAssets - ($suppliers + $otherCreditors + $vat + $tax + $loans + $otherLongTerm + $paidUp + $reserve);

        $data = [
            'fixed_assets'    => $assets,
            'cash'            => ['cash_and_bank_amount' => $cash, 'customer_receivable_amount' => $receivables, 'settlements' => [1 => 6000000, 2 => 5000000, 3 => 3000000]],
            'other_debtors'   => [
                ['name' => 'Advances to suppliers', 'amount' => 2000000, 'settlements' => [2 => 1000000, 4 => 1000000]],
                ['name' => 'Staff loans', 'amount' => 500000, 'settlements' => [11 => 500000]],
            ],
            'suppliers'       => ['amount' => $suppliers, 'settlements' => [1 => 4000000, 2 => 3000000, 3 => 2000000]],
            'other_creditors' => [['name' => 'Accrued expenses', 'amount' => $otherCreditors, 'settlements' => [1 => 2500000]]],
            'taxes'           => ['vat_amount' => $vat, 'credit_withhold_taxes' => 0, 'corporate_taxes_payable' => $tax],
            'loans'           => [['amount' => $loans, 'installments' => $installments, 'interests' => $interests]],
            'other_long_term' => [['amount' => $otherLongTerm, 'settlements' => [23 => 1500000, 35 => 1500000]]],
            'equity'          => ['paid_up_capital_amount' => $paidUp, 'legal_reserve' => $reserve, 'retained_earnings' => $retained],
            'after'           => 'stay',
        ];

        $valid = $this->validated(UpdateOpeningBalancesRequest::class, $data, $project);

        DB::transaction(function () use ($project, $valid) {
            $balances = app(OpeningBalanceService::class);
            $balances->save($project, $valid);

            $check = OpeningBalanceCalculator::balance($balances->totals($project->refresh()));
            if (! $check['balanced']) {
                throw new \RuntimeException('The demo opening balance sheet does not balance (difference '.round($check['difference']).').');
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  Saving each form (the form request runs first, then the same service the screen uses)
    // ══════════════════════════════════════════════════════════════════

    private function saveStudy(Project $project, array $data): Project
    {
        return app(ProjectService::class)->save($project, $this->validated(UpdateProjectRequest::class, $data, $project));
    }

    /** @param array<string,mixed> $o capacity, target, local, price, export_price, seasonality, coverage, local_terms, export_terms, recipe, comment, existing */
    private function saveProduct(Project $project, int $productId, array $o): void
    {
        $growth = 8;
        $exportGrowth = 5;
        $years = range(0, 4);

        $data = [
            'max_capacity'                      => $o['capacity'],
            'target_percentages'                => $o['target'],
            'local_target_quantity_percentages' => array_fill(0, 5, $o['local']),
            'local_growth_rates'                => [0, $growth, $growth, $growth, $growth],
            'local_price_per_unit'              => array_map(fn ($y) => (int) round($o['price'] * ((1 + $growth / 100) ** $y)), $years),
            'export_growth_rates'               => [0, $exportGrowth, $exportGrowth, $exportGrowth, $exportGrowth],
            'export_price_per_unit'             => array_map(fn ($y) => (int) round($o['export_price'] * ((1 + $exportGrowth / 100) ** $y)), $years),
            'seasonality'                       => $o['seasonality'],
            'fg_inventory_coverage_days'        => $o['coverage'],
            'comment_for_sales'                 => $o['comment'] ?? null,
            'collection_policy_value'           => ['local' => $o['local_terms'], 'export' => $o['export_terms']],
            'rawMaterials'                      => array_map(fn ($line) => ['raw_material_id' => $line[0], 'percentages' => array_fill(0, 5, $line[1])], $o['recipe']),
            'after'                             => 'stay',
        ];
        foreach ($o['existing'] ?? [] as $key => $value) {
            $data[$key] = $value;
        }

        $product = $project->products()->whereKey($productId)->firstOrFail();
        app(ProductPlanService::class)->save($project, $product, $this->validated(UpdateProductRequest::class, $data, $project));
    }

    /** @param array<int,array<int,array<string,mixed>>> $policies raw material id => [period => terms] */
    private function saveRawPayments(Project $project, array $policies, string $comment): void
    {
        $rawMaterials = [];
        foreach ($policies as $id => $policy) {
            $rawMaterials[$id] = ['collection_policy_value' => $policy];
        }

        $valid = $this->validated(UpdateRawMaterialPaymentsRequest::class, ['rawMaterials' => $rawMaterials, 'comment_for_raw_materials' => $comment, 'after' => 'stay'], $project);

        // The same steps as RawMaterialPaymentController::update.
        $periods = $project->duration > 1 ? 2 : 1;
        foreach ($project->rawMaterials()->get() as $material) {
            $input = $valid['rawMaterials'][$material->id]['collection_policy_value'] ?? null;
            if ($input === null) {
                continue;
            }
            $policy = [];
            for ($i = 0; $i < $periods; $i++) {
                $policy[$i] = ProductPlanService::mergeDueDays($input[$i] ?? []);
            }
            $material->update(['collection_policy_value' => $policy, 'collection_policy_type' => 'customize', 'collection_policy_interval' => 'monthly']);
        }
        $project->update(['comment_for_raw_materials' => $valid['comment_for_raw_materials'] ?? null]);
        app(SalesCalculationService::class)->recalculate($project->refresh());
    }

    /**
     * @param list<array{0:string,1:string,2:int|float,3:int|float,4:array<int,int|float>}> $rows type, position, salary, existing staff, hirings (month position => number)
     * @param array<string,array{by_revenue:bool,allocations:array<int,int|float>}> $typed shares per type (the others follow the revenue)
     */
    private function saveManpower(Project $project, array $rows, array $typed): void
    {
        $data = [
            'salaries_annual_increase' => 10, 'salaries_tax_rate' => 8, 'social_insurance_rate' => 11,
            'rows' => array_map(fn ($r) => ['type' => $r[0], 'position' => $r[1], 'avg_salary' => $r[2], 'existing_count' => $r[3], 'hirings' => $r[4]], $rows),
            'by_revenue' => [], 'allocations' => [],
            'after' => 'stay',
        ];
        foreach ($typed as $type => $setting) {
            $data['by_revenue'][$type] = $setting['by_revenue'];
            $data['allocations'][$type] = $setting['allocations'];
        }

        app(CostPlanService::class)->saveManpower($project, $this->validated(UpdateManpowerRequest::class, $data, $project));
    }

    /** @param list<array<string,mixed>> $rows */
    private function saveExpenses(Project $project, array $rows): void
    {
        app(CostPlanService::class)->saveExpenses($project, $this->validated(UpdateExpensesRequest::class, ['rows' => $rows, 'after' => 'stay'], $project));
    }

    /** @param list<array<string,mixed>> $rows */
    private function saveAssets(Project $project, array $rows): void
    {
        app(FixedAssetService::class)->save($project, $this->validated(UpdateFixedAssetsRequest::class, ['rows' => $rows, 'after' => 'stay'], $project));
    }

    /** What-if settings, the two notes under the dashboard and one sharing link. */
    private function extras(Project $project, array $sensitivity, string $noteOne, string $noteTwo): void
    {
        app(SensitivityService::class)->save($project, $this->user, $sensitivity);
        $project->update(['dashboard_comment_1' => $noteOne, 'dashboard_comment_2' => $noteTwo]);

        SharingLink::create([
            'project_id' => $project->id,
            'user_id'    => $this->user->id,
            'name'       => 'Bank presentation',
            'link_code'  => Str::random(40),
            'closed'     => false,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Small helpers
    // ══════════════════════════════════════════════════════════════════

    /**
     * Runs a form request on the data, exactly as the screen would, and returns what it accepts.
     * If something were wrong it stops and says which form and which field.
     *
     * @param  class-string<FormRequest>  $class
     * @return array<string,mixed>
     */
    private function validated(string $class, array $data, Project $project): array
    {
        $request = $class::create('/demo', 'PUT', $data);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $this->user);
        $request->setRouteResolver(fn () => new class($project) {
            public function __construct(private readonly Project $project)
            {
            }

            public function parameter(string $name, mixed $default = null): mixed
            {
                return $name === 'project' ? $this->project : $default;
            }
        });

        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            throw new \RuntimeException('Demo data was refused by '.class_basename($class).': '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        return $request->validated();
    }

    /** @return array<string,int> name => id, in the order they were added */
    private function idsByName($relation): array
    {
        return $relation->orderBy('id')->pluck('id', 'name')->all();
    }

    /**
     * Collection (or payment) terms for the first operation year and the following years.
     *
     * @param  array{0:int,1:int,2:int,3:int,4:int}  $first  cash %, first rate %, second rate %, first due days, second due days
     * @param  array{0:int,1:int,2:int,3:int,4:int}  $next
     * @return array<int,array<string,mixed>>
     */
    private function terms(array $first, array $next): array
    {
        $period = fn (array $t) => ['cash_payment' => $t[0], 'rate' => [$t[1], $t[2]], 'due_in_days' => [$t[3], $t[4]]];

        return [0 => $period($first), 1 => $period($next)];
    }

    /** @param array<string,mixed> $over */
    private function expense(string $category, string $kind, string $name, array $over): array
    {
        return array_merge([
            'relation_name' => $kind, 'category_id' => $category, 'name' => $name,
            'increase_rate' => 0, 'payment_terms' => 'cash', 'is_as_revenue_percentages' => true,
        ], $over);
    }

    /** @param array<string,mixed> $over */
    private function asset(string $name, int $counts, int $amount, int $years, string $start, string $end, array $over): array
    {
        return array_merge([
            'name' => $name, 'counts' => $counts, 'amount' => $amount, 'depreciation_duration' => $years, 'start' => $start, 'end' => $end,
            'admin_depreciation_percentage' => 0, 'replacement_cost_rate' => 0, 'replacement_cost_interval' => 1,
            'payment_terms' => 'cash', 'equity_funding_rate' => 100, 'interest_rate' => 0, 'grace_period' => 0, 'tenor' => 60, 'installment_interval' => 'monthly',
            'is_as_revenue_percentages' => true,
        ], $over);
    }
}
