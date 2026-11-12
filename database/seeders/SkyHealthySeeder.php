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
//  ZAVERO Manufacturing Pro — Sky Healthy Food: SKY-CAL eggshell calcium plant, 7 products (version 2, 11 Nov 2026)
//  Location: database/seeders/SkyHealthySeeder.php
//  Run:  php artisan db:seed --class=SkyHealthySeeder
//
//  Creates ONE study (only if it is not there yet, so it can be run again without making copies):
//    "Sky Healthy Food - SKY-CAL Eggshell Calcium Plant (7 products)"
//    a NEW company, 7 years from 2027-01 (installation during 2027, operation from 2028-01), EGP.
//  Version 3 (12 Nov 2026): management assumptions - share of capacity sold 25/50/60/70/80/85% for 2028-2033 (25% in 2028 because the first year is a part year) (same for every product),
//  local and export prices both +15% a year from 2029, one export price per product (the highest), exchange rates fixed at USD 56 / EUR 60.
//  The earlier versions of the Sky Health study (3-product, 7-product version 2, and a version 3 made before) are deleted when this one is made, so only ONE Sky Health study is left.
//  Version 3 follows the Excel workbook SKY-CAL_Feasibility_V4.xlsx; the raw-material % of price and the fixed-asset amounts are its figures at USD 56.
//  (Earlier versions: the Excel workbook SKY-CAL_Feasibility_V2.xlsx (sheets Study_General, Products, Sales_Plan, FX_Forecast,
//  Raw_Materials, App_Recipe_Entry, Manpower, Expenses, Fixed_Assets).
//  The old 3-product study of the same company (if it is in the database) is deleted, so only this study is left.
//  The company "Sky Healthy Food" and its administrator are created first (only if missing). Login: admin@sky.com  /  Sky@Zavero2026.
//  The demo company is never used or changed.
//
//  Every figure goes through the very same checks and services as the screens (the form requests are run on the data
//  first), so if a number were not accepted by a screen, this seeder stops and says which form and which field.
//
//  Quantities are in TONS, prices in EGP per ton (the application rounds prices to whole numbers).
// ══════════════════════════════════════════════════════════════════

class SkyHealthySeeder extends Seeder
{
    private User $user;

    /** The ids of the channels and markets of the study (set once the study is saved). @var array{channels:array<string,int>,markets:array<string,int>}|null */
    private ?array $plan = null;

    // ── the company and its administrator (created if missing) ───────────────────────────────
    public const COMPANY = 'Sky Healthy Food';
    public const EMAIL = 'admin@sky.com';
    public const PASSWORD = 'Sky@Zavero2026';

    public const STUDY = 'Sky Healthy Food - SKY-CAL Eggshell Calcium Plant (7 products, version 3)';

    /** The name of the first (3-product) version. If it is found, it is deleted and replaced by the new study. */
    public const LEGACY_STUDY = 'Sky Healthy Food - SKY-CAL Eggshell Calcium Carbonate Plant (Initial Feasibility)';

    /** The earlier 7-product version (version 2). It is deleted when version 3 is made, so the company keeps ONE Sky Health study. */
    public const VERSION_2_STUDY = 'Sky Healthy Food - SKY-CAL Eggshell Calcium Plant (7 products)';

    // ── general assumptions (Excel: Study_General) ───────────────────────────────────────────
    public const YEARS = 7;                 // 2027 - 2033
    public const FX = 56;                   // EGP per USD, FIXED in every year (management decision: no forecast without a credible source). Also used for the local prices.
    public const FX_EUR = 60;               // EGP per EUR, FIXED in every year
    public const LOCAL_GROWTH = 15;         // % a year, local prices (management decision)
    public const EXPORT_GROWTH = 15;        // % a year, export prices in USD / EUR (management decision: the same as the local growth)
    public const GROWTH_START_INDEX = 2;    // price growth starts in the second selling year, 2029 (study year index 0 = 2027); 2028 is the base price
    /** Share of the maximum capacity sold, 2027 ... 2033 (management decision, the same for every product; 2027 is investment only). */
    public const TARGET_RAMP = [0, 25, 50, 60, 70, 80, 85];
    public const TARGET_AFTER_2033 = 90;    // % from 2034 if the study is ever extended (the study itself ends in 2033)
    public const EXPORT_START_INDEX = 2;    // export starts in 2029 (study year index 0 = 2027)
    public const SALARY_INCREASE = 12;      // % a year
    public const SALARY_TAX = 20;           // % income tax on salaries (management: average 20%)
    public const SOCIAL_INSURANCE = 15;     // % social insurance (management: average 15%); net salary = gross x (1 - 20% - 15%)
    public const TAX_RATE = 22.5;
    public const RETURN_RATE = 25;          // required return on equity
    public const GROWTH_RATE = 3;           // perpetual growth rate
    public const FIXED_COST_GROWTH = 10;    // % a year on fixed monthly expenses

