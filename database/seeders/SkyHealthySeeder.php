<?php

namespace Database\Seeders;

use App\Http\Requests\UpdateExpensesRequest;
use App\Http\Requests\UpdateFixedAssetsRequest;
use App\Http\Requests\UpdateManpowerRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Requests\UpdateRawMaterialPaymentsRequest;
use App\Models\BusinessSector;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\CostPlanService;
use App\Services\FixedAssetService;
use App\Services\ProductPlanService;
use App\Services\ProjectService;
use App\Services\SalesCalculationService;
use App\Services\SensitivityService;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Sky Healthy Food: eggshell calcium carbonate plant (SKY-CAL)
//  Location: database/seeders/SkyHealthySeeder.php
//  Run:  php artisan db:seed --class=SkyHealthySeeder
//
//  Creates ONE study (only if it is not there yet, so it can be run again without making copies):
//    "Sky Healthy Food - SKY-CAL Eggshell Calcium Carbonate Plant (Initial Feasibility)"
//    a NEW company, 7 years from 2027-01 (installation Jan-Jun 2027, operation and first sales from 2027-07), EGP.
//  The company "Sky Healthy Food" and its administrator are created first (only if missing); the study belongs to that
//  company. Login: admin@sky.com  /  Sky@Zavero2026. The demo company is never used or changed.
//
//  Every figure goes through the very same checks and services as the screens (the form requests are run on the data
//  first), so if a number were not accepted by a screen, this seeder stops and says which form and which field.
//
//  WHERE THE NUMBERS COME FROM (details in docs/SKY_HEALTHY_FOOD_STUDY.md):
//    client documents  = process flow (Dr. Emara, June 2026), product data sheet, safety data sheet, progress report
//                        (Jan 2026), Egyptian customs import data Jan-Aug 2026
//    market data       = USD/EGP about 54 (21-Oct-2026), Central Bank of Egypt overnight lending rate 20% (20-Aug-2026)
//    expert estimate   = everything not yet quoted: equipment, staffing, utilities, selling prices
//  Quantities are in TONS, prices in EGP per ton (the application rounds prices to whole numbers, so per-kg prices
//  would lose precision).
// ══════════════════════════════════════════════════════════════════

class SkyHealthySeeder extends Seeder
{
    private User $user;

    // ── the company and its administrator (created if missing) ───────────────────────────────
    public const COMPANY = 'Sky Healthy Food';
    public const EMAIL = 'admin@sky.com';
    public const PASSWORD = 'Sky@Zavero2026';

    public const STUDY = 'Sky Healthy Food - SKY-CAL Eggshell Calcium Carbonate Plant (Initial Feasibility)';

    // ── general assumptions ──────────────────────────────────────────────────────────────
    public const YEARS = 7;              // 2027 - 2033
    public const FX = 54.0;              // EGP per USD (bank quotes 21-Oct-2026: about 54.4)
    public const LOCAL_GROWTH = 8;       // % a year, local prices
    public const EXPORT_GROWTH = 6;      // % a year, export prices (USD flat + gradual EGP depreciation)
    public const COST_INFLATION = 9;     // % a year, raw material cost per ton (used to turn cost into % of the price)
    public const SALARY_FACTOR = 1.25;   // salaries are the first estimate + 25% (client's instruction)
    public const SALARY_INCREASE = 12;   // % a year
    public const SALARY_TAX = 8;         // % income tax on salaries
    public const SOCIAL_INSURANCE = 20;  // % blended employee + employer share
    public const TAX_RATE = 22.5;
    public const RETURN_RATE = 25;       // required return on equity: CBE rates about 19-20% + about 5 points
    public const GROWTH_RATE = 3;        // perpetual growth rate