    /**
     * The seven products, in the order of the Excel workbook (the What-if screen covers the first five, so the membrane powder is the fifth).
     * capacity = tons a year of the shared line (the share of it sold each year is TARGET_RAMP, the same for every product);
     * local = share of the quantity sold locally once export is active (%); usd_ton = price in USD a ton (the same in every market);
     * rm = each raw material as a % of the selling price, in the order of RAW_MATERIALS (constant over the years: Excel App_Recipe_Entry).
     */
    private const PRODUCTS = [
        'food' => [
            'name' => 'SKY-CAL Food Ingredient Grade', 'selling_start' => '2028-04', 'first_year_months' => 9,
            'capacity' => 400, 'local' => 70,
            'usd_ton' => 450, 'coverage' => 30,
            'rm' => [0, 0.6928, 2.3095, 0, 3.9683, 0, 0.9921, 2.1825, 1.9841, 0],
            'comment' => 'Food-fortification grade (bakery, dairy alternatives, cereals; CaCO3 95% or more, D90 18 micron or less, egg-allergen declaration). Price: USD 450 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. Price basis: the Q3 2026 China FOB benchmark for food-grade calcium carbonate is about USD 285 a ton, and an Egyptian supplier lists USD 400-900 a ton for food and pharma grade. Capacity 400 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 70% from 2029 (100% in 2028, before export starts).',
        ],
        'supplement' => [
            'name' => 'SKY-CAL Supplement Active Grade', 'selling_start' => '2028-07', 'first_year_months' => 6,
            'capacity' => 300, 'local' => 60,
            'usd_ton' => 550, 'coverage' => 30,
            'rm' => [0, 0.5669, 1.8896, 0, 3.2468, 0, 0.8117, 1.7857, 2.4351, 0],
            'comment' => 'Supplement active grade for calcium tablets, capsules and contract supplement manufacturers. Price: USD 550 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. Price basis: a USP-grade calcium carbonate reference of about USD 689 a ton (IMARC, Q2 2024) against Made-in-China listings of USD 199-409 a ton; small-pack laboratory prices are not a bulk benchmark. Capacity 300 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 60% from 2029 (100% in 2028, before export starts).',
        ],
        'oral' => [
            'name' => 'SKY-CAL Oral Care Abrasive Grade', 'selling_start' => '2028-01', 'first_year_months' => 12,
            'capacity' => 400, 'local' => 50,
            'usd_ton' => 200, 'coverage' => 30,
            'rm' => [0, 1.5589, 5.1964, 0, 4.4643, 5.55, 2.2321, 4.9107, 3.5714, 0],
            'comment' => 'Oral-care abrasive grade for toothpaste and tooth-powder manufacturers (narrow particle-size cut). Price: USD 200 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. Price basis: Made-in-China toothpaste-grade listings of USD 165-195 a ton with a 10-ton minimum order. Buyers (toothpaste makers) will run qualification trials first, so this grade is the first to sell (January 2028). Capacity 400 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 50% from 2029 (100% in 2028, before export starts).',
        ],
        'cosmetic' => [
            'name' => 'SKY-CAL Cosmetic Grade', 'selling_start' => '2028-07', 'first_year_months' => 6,
            'capacity' => 100, 'local' => 40,
            'usd_ton' => 400, 'coverage' => 45,
            'rm' => [0, 0.7795, 2.5982, 0, 4.4643, 0, 1.1161, 2.4554, 4.4643, 0],
            'comment' => 'Cosmetic grade for powders, scrubs, creams and colour cosmetics. Price: USD 400 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. No cosmetic-specific price was found: the USP-grade price is used with no premium. Cosmetic buyers need the finest cut (D90 18 micron or less), ISO 22716 support documents and an EU cosmetic dossier. Capacity 100 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 40% from 2029 (100% in 2028, before export starts).',
        ],
        'membrane' => [
            'name' => 'SKY-CAL Eggshell Membrane Powder', 'selling_start' => '2028-04', 'first_year_months' => 9,
            'capacity' => 35, 'local' => 30,
            'usd_ton' => 10500, 'coverage' => 30,
            'rm' => [0, 0.0255, 0, 0, 0, 0, 0.0425, 0.0187, 0.8503, 1.9048],
            'comment' => 'Eggshell membrane powder (protein 60-70%), a co-product separated before drying, for joint-health supplements, sports nutrition, cosmetics and feed. Price: USD 10,500 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. Price basis: supplier listings of USD 9.30 a kg (500 kg or more) and USD 11.20 a kg (100-499 kg), and USD 12-30 a kg on other listings; USD 10.50 a kg is used. Together with collagen this is the most uncertain price of the study. The What-if screen halves its sales. Capacity 35 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 30% from 2029 (100% in 2028, before export starts).',
        ],
        'nutra' => [
            'name' => 'SKY-CAL Nutraceutical Raw Material', 'selling_start' => '2028-07', 'first_year_months' => 6,
            'capacity' => 300, 'local' => 50,
            'usd_ton' => 650, 'coverage' => 30,
            'rm' => [0, 0.4797, 1.5989, 0, 2.7473, 0, 0.6868, 1.511, 2.4725, 0],
            'comment' => 'Nutraceutical raw material: the supplement grade with full traceability and a premium documentation pack. Price: USD 650 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. No nutraceutical-specific price was found: the same USP-grade price as the supplement grade is used with no premium. Buyers want full traceability, third-party contaminant tests and certificates (organic, non-GMO, Halal where required). Capacity 300 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 50% from 2029 (100% in 2028, before export starts).',
        ],
        'collagen' => [
            'name' => 'SKY-CAL Collagen Hydrolysate (Eggshell Membrane)', 'selling_start' => '2028-07', 'first_year_months' => 6,
            'capacity' => 13, 'local' => 30,
            'usd_ton' => 25000, 'coverage' => 30,
            'rm' => [0, 0.0214, 0, 2.6667, 0, 0, 0.0179, 0.0039, 0.5357, 0.8],
            'comment' => 'Collagen hydrolysate made from the eggshell membrane (route R2: enzymatic hydrolysis, filtration, spray drying) for cosmetic and nutraceutical peptide ingredients. Price: USD 25,000 a ton in every market; the local price is converted at the fixed rate of EGP 56 a dollar and grows 15% a year from 2029; the export price also grows 15% a year from 2029 and is the same in every export market (the highest price, USD basis), converted at the fixed rates of EGP 56 a dollar and EGP 60 a euro. Price basis: a hydrolysed eggshell membrane peptide listing at USD 20 a kg (protein above 90%); other listings ask USD 40-105 a kg but state a different collagen content and are not directly comparable. USD 25 a kg is used. Capacity is only 13 tons a year and needs the membrane stream (the Excel check shows membrane supply of about 58 tons a year). Capacity 13 tons a year in every year; share of capacity sold 25, 50, 60, 70, 80, 85% for 2028-2033 (the same for every product; 90% from 2034 if the study is extended). Local share of the quantity 30% from 2029 (100% in 2028, before export starts).',
        ],
    ];

    /** Raw materials: name, unit, stock days, VAT %, payment terms [cash %, share 1 %, share 2 %, days 1, days 2]. */
    private const RAW_MATERIALS = [
        ['Eggshells (own plant)', 'ton', 0, 0, [0, 100, 0, 30, 60]],
        ['Process water', 'others', 0, 14, [0, 100, 0, 30, 60]],
        ['Wash & sanitation chemicals (per wet ton of shell)', 'ton', 30, 14, [0, 100, 0, 30, 60]],
        ['Protease enzyme (Alcalase / Pronase type)', 'kg', 60, 14, [20, 80, 0, 30, 60]],
        ['PP bag 25 kg with PE liner (food-contact)', 'piece', 45, 14, [0, 100, 0, 30, 60]],
        ['FIBC 500 kg bulk bag with liner', 'piece', 45, 14, [20, 80, 0, 30, 60]],
        ['Pallet, stretch wrap & labels (per finished ton)', 'ton', 30, 14, [0, 100, 0, 30, 60]],
        ['Filter bags & mill wear parts (per finished ton)', 'ton', 90, 14, [0, 100, 0, 30, 60]],
        ['External lab testing (COA package)', 'others', 0, 14, [0, 100, 0, 15, 60]],
        ['Membrane/hydrolysate packaging (20 kg foil-lined pouch or drum)', 'piece', 45, 14, [20, 80, 0, 30, 60]],
    ];

    /** Collection terms of every product: [cash %, share 1 %, share 2 %, days 1, days 2]. */
    private const LOCAL_TERMS = [20, 40, 40, 30, 60];

    private const EXPORT_TERMS = [30, 50, 20, 30, 60];

    /**
     * Staff: type, position, NET monthly salary (EGP; the application adds 20% tax and 15% insurance, so the employer cost equals the gross
     * salary of the client file), hirings (month position => people). Month position 0 = January 2027, so 12 = January 2028 (operation start)
     * and 18 = July 2028.
     */
    private const POSITIONS = [
        ['manufacturing_overheads', 'Plant / Operations Manager', 39000, [12 => 1]],
        ['manufacturing_overheads', 'Production Supervisor', 14950, [12 => 1]],
        ['direct_labor', 'Shift / Line Leader', 11050, [12 => 2]],
        ['direct_labor', 'Machine Operators', 7150, [12 => 6]],
        ['direct_labor', 'Production Helpers / Packers', 5200, [18 => 6]],
        ['manufacturing_overheads', 'Quality & Food Safety Manager', 26000, [12 => 1]],
        ['manufacturing_overheads', 'QC Chemist / Lab Analyst', 11700, [12 => 2]],
        ['manufacturing_overheads', 'Maintenance Engineer', 20800, [12 => 1]],
        ['manufacturing_overheads', 'Maintenance Technician', 10400, [12 => 2]],
        ['operational_salaries', 'Procurement & Logistics Officer', 14950, [12 => 1]],
        ['manufacturing_overheads', 'Warehouse / Stores Supervisor', 11700, [12 => 1]],
        ['direct_labor', 'Warehouse / Raw Shell Handlers', 5850, [18 => 3]],
        ['general', 'Accountant', 14300, [12 => 1]],
        ['general', 'HR / Admin Officer', 13650, [12 => 1]],
        ['sales', 'B2B Sales Executive', 16250, [12 => 2]],
        ['operational_salaries', 'HSE & dust-safety officer', 15600, [12 => 1]],
        ['marketing', 'Regulatory & technical-marketing specialist', 20800, [12 => 1]],
        ['sales', 'Export & key-account sales manager', 29250, [12 => 1]],
        ['manufacturing_overheads', 'Application / R&D chemist', 18200, [12 => 1]],
        ['direct_labor', 'Membrane & hydrolysate line operators', 7150, [18 => 3]],
    ];