    /**
     * The three products. capacity = tons a year (one shared line of about 125 tons a month, in total 1,500 tons of powder).
     * ramp = share of the capacity sold in each of the 7 years; the first year is multiplied by (months selling / 12).
     * usd_kg = price in USD per kg for the first year. rm_kg = cost of the 5 raw materials, EGP per kg of product (2027).
     */
    private const PRODUCTS = [
        'bio' => [
            'name' => 'SKY-CAL Bio-Active', 'selling_start' => '2027-07', 'first_year_months' => 6,
            'capacity' => 600, 'ramp' => [25, 50, 70, 80, 85, 85, 85], 'local' => 25,
            'local_usd_kg' => 0.70, 'export_usd_kg' => 0.80, 'coverage' => 30,
            'rm_kg' => [2.6, 0.7, 0.2, 0.6, 1.6],
            'local_terms' => [20, 40, 40, 30, 60], 'export_terms_first' => [20, 50, 30, 30, 60], 'export_terms_next' => [30, 50, 20, 30, 60],
        ],
        'industrial' => [
            'name' => 'SKY-CAL Industrial/Feed', 'selling_start' => '2027-07', 'first_year_months' => 6,
            'capacity' => 900, 'ramp' => [35, 60, 75, 85, 90, 90, 90], 'local' => 50,
            'local_usd_kg' => 0.28, 'export_usd_kg' => 0.33, 'coverage' => 15,
            'rm_kg' => [2.6, 0.7, 0.1, 0.3, 0.8],
            'local_terms' => [20, 40, 40, 30, 60], 'export_terms_first' => [20, 50, 30, 30, 60], 'export_terms_next' => [30, 50, 20, 30, 60],
        ],
        'membrane' => [
            'name' => 'Eggshell Membrane Powder', 'selling_start' => '2027-10', 'first_year_months' => 3,
            'capacity' => 48, 'ramp' => [15, 30, 45, 55, 60, 60, 60], 'local' => 40,
            'local_usd_kg' => 10.0, 'export_usd_kg' => 12.0, 'coverage' => 30,
            'rm_kg' => [10.0, 5.0, 30.0, 8.0, 12.0],
            'local_terms' => [20, 40, 40, 30, 60], 'export_terms_first' => [20, 50, 30, 30, 60], 'export_terms_next' => [30, 50, 20, 30, 60],
        ],
    ];

    /** Raw materials, in the order of the rm_kg lists above: name, unit, stock days, VAT %, payment terms [cash %, share 1 %, share 2 %, days 1, days 2]. */
    private const RAW_MATERIALS = [
        ['Eggshells (own plant)', 'ton', 0, 0, [0, 100, 0, 30, 60]],
        ['Water and wash chemicals', 'kg', 15, 14, [50, 50, 0, 30, 60]],
        ['Enzymes and process aids', 'kg', 60, 14, [20, 40, 40, 30, 60]],
        ['Consumables and QC reagents', 'piece', 45, 14, [20, 80, 0, 30, 60]],
        ['Packaging materials', 'piece', 45, 14, [0, 60, 40, 30, 60]],
    ];

    /**
     * Staff: type, position, first estimate of the net monthly salary (EGP; multiplied by SALARY_FACTOR), hirings (month position => people).
     * Month position 0 = January 2027, so 6 = July 2027 (operation start) and 18 = July 2028.
     */
    private const POSITIONS = [
        ['direct_labor', 'Process operators (washing, peeling, dewatering)', 8500, [6 => 8, 18 => 2]],
        ['direct_labor', 'Drying, milling and micronization operators', 11000, [6 => 3, 18 => 1]],
        ['direct_labor', 'Packing and warehouse workers', 7500, [6 => 3, 18 => 1]],
        ['manufacturing_overheads', 'QC and laboratory analysts', 15000, [6 => 2, 18 => 1]],
        ['manufacturing_overheads', 'Maintenance technicians (mechanical / electrical)', 14000, [6 => 2]],
        ['manufacturing_overheads', 'Plant manager', 50000, [6 => 1]],
        ['operational_salaries', 'Shift supervisors and HSE / dust-safety officer', 18000, [6 => 2]],
        ['sales', 'Sales executives (B2B and export)', 22000, [6 => 1, 18 => 1]],
        ['sales', 'Sales and export manager', 45000, [6 => 1]],
        ['marketing', 'Technical marketing and regulatory specialist', 30000, [6 => 1]],
        ['general', 'Business unit manager (eggshell products)', 45000, [6 => 1]],
        ['general', 'Accounting and administration staff', 16000, [6 => 1, 18 => 1]],
        ['general', 'Logistics, procurement and HR assistant', 12000, [6 => 1, 18 => 1]],
    ];

    /** Share of the direct labour cost taken by each product, % (the other staff cost follows the revenue). */
    private const DIRECT_LABOUR_SHARE = ['bio' => 45, 'industrial' => 45, 'membrane' => 10];

    public function run(): void
    {
        $this->call(BusinessSectorSeeder::class);

        $this->user = $this->owner();

        if (Project::query()->where('company_id', $this->user->company_id)->where('name', self::STUDY)->exists()) {
            $this->command?->warn('Study "'.self::STUDY.'" already exists - left as it is.');

            return;
        }

        $project = $this->build(self::STUDY);
        app(SalesCalculationService::class)->recalculate($project->refresh());

        $this->command?->info('Study "'.self::STUDY.'" is ready (company '.self::COMPANY.', login '.$this->user->email.').');
    }

    /** The company "Sky Healthy Food" and its administrator: found if they exist, created if not. */
    private function owner(): User
    {
        $company = Company::query()->firstOrCreate(['name_en' => self::COMPANY], [
            'name_ar'                => 'سكاي هيلثي فود',
            'status'                 => 'active',
            'users_limit'            => 10,
            'subscription_starts_at' => today()->subMonth(),
            'subscription_ends_at'   => today()->addYears(3),
            'contact_email'          => self::EMAIL,
            'notes'                  => 'Created by SkyHealthySeeder.',
        ]);

        $user = User::query()->where('email', self::EMAIL)->first();
        if ($user === null) {
            $user = User::query()->forceCreate([
                'company_id'        => $company->id,
                'name'              => 'Sky Healthy Food Admin',
                'email'             => self::EMAIL,
                'password'          => self::PASSWORD,
                'role'              => User::ROLE_COMPANY_ADMIN,
                'email_verified_at' => now(),
                'language'          => 'en',
            ]);
            $this->command?->info('Company: '.self::COMPANY.'   login: '.self::EMAIL.'  /  '.self::PASSWORD);
        }

        return $user;
    }

    // ══════════════════════════════════════════════════════════════════
    //  The study
    // ══════════════════════════════════════════════════════════════════

    private function build(string $name): Project
    {
        $project = app(ProjectService::class)->create($this->user, $name);
        $sector = BusinessSector::query()->where('name_en', 'Food & Beverage')->value('id');

        $products = [];
        foreach (self::PRODUCTS as $def) {
            $products[] = ['name' => $def['name'], 'measurement_unit' => 'ton', 'selling_start_date' => $def['selling_start'], 'vat_rate' => 14, 'withhold_tax_rate' => 1];
        }
        $materials = [];
        foreach (self::RAW_MATERIALS as [$materialName, $unit, $days, $vat]) {
            $materials[] = ['name' => $materialName, 'rm_inventory_coverage_days' => $days, 'measurement_unit' => $unit, 'vat_rate' => $vat, 'withhold_tax_rate' => 0];
        }

        $project = $this->saveStudy($project, [
            'name' => $name, 'new_company' => true, 'main_functional_currency' => 'egp', 'business_sector_id' => $sector,
            'intro_paragraph' => $this->intro(),
            'start_date' => '2027-01', 'duration' => self::YEARS, 'operation_start_date' => '2027-07',
            'tax_rate' => self::TAX_RATE, 'return_rate' => self::RETURN_RATE, 'perpetual_growth_rate' => self::GROWTH_RATE,
            'products' => $products, 'rawMaterials' => $materials,
        ]);

        $p = $this->idsByName($project->products());
        $m = $this->idsByName($project->rawMaterials());
        $bio = $p['SKY-CAL Bio-Active'];
        $industrial = $p['SKY-CAL Industrial/Feed'];
        $membrane = $p['Eggshell Membrane Powder'];
        $materialIds = array_values($m);   // same order as RAW_MATERIALS

        $comments = $this->productComments();
        foreach (self::PRODUCTS as $key => $def) {
            $this->saveProduct($project, $p[$def['name']], $def, $materialIds, $comments[$key]);
        }

        $payments = [];
        foreach (self::RAW_MATERIALS as $i => $line) {
            $payments[$materialIds[$i]] = $this->terms($line[4], $line[4]);
        }
        $this->saveRawPayments($project, $payments, $this->rawMaterialsComment());

        $this->saveManpower($project, [
            'direct_labor' => ['by_revenue' => false, 'allocations' => [
                $bio => self::DIRECT_LABOUR_SHARE['bio'], $industrial => self::DIRECT_LABOUR_SHARE['industrial'], $membrane => self::DIRECT_LABOUR_SHARE['membrane'],
            ]],
        ]);

        $all = array_values($p);
        $this->saveExpenses($project, $this->expenses($all, [$bio, $industrial]));
        $this->saveAssets($project, $this->assets($bio, $industrial, $membrane));

        $this->extras($project);

        return $project;
    }