    public function run(): void
    {
        $this->call(BusinessSectorSeeder::class);

        $this->user = $this->owner();

        $this->removeTheFirstVersion();

        if (Project::query()->where('company_id', $this->user->company_id)->where('name', self::STUDY)->exists()) {
            $this->command?->warn('Study "'.self::STUDY.'" already exists - left as it is.');

            return;
        }

        $project = $this->build(self::STUDY);
        app(SalesCalculationService::class)->recalculate($project->refresh());

        $this->command?->info('Study "'.self::STUDY.'" is ready (company '.self::COMPANY.', login '.$this->user->email.').');
    }

    /** The first 3-product study of the company (and a renamed copy of it, if one was made), if it is there, is deleted for good, so only the new study is left. */
    private function removeTheFirstVersion(): void
    {
        $names = [self::LEGACY_STUDY, self::LEGACY_STUDY.' [old 3-product version]', self::VERSION_2_STUDY, self::VERSION_2_STUDY.' [old version]', self::STUDY];

        $old = Project::withTrashed()->where('company_id', $this->user->company_id)->whereIn('name', $names)->get();
        foreach ($old as $project) {
            $project->forceDelete();
        }

        if ($old->isNotEmpty()) {
            $this->command?->warn('The earlier Sky Health study (any version, including a version 3 made before) was removed and is replaced by this one, so only one Sky Health study is left.');
        }
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
            'start_date' => '2027-01', 'duration' => self::YEARS, 'operation_start_date' => '2028-01',
            'tax_rate' => self::TAX_RATE, 'return_rate' => self::RETURN_RATE, 'perpetual_growth_rate' => self::GROWTH_RATE,
            ...$this->salesSettings(),
            'products' => $products, 'rawMaterials' => $materials,
        ]);

        $p = $this->idsByName($project->products());
        $m = $this->idsByName($project->rawMaterials());
        $this->plan = ['channels' => $project->salesChannels()->pluck('id', 'name')->all(), 'markets' => $project->exportMarkets()->pluck('id', 'name')->all()];
        $materialIds = array_values($m);   // same order as RAW_MATERIALS

        $byKey = [];
        foreach (self::PRODUCTS as $key => $def) {
            $byKey[$key] = $p[$def['name']];
            $this->saveProduct($project, $p[$def['name']], $def, $materialIds);
        }

        $payments = [];
        foreach (self::RAW_MATERIALS as $i => $line) {
            $payments[$materialIds[$i]] = $this->terms($line[4], $line[4]);
        }
        $this->saveRawPayments($project, $payments, $this->rawMaterialsComment());

        // Every staff type follows the revenue of the products (the Excel workbook gives no other split).
        $this->saveManpower($project);

        $all = array_values($byKey);
        $powders = [$byKey['food'], $byKey['supplement'], $byKey['oral'], $byKey['cosmetic'], $byKey['nutra']];   // the five calcium carbonate grades
        $this->saveExpenses($project, $this->expenses($all, $powders));
        $this->saveAssets($project, $this->assets());

        $this->extras($project);

        return $project;
    }

    // ══════════════════════════════════════════════════════════════════
    //  The figures (kept in small methods so a figure is easy to find and change)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Expenses (Excel sheet Expenses). Month text "2027-07" = July 2027. The last study month is December 2033.
     * Fixed monthly amounts grow 10% a year; a cost per ton cannot grow in the Excel model, so it is kept flat here too.
     *
     * @param  list<int>  $all  every product  @param  list<int>  $powders  the five calcium carbonate grades
     */
    private function expenses(array $all, array $powders): array
    {
        $end = '2033-12';
        $grow = self::FIXED_COST_GROWTH;

        return [
            // before operation: spread equally from the start month over the months given (Excel: start and end month)
            $this->expense('pre-operating-expenses', 'one_time_expense', 'FSSC 22000 food-safety certification (first cycle)', ['amount' => 1367500, 'start' => '2027-04', 'amortization_months' => 9]),
            $this->expense('pre-operating-expenses', 'one_time_expense', 'Pre-operating payroll and training (3 months before start)', ['amount' => 1758000, 'start' => '2027-10', 'amortization_months' => 3]),
            // manufacturing
            $this->expense('manufacturing-expenses', 'cost_per_unit', 'Electricity (calcium carbonate grades)', ['monthly_cost_of_unit' => 1125, 'start' => '2028-01', 'end' => $end, 'products' => $powders]),
            $this->expense('manufacturing-expenses', 'cost_per_unit', 'Natural gas for drying', ['monthly_cost_of_unit' => 977.02, 'start' => '2028-01', 'end' => $end, 'products' => $powders]),
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Factory rent (1,580 m2)', ['amount' => 293880, 'start' => '2027-07', 'end' => $end, 'increase_rate' => $grow, 'payment_terms' => 'quarterly']),
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Maintenance and spare parts (routine)', ['amount' => 85000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Cleaning, pest control, waste and effluent disposal', ['amount' => 25000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'Outsourced security and cleaning (2 persons)', ['amount' => 17000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('manufacturing-expenses', 'fixed_monthly_repeating_amount', 'PPE and uniforms', ['amount' => 8000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            // selling
            $this->expense('sales', 'expense_as_percentage', 'Freight and delivery (all products, local and export)', ['monthly_percentage' => 4.4, 'start' => '2028-01', 'end' => $end, 'products' => $all, 'payment_terms' => 'customize', 'custom' => [['due_days' => 30, 'rate' => 100]]]),
            $this->expense('sales', 'expense_as_percentage', 'Sales commissions', ['monthly_percentage' => 1.5, 'start' => '2028-01', 'end' => $end, 'products' => $all]),
            $this->expense('sales', 'fixed_monthly_repeating_amount', 'Customer samples and technical visits', ['amount' => 25000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            // marketing
            $this->expense('marketing', 'fixed_monthly_repeating_amount', 'Social media', ['amount' => 50000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('marketing', 'expense_as_percentage', 'Other marketing expense (4% of revenue)', ['monthly_percentage' => 4, 'start' => '2028-01', 'end' => $end, 'products' => $all]),
            // general
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Office, internet, phones and software', ['amount' => 20000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Professional fees, legal and audit', ['amount' => 25000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Insurance, licences and compliance', ['amount' => 30000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
            $this->expense('general-expenses', 'fixed_monthly_repeating_amount', 'Bank charges', ['amount' => 15000, 'start' => '2028-01', 'end' => $end, 'increase_rate' => $grow]),
        ];
    }

    /**
     * Fixed assets (Excel sheet Fixed_Assets, nine groups, EGP 80.1 million in total with 18% freight and customs on imported items,
     * 15% installation and 10% contingency). Kinds as on the app screen:
     *   construction - the site fit-out, S-curve, 40% of the total price up front then 40% / 20% of each month's work after 30 / 90 days
     *   machines     - ordered, delivered two months before they are ready, ready by December 2027 at the latest; paid 30% at the order,
     *                  60% at delivery and 10% when ready; the three lines with a bank loan are funded 70% own money / 30% loan
     *   other        - laboratory (30 / 60 / 10% after 0 / 60 / 180 days), vehicles and IT (cash)
     * replacement_cost_rate = yearly renewal capex as a % of the gross value (replacement every year).
     */
    private function assets(): array
    {
        $loan = ['equity_funding_rate' => 70, 'interest_rate' => 22, 'grace_period' => 12, 'tenor' => 60, 'installment_interval' => 'quarterly'];
        $machine = fn (string $delivery, int $repl) => ['asset_type' => 'machine', 'delivery' => $delivery, 'payment_terms' => 'milestone', 'milestones' => $this->milestones(30, 60, 10), 'replacement_cost_rate' => $repl];

        return [
            $this->asset('Site fit-out (wet/dry zones)', 1, 7022400, 10, '2027-07', '2027-10', [
                'asset_type' => 'construction', 'execution_curve' => 's_curve', 'admin_depreciation_percentage' => 15, 'replacement_cost_rate' => 0.5,
                'payment_terms' => 'customize', 'custom' => [['due_days' => 0, 'rate' => 40, 'from_total' => true], ['due_days' => 30, 'rate' => 40, 'from_total' => false], ['due_days' => 90, 'rate' => 20, 'from_total' => false]],
            ]),
            $this->asset('Receiving, washing & membrane-separation line', 1, 14284785, 10, '2027-04', '2027-10', $machine('2027-08', 2) + $loan),
            $this->asset('Drying, milling & micronization line', 1, 19876692, 10, '2027-03', '2027-10', $machine('2027-08', 3) + $loan),
            $this->asset('Packing, dust protection & safety', 1, 12954638, 10, '2027-06', '2027-11', $machine('2027-09', 2)),
            $this->asset('Membrane & collagen line', 1, 15179494, 10, '2027-07', '2027-12', $machine('2027-10', 2) + $loan),
            $this->asset('Utilities & water treatment', 1, 4048000, 10, '2027-04', '2027-10', $machine('2027-08', 2)),
            $this->asset('Laboratory & QC', 1, 3791172, 5, '2027-09', '2027-09', [
                'asset_type' => 'other', 'admin_depreciation_percentage' => 30, 'replacement_cost_rate' => 8,
                'payment_terms' => 'customize', 'custom' => [['due_days' => 0, 'rate' => 30], ['due_days' => 60, 'rate' => 60], ['due_days' => 180, 'rate' => 10]],
            ]),
            $this->asset('Forklifts & vehicles', 1, 1108800, 5, '2027-11', '2027-11', ['asset_type' => 'other', 'replacement_cost_rate' => 10]),
            $this->asset('IT, ERP & office', 1, 1848000, 5, '2027-09', '2027-09', ['asset_type' => 'other', 'admin_depreciation_percentage' => 100, 'replacement_cost_rate' => 10]),
        ];
    }

    // ── pure calculations (no database; tested on their own) ─────────────────────────────────

    /**
     * Prices year by year, the way the application does it: each year = the year before x (1 + growth), rounded to a whole number.
     * The growth starts at study year $growthFrom (default 1); the years before it keep the base price.
     */
    public static function priceChain(float $first, float $growthPercent, int $growthFrom = 1): array
    {
        $prices = [0 => (float) round($first)];
        for ($year = 1; $year < self::YEARS; $year++) {
            $prices[$year] = $year < $growthFrom
                ? $prices[$year - 1]
                : (float) round($prices[$year - 1] * (1 + $growthPercent / 100));
        }

        return $prices;
    }

    /** The yearly price growth %: 0 until the growth starts (2029), then the same every year. @return list<int|float> */
    public static function growthRates(float $growthPercent): array
    {
        return array_map(fn ($year) => $year < self::GROWTH_START_INDEX ? 0 : $growthPercent, range(0, self::YEARS - 1));
    }

    /** Share of the maximum capacity sold in each year (2027 ... 2033): the same for every product, exactly as typed by management. @return list<int|float> */
    public static function targets(): array
    {
        return self::TARGET_RAMP;
    }

    /**
     * Exchange rates, EGP for one unit of the foreign currency, one per year of the study. They are FIXED (USD 56, EUR 60): no forecast is used
     * because no credible source is attached to one.
     *
     * @return array<string,list<float>>
     */
    public static function rates(): array
    {
        return [
            'usd'  => array_fill(0, self::YEARS, (float) self::FX),
            'euro' => array_fill(0, self::YEARS, (float) self::FX_EUR),
        ];
    }

    /** The one export price of a product: the same NUMBER in every market (USD 450 = EUR 450), the highest one, as in the Excel workbook (Sales_Plan!C139 = Yes). */
    public static function exportPrice(float $usdPerTon, string $currency = 'usd'): float
    {
        return round($usdPerTon, 2);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Saving each form (the form request runs first, then the same service the screen uses)
    // ══════════════════════════════════════════════════════════════════

    private function saveStudy(Project $project, array $data): Project
    {
        return app(ProjectService::class)->save($project, $this->validated(UpdateProjectRequest::class, $data, $project));
    }

    /** @param array<string,mixed> $def one entry of PRODUCTS @param list<int> $materialIds */
    private function saveProduct(Project $project, int $productId, array $def, array $materialIds): void
    {
        $years = range(0, self::YEARS - 1);
        $localPrices = self::priceChain($def['usd_ton'] * self::FX, self::LOCAL_GROWTH, self::GROWTH_START_INDEX);
        $exportPrices = self::priceChain($def['usd_ton'] * self::FX, self::EXPORT_GROWTH, self::GROWTH_START_INDEX);   // not used while the study has export markets (the markets carry the prices)

        $recipe = [];
        foreach ($def['rm'] as $i => $percent) {
            $recipe[] = ['raw_material_id' => $materialIds[$i], 'percentages' => array_fill(0, self::YEARS, $percent)];
        }

        // Before the export start year the whole quantity is sold locally.
        $localShare = array_map(fn ($y) => $y < self::EXPORT_START_INDEX ? 100 : $def['local'], $years);

        $data = [
            'max_capacity'                      => array_fill(0, self::YEARS, $def['capacity']),
            'target_percentages'                => self::targets(),
            'local_target_quantity_percentages' => $localShare,
            'local_growth_rates'                => self::growthRates(self::LOCAL_GROWTH),
            'local_price_per_unit'              => $localPrices,
            'export_growth_rates'               => self::growthRates(self::EXPORT_GROWTH),
            'export_price_per_unit'             => $exportPrices,
            'seasonality'                       => ['type' => 'flat'],
            'fg_inventory_coverage_days'        => $def['coverage'],
            'comment_for_sales'                 => $def['comment'],
            'collection_policy_value'           => [
                'local'  => $this->terms(self::LOCAL_TERMS, self::LOCAL_TERMS),
                'export' => $this->terms(self::EXPORT_TERMS, self::EXPORT_TERMS),
            ],
            'rawMaterials'                      => $recipe,
            'after'                             => 'stay',
        ];
        if ($this->plan !== null) {
            $data += $this->productSales($def);
        }

        $product = $project->products()->whereKey($productId)->firstOrFail();
        app(ProductPlanService::class)->save($project, $product, $this->validated(UpdateProductRequest::class, $data, $project));
    }

    // ══════════════════════════════════════════════════════════════════
    //  Sales channels, export markets and exchange rates
    //  Local: two kinds of buyers. Export (from 2029): Saudi Arabia and the UAE pay in US dollars, Germany pays in euros. The USD price is the same
    //  in every market and grows 15% a year from 2029; the euro price is the same number as the USD price (USD 56, EUR 60, fixed).
    // ══════════════════════════════════════════════════════════════════

    /** Local channels: name, share %, price difference %, own terms (null = the product's terms). */
    private const CHANNELS = [['Food and supplement manufacturers', 55, 3, null], ['Distributors', 45, -3, [30, 70, 0, 45, 60]]];

    /** Export markets: name, currency, share of the export quantity %. */
    private const MARKETS = [['Saudi Arabia', 'usd', 60], ['United Arab Emirates', 'usd', 30], ['Germany', 'euro', 10]];

    /** The study form's sales part. @return array<string,mixed> */
    private function salesSettings(): array
    {
        return [
            'has_export_sales' => true,
            'sales_channels'   => array_map(fn ($c) => ['name' => $c[0]], self::CHANNELS),
            'fx_rates'         => array_map(fn ($currency, $rates) => ['currency' => $currency, 'rates' => $rates], array_keys(self::rates()), self::rates()),
            'export_markets'   => array_map(fn ($m) => ['name' => $m[0], 'currency' => $m[1]], self::MARKETS),
        ];
    }

    /**
     * What a product sends for its channels and markets.
     *
     * @param  array<string,mixed>  $def
     * @return array<string,mixed>
     */
    private function productSales(array $def): array
    {
        $years = range(0, self::YEARS - 1);
        $each = fn ($value) => array_fill(0, self::YEARS, $value);
        $own = fn (array $t) => $this->terms($t, $t);

        return [
            'export_seasonality' => ['type' => 'flat'],
            'segments' => [
                'local'  => array_map(fn ($c) => [
                    'sales_channel_id' => $this->plan['channels'][$c[0]], 'allocation_percentages' => $each($c[1]), 'price_differences' => $each($c[2]),
                    'uses_own_terms' => $c[3] !== null, 'collection_policy_value' => $c[3] === null ? null : $own($c[3]),
                ], self::CHANNELS),
                'export' => array_map(fn ($m) => [
                    'export_market_id' => $this->plan['markets'][$m[0]],
                    'allocation_percentages' => array_map(fn ($y) => $y < self::EXPORT_START_INDEX ? 0 : $m[2], $years),
                    'first_foreign_price' => self::exportPrice((float) $def['usd_ton'], $m[1]),
                    'foreign_growth_rates' => self::growthRates(self::EXPORT_GROWTH),
                    'uses_own_terms' => false, 'collection_policy_value' => null,
                ], self::MARKETS),
            ],
        ];
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

    /** Staff of the Excel workbook (net salaries, 20% tax and 15% insurance added by the application). Every type follows the revenue of the products. */
    private function saveManpower(Project $project): void
    {
        $rows = [];
        foreach (self::POSITIONS as [$type, $position, $netSalary, $hirings]) {
            $rows[] = ['type' => $type, 'position' => $position, 'avg_salary' => $netSalary, 'existing_count' => 0, 'hirings' => $hirings];
        }

        $data = [
            'salaries_annual_increase' => self::SALARY_INCREASE, 'salaries_tax_rate' => self::SALARY_TAX, 'social_insurance_rate' => self::SOCIAL_INSURANCE,
            'rows' => $rows, 'by_revenue' => [], 'allocations' => [], 'after' => 'stay',
        ];

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
        // Slots follow the order the products were added: first = Food, second = Supplement, third = Oral care, fourth = Cosmetic, fifth = Membrane powder.
        app(SensitivityService::class)->save($project, $this->user, [
            ['slot' => 'first', 'target' => -15, 'rm' => 5, 'days' => 15],
            ['slot' => 'second', 'target' => -15, 'rm' => 5, 'days' => 15],
            ['slot' => 'third', 'target' => -15, 'rm' => 5, 'days' => 15],
            ['slot' => 'fourth', 'target' => -15, 'rm' => 5, 'days' => 15],
            ['slot' => 'fifth', 'target' => -50, 'rm' => 10, 'days' => 30],
        ], -10.0);   // every exchange rate 10% lower (a weaker dollar and euro)

        $project->update(['dashboard_comment_1' => $this->dashboardNoteOne(), 'dashboard_comment_2' => $this->dashboardNoteTwo()]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  The words of the study (what a reader sees on the screens and in the printed report)
    // ══════════════════════════════════════════════════════════════════

    private function intro(): string
    {
        return 'Sky Healthy Food runs a pasteurized-egg factory in 6th of October City, Giza, so it already has the main raw material for this project: eggshell, about 93-95% natural calcium carbonate. '
            .'The shell is captive waste of the egg plant and enters the study at zero cost. '
            .'The study looks at a new SKY-CAL plant that washes the shell, separates the membrane, dries it and micronizes it (D90 about 18 micron) into five calcium carbonate grades '
            .'(food ingredient, supplement, oral care, cosmetic and nutraceutical) on one shared line of about 125 tons of powder a month (process flow by Dr. Mahmoud Emara, June 2026), '
            .'plus two membrane products: membrane powder and collagen hydrolysate. '
            .'Equipment is ordered, delivered, installed and tested during 2027; operation starts in January 2028 and the products start selling between January and July 2028. '
            .'Local sales go to manufacturers and distributors; export starts in 2029 to Saudi Arabia and the UAE (priced in US dollars) and Germany (priced in euros). '
            .'The study covers seven years (2027-2033), is in Egyptian pounds, and treats the plant as a new business inside Sky. '
            .'Exchange rates are fixed in every year at EGP 56 to the US dollar and EGP 60 to the euro (no forecast is used, because none comes with a credible source). '
            .'Local and export prices both grow 15% a year, from 2029. Selling prices come from public supplier listings and trade sources, not from quotations. '
            .'Equipment, staffing, utilities and expenses that the Excel feasibility workbook marks as placeholders are estimates to be replaced by quotations.';
    }

    private function rawMaterialsComment(): string
    {
        return 'Each material is entered as a % of the selling price: its cost per ton of product (quantity per ton x price, Excel sheet Recipes) divided by the 2027 blended selling price, '
            .'and held at that % in every year (cost and price are assumed to move together). '
            .'Eggshell is captive waste of the Sky egg plant and costs nothing; it is a joint input shared by all products. '
            .'Most materials are settled 30 days after delivery; enzymes, bulk bags and membrane packaging are 20% cash and 80% after 30 days; laboratory testing is settled after 15 days. '
            .'Stock days follow the application list (0, 15, 30, 45, 60 ...). Units without an exact match (cubic metre of water, laboratory batch) are entered as "others". '
            .'Prices without a source (wash chemicals, bags, pallets, filter parts, laboratory testing, membrane packaging) are placeholders to be replaced by quotations.';
    }

    private function dashboardNoteOne(): string
    {
        return 'How to read this study. It is an initial feasibility study with seven years of monthly figures in Egyptian pounds, built from the Excel workbook SKY-CAL_Feasibility_V2 (11 November 2026). '
            .'Operation starts in January 2028, so 2027 is investment only and there are six operating years. 2028 is local sales only; export starts in 2029. '
            .'Export goes to Saudi Arabia (60% of export), the UAE (30%) and Germany (10%). Every product has one export price, the same in every market (the highest: the same number in USD and EUR, e.g. USD 450 = EUR 450). '
            .'Prices: 2028 is the base price; from 2029 local and export prices both grow 15% a year. '
            .'Exchange rates are fixed in every year (USD/EGP 56, EUR/EGP 60); no forecast is used. '
            .'The share of the maximum capacity sold is 25% in 2028 (a part year), then 50%, 60%, 70%, 80% and 85% in 2033 (90% from 2034 if the study is extended), the same for every product. '
            .'Because the plant is heavy on equipment, a large part of the value (the terminal value) comes from the years after 2033, so look at the payback and at the share of the terminal value in the valuation tiles as well as at the NPV.';
    }

    private function dashboardNoteTwo(): string
    {
        return 'What decides the result, and what to confirm next. The main drivers are: '
            .'(1) the price and volume Sky can really get for membrane powder and collagen hydrolysate, the two most uncertain prices; '
            .'(2) firm equipment quotations: the EGP 80.1 million of fixed assets includes 18% freight and customs, 15% installation and 10% contingency, but many machine prices are listings or placeholders; '
            .'(3) the exchange rate: it is fixed at USD 56 and EUR 60 in every year, and the What-if screen has an exchange-rate field (saved at -10%) to test a stronger or weaker pound; '
            .'(4) the capacity ramp-up (25% to 85% of capacity), the price growth (15% local and export) and the collection terms, which are management assumptions. '
            .'Also to confirm: rented or Sky-owned space, the thermal-treatment unit, in-house or external ICP-OES testing, vendor quotes for the membrane separation unit, the electricity tariff, '
            .'and EU entry for Germany (REACH, supplement and cosmetic rules, novel-food status of membrane products). '
            .'The What-if screen holds a saved downside case: sales -15% on the four powder grades and -50% on membrane powder, higher raw material cost, slower collections and exchange rates 10% lower.';
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

    /** A machine paid by milestones: % at the purchase, % at the delivery, % when it is ready (no waiting days). */
    private function milestones(int $purchase, int $delivery, int $ready): array
    {
        return ['purchase' => ['rate' => $purchase, 'days' => 0], 'delivery' => ['rate' => $delivery, 'days' => 0], 'ready' => ['rate' => $ready, 'days' => 0]];
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