    // ══════════════════════════════════════════════════════════════════
    //  The figures (kept in small methods so a figure is easy to find and change)
    // ══════════════════════════════════════════════════════════════════

    /** Expenses. Month text "2027-07" = July 2027. The last study month is December 2033. */
    private function expenses(array $all, array $powders): array
    {
        $end = '2033-12';

        return [
            // before operation
            $this->expense('pre-operating-expenses', 'one_time_expense', 'Licences, permits and certifications', ['amount' => 3000000, 'start' => '2027-01', 'amortization_months' => 36]),
            $this->expense('pre-operating-expenses', 'one_time_expense', 'Recruitment, training and commissioning (incl. about 2 months of pre-start payroll)', ['amount' => 3000000, 'start' => '2027-05', 'amortization_months' => 24]),
            $this->expense('start-up-expenses', 'one_time_expense', 'Product development and market launch', ['amount' => 3000000, 'start' => '2027-07', 'amortization_months' => 12]),
            // manufacturing
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Electricity, gas and water', ['amount' => 300000, 'start' => '2027-07', 'end' => $end, 'increase_rate' => 10]),
            $this->expense('manufacturing-expenses', 'expense_as_percentage', 'Maintenance and spare parts (routine; renewals are in the fixed assets)', ['monthly_percentage' => 2.0, 'start' => '2027-07', 'end' => $end, 'products' => $all]),
            $this->expense('other-operation-expenses', 'fixed_monthly_repeating_amount', 'Laboratory testing and audits', ['amount' => 60000, 'start' => '2027-07', 'end' => $end, 'increase_rate' => 10, 'payment_terms' => 'quarterly']),
            // selling (a cost per ton cannot grow yearly in the application, so EGP 1,800 is the 2027 cost of EGP 1,500 plus the average inflation of the 7 years)
            $this->expense('sales', 'cost_per_unit', 'Delivery cost per ton (powders)', ['monthly_cost_of_unit' => 1800, 'start' => '2027-07', 'end' => $end, 'products' => $powders]),
            $this->expense('sales', 'expense_as_percentage', 'Sales commissions and export agents', ['monthly_percentage' => 2.5, 'start' => '2027-07', 'end' => $end, 'products' => $all]),
            $this->expense('marketing', 'expense_as_percentage', 'Marketing, samples and trade fairs', ['monthly_percentage' => 2.5, 'start' => '2027-07', 'end' => $end, 'products' => $all]),
            // general
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Premises rent and services (inside the existing Sky site)', ['amount' => 40000, 'start' => '2027-07', 'end' => $end, 'increase_rate' => 10, 'payment_terms' => 'quarterly']),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Insurance (plant and stock)', ['amount' => 35000, 'start' => '2027-01', 'end' => $end, 'increase_rate' => 10, 'payment_terms' => 'annually']),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Audit, legal, environmental and consulting', ['amount' => 50000, 'start' => '2027-01', 'end' => $end, 'increase_rate' => 10]),
        ];
    }

    /**
     * Fixed assets. Installation Jan-Jun 2027. "replacement_cost_rate" is the yearly renewal capex as a % of the gross value,
     * from one year after the operation start (replacement every 1 year). No second production line is planned.
     */
    private function assets(int $bio, int $industrial, int $membrane): array
    {
        $lineShare = fn () => ['is_as_revenue_percentages' => false, 'allocations' => [
            $bio => self::DIRECT_LABOUR_SHARE['bio'], $industrial => self::DIRECT_LABOUR_SHARE['industrial'], $membrane => self::DIRECT_LABOUR_SHARE['membrane'],
        ]];
        $loan = ['equity_funding_rate' => 50, 'interest_rate' => 22, 'grace_period' => 6, 'tenor' => 60, 'installment_interval' => 'quarterly'];

        return [
            $this->asset('Building fit-out and utilities', 1, 10000000, 15, '2027-01', '2027-06', ['admin_depreciation_percentage' => 20, 'replacement_cost_rate' => 0.5]
                + ['equity_funding_rate' => 60, 'interest_rate' => 22, 'grace_period' => 6, 'tenor' => 60, 'installment_interval' => 'quarterly']),
            $this->asset('Washing and membrane-separation line', 1, 15000000, 10, '2027-02', '2027-06', ['replacement_cost_rate' => 2] + $loan + $lineShare()),
            $this->asset('Drying, milling and micronization line', 1, 24000000, 10, '2027-01', '2027-06', ['replacement_cost_rate' => 3] + $loan + $lineShare()),
            $this->asset('Packaging, dust protection and effluent', 1, 8000000, 10, '2027-03', '2027-06', ['replacement_cost_rate' => 2] + $lineShare()),
            $this->asset('Forklifts and vehicles', 3, 1200000, 5, '2027-06', '2027-06', ['replacement_cost_rate' => 10]),
            $this->asset('Laboratory and office equipment', 1, 5000000, 5, '2027-04', '2027-06', ['admin_depreciation_percentage' => 30, 'replacement_cost_rate' => 8]),
        ];
    }

    // ── pure calculations (no database; tested on their own) ─────────────────────────────────

    /** Prices year by year, the way the application does it: each year = the year before x (1 + growth), rounded to a whole number. */
    public static function priceChain(float $first, float $growthPercent): array
    {
        $prices = [0 => (float) round($first)];
        for ($year = 1; $year < self::YEARS; $year++) {
            $prices[$year] = (float) round($prices[$year - 1] * (1 + $growthPercent / 100));
        }

        return $prices;
    }

    /** Raw material cost as a % of the average selling price, year by year (the application prices the recipe on the selling price). */
    public static function recipePercentages(float $costPerTon, array $localPrices, array $exportPrices, float $localShare): array
    {
        $percentages = [];
        for ($year = 0; $year < self::YEARS; $year++) {
            $average = $localShare / 100 * $localPrices[$year] + (1 - $localShare / 100) * $exportPrices[$year];
            $percentages[$year] = $average > 0 ? round(100 * $costPerTon * ((1 + self::COST_INFLATION / 100) ** $year) / $average, 2) : 0.0;
        }

        return $percentages;
    }

    /** Share of the capacity sold: the typed ramp, with the part-year of the first year taken into account. */
    public static function targets(array $ramp, int $firstYearMonths): array
    {
        $ramp[0] = round($ramp[0] * $firstYearMonths / 12, 2);

        return array_values($ramp);
    }

    /** Net monthly salary: the first estimate + 25%, rounded to the nearest 100. */
    public static function salary(int $firstEstimate): int
    {
        return (int) (round($firstEstimate * self::SALARY_FACTOR / 100) * 100);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Saving each form (the form request runs first, then the same service the screen uses)
    // ══════════════════════════════════════════════════════════════════

    private function saveStudy(Project $project, array $data): Project
    {
        return app(ProjectService::class)->save($project, $this->validated(UpdateProjectRequest::class, $data, $project));
    }

    /** @param array<string,mixed> $def one entry of PRODUCTS @param list<int> $materialIds @param string $comment the comment shown under the product's sales */
    private function saveProduct(Project $project, int $productId, array $def, array $materialIds, string $comment): void
    {
        $years = range(0, self::YEARS - 1);
        $localPrices = self::priceChain($def['local_usd_kg'] * self::FX * 1000, self::LOCAL_GROWTH);
        $exportPrices = self::priceChain($def['export_usd_kg'] * self::FX * 1000, self::EXPORT_GROWTH);

        $recipe = [];
        foreach ($def['rm_kg'] as $i => $costPerKg) {
            $recipe[] = [
                'raw_material_id' => $materialIds[$i],
                'percentages'     => self::recipePercentages($costPerKg * 1000, $localPrices, $exportPrices, $def['local']),
            ];
        }

        $data = [
            'max_capacity'                      => array_fill(0, self::YEARS, $def['capacity']),
            'target_percentages'                => self::targets($def['ramp'], $def['first_year_months']),
            'local_target_quantity_percentages' => array_fill(0, self::YEARS, $def['local']),
            'local_growth_rates'                => array_map(fn ($y) => $y === 0 ? 0 : self::LOCAL_GROWTH, $years),
            'local_price_per_unit'              => $localPrices,
            'export_growth_rates'               => array_map(fn ($y) => $y === 0 ? 0 : self::EXPORT_GROWTH, $years),
            'export_price_per_unit'             => $exportPrices,
            'seasonality'                       => ['type' => 'flat'],
            'fg_inventory_coverage_days'        => $def['coverage'],
            'comment_for_sales'                 => $comment,
            'collection_policy_value'           => [
                'local'  => $this->terms($def['local_terms'], $def['local_terms']),
                'export' => $this->terms($def['export_terms_first'], $def['export_terms_next']),
            ],
            'rawMaterials'                      => array_map(fn ($line) => ['raw_material_id' => $line['raw_material_id'], 'percentages' => $line['percentages']], $recipe),
            'after'                             => 'stay',
        ];

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

    /** @param array<string,array{by_revenue:bool,allocations:array<int,int|float>}> $typed shares per type (the others follow the revenue) */
    private function saveManpower(Project $project, array $typed): void
    {
        $rows = [];
        foreach (self::POSITIONS as [$type, $position, $firstEstimate, $hirings]) {
            $rows[] = ['type' => $type, 'position' => $position, 'avg_salary' => self::salary($firstEstimate), 'existing_count' => 0, 'hirings' => $hirings];
        }

        $data = [
            'salaries_annual_increase' => self::SALARY_INCREASE, 'salaries_tax_rate' => self::SALARY_TAX, 'social_insurance_rate' => self::SOCIAL_INSURANCE,
            'rows' => $rows, 'by_revenue' => [], 'allocations' => [], 'after' => 'stay',
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

    /** What-if settings (a downside case) and the two notes under the dashboard. No public link is created. */
    private function extras(Project $project): void
    {
        // Slots follow the order the products were added: first = Bio-Active, second = Industrial/Feed, third = Membrane powder.
        app(SensitivityService::class)->save($project, $this->user, [
            ['slot' => 'first', 'target' => -15, 'rm' => 5, 'days' => 15],
            ['slot' => 'second', 'target' => -15, 'rm' => 5, 'days' => 15],
            ['slot' => 'third', 'target' => -50, 'rm' => 10, 'days' => 30],
        ]);

        $project->update(['dashboard_comment_1' => $this->dashboardNoteOne(), 'dashboard_comment_2' => $this->dashboardNoteTwo()]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  The words of the study (what a reader sees on the screens and in the printed report)
    // ══════════════════════════════════════════════════════════════════

    private function intro(): string
    {
        return 'Sky Healthy Food runs a pasteurized-egg factory in 6th of October City, Giza, so it already has the main raw material for this project: eggshell, which is about 93-95% natural calcium carbonate. '
            .'The study looks at a new SKY-CAL plant that washes, separates the membrane, dries and micronizes the shell (D90 about 18 micron) into two calcium carbonate grades plus a membrane powder co-product, '
            .'on a shared line of about 100-150 tons of powder a month (process flow by Dr. Mahmoud Emara, June 2026). '
            .'Equipment is ordered, delivered, installed and tested between January and June 2027; operation and the first sales start in July 2027 (membrane powder in October 2027). '
            .'The study covers seven years (2027-2033), is in Egyptian pounds, and treats the plant as a new business inside Sky. '
            .'Sources: client documents (process flow, product data sheet, safety data sheet, progress report January 2026, Egyptian customs imports January-August 2026), market data (USD/EGP about 54 on 21 October 2026; Central Bank of Egypt lending rate 20%) '
            .'and expert estimates where no quotation exists yet. Equipment cost, staffing, utilities, selling prices and the membrane price are estimates to be replaced by quotations and buyer feedback.';
    }

    /** @return array<string,string> */
    private function productComments(): array
    {
        return [
            'bio' => 'Premium food, supplement and cosmetic grade (product data sheet: CaCO3 93%, D90 about 18 micron, moisture 2.6%, egg protein 4.6% so an egg-allergen label is needed). '
                .'Price: USD 700 a ton locally and USD 800 a ton for export in 2027, inside the USD 600-1,000 export benchmark of the January 2026 progress report; Egypt imported food-grade calcium carbonate at about USD 690 a ton and medical grade at about USD 1,065 a ton (customs data January-August 2026). '
                .'Egypt imports only about 700 tons a year of food and medical grade in total, so only 25% is planned for the local market and exports must carry the rest. '
                .'Capacity is 600 tons a year of the shared line in every year; the 2027 share (12.5%) is the first-year ramp of 25% over the six months of selling. Exporters such as ACMA treat 150-200 tons a month as a small scale, so the planned volumes are small for such buyers.',
            'industrial' => 'Coarser grade (hammer-mill only, no jet-mill) for ceramics, plastics, paints, feed and fertiliser. Price USD 280 a ton locally and USD 330 for export in 2027: Egypt imported "other" calcium carbonate at about USD 246 a ton (customs data), and a premium is assumed for natural, upcycled origin. '
                .'The LIFE EGGSHELLENCE industrial trials replaced 50-60% of mineral calcite in wall tiles with 76-90% first-quality tiles; micronized material is needed for large tile formats. '
                .'Capacity is 900 tons a year of the shared line (together with Bio-Active 1,500 tons, about 125 tons a month). Without this grade much of the line would stand idle, so it fills capacity at low extra cost, but it only pays if the volumes are actually sold.',
            'membrane' => 'Eggshell membrane powder, a co-product separated before drying (process flow stage 04; about 3-5 tons a month; protein 60-70%) for supplements, cosmetics, wound care and feed. '
                .'This is the most uncertain price in the study: online listings range from about USD 3.5 a kg (crude) to USD 40-100 a kg (supplement grade). USD 10 a kg local and USD 12 a kg export is a mid-range estimate. '
                .'Selling starts three months after the main products (October 2027) because buyers must qualify the material first. Capacity 48 tons a year; the 2027 share is the first-year ramp of 15% over three months. '
                .'The What-if screen shows what happens if membrane sales are halved: it is the product that decides whether the project clears the required return.',
        ];
    }

    private function rawMaterialsComment(): string
    {
        return 'Costs are entered as a % of the selling price (cost per kg of product in 2027, rising 9% a year, divided by the average selling price of each year). '
            .'Eggshells come from the Sky pasteurization plant at an internal price of EGP 2 a kg of wet shell, 1.28 kg of shell per kg of powder (about 78% yield after 13% moisture, washing and membrane loss), and are settled at 30 days. '
            .'The shell is processed the day it arrives, so no stock is held (0 days). Water uses 12-15 litres a kg (process flow). Enzymes and process aids are imported (60 days of stock). '
            .'Packaging is 25 kg PE-lined bags and 500 kg bulk bags for the powders, small foil packs for the membrane powder. All cost levels are expert estimates.';
    }

    private function dashboardNoteOne(): string
    {
        return 'How to read this study. It is an initial feasibility study with seven years of monthly figures. The base case is deliberately realistic: six months from the start of the study to the first sales, '
            .'full capacity entered in every year (the ramp-up is in the share of capacity sold), salaries 25% above the first estimate, yearly renewal capex on every asset instead of one big replacement, and no second production line. '
            .'Money figures are in EGP. Because the plant is heavy on equipment, a large part of the value (the terminal value) comes from the years after 2033, so look at the payback and at the share of the terminal value in the valuation tiles as well as at the NPV.';
    }

    private function dashboardNoteTwo(): string
    {
        return 'What decides the result, and what to confirm next. In the first review, a 5-year Excel version, the project was close to break-even, so small changes decided the sign of the NPV. The three drivers are: '
            .'(1) the price and volume Sky can really get for membrane powder; (2) firm equipment quotations (the equipment cost is an estimate including about 10% contingency); (3) how much premium Bio-Active powder export customers will take. '
            .'Also to confirm: the real tons of eggshell per day from the pasteurization plant and what Sky earns or pays for the shell today; which product is sold (with membrane protein and an egg-allergen label, or without); moisture target (process flow below 1%, data sheet up to 3%, exporters ask 0.2%); '
            .'and the dust-explosion protection (minimum ignition energy test, ASTM E2019). The What-if screen holds a saved downside case (sales -15% on the powders and -50% on membrane powder, higher raw material cost, slower collections).';
    }

    // ══════════════════════════════════════════════════════════════════
    //  Small helpers (the same as the demo seeder)
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
        $request = $class::create('/sky-healthy', 'PUT', $data);
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
            throw new \RuntimeException('Sky Healthy Food data was refused by '.class_basename($class).': '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
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
