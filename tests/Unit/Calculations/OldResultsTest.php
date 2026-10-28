<?php

namespace Tests\Unit\Calculations;

use App\Calculations\CollectionPolicy;
use App\Calculations\ProductSalesCalculator;
use App\Calculations\RawMaterialCalculator;
use App\Calculations\SeasonalityCalculator;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// The new calculation engine against the results the previous ZAVERO saved for real (test) studies.
// tests/Fixtures/stage2_golden.json holds 7 products and 5 raw materials: what was typed and what the old application worked out.
// Every monthly figure must agree within 0.01.   Run:  php artisan test --filter=OldResultsTest
class OldResultsTest extends TestCase
{
    private const DELTA = 0.01;

    private array $golden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->golden = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/stage2_golden.json'), true);
    }

    private function assertSeries(array $expected, array $actual, string $label): void
    {
        $this->assertNotEmpty($expected, $label);

        foreach ($expected as $month => $value) {
            $this->assertEqualsWithDelta($value, $actual[$month] ?? 0, self::DELTA, "$label, month $month");
        }
    }

    public function test_the_fixture_is_there(): void
    {
        $this->assertCount(7, $this->golden['products']);
        $this->assertCount(5, $this->golden['materials']);
    }

    public function test_product_sales_units_to_make_and_stock_match_the_old_results(): void
    {
        foreach ($this->golden['products'] as $case) {
            $timeline = Timeline::forStudy($case['study']['start'], $case['study']['duration'], $case['study']['operation_start']);

            $result = ProductSalesCalculator::calculate($timeline, [
                'selling_start_index'   => $case['selling_start_index'],
                'seasonality_weights'   => SeasonalityCalculator::monthWeights($case['seasonality']['type'], $case['seasonality']['percentages']),
                'local_quantities'      => $case['local_quantities'],
                'local_prices'          => $case['local_prices'],
                'export_quantities'     => $case['export_quantities'],
                'export_prices'         => $case['export_prices'],
                'fg_beginning_quantity' => $case['fg_beginning_quantity'],
                'fg_coverage_days'      => $case['fg_coverage_days'],
            ]);

            $this->assertSeries($case['expected']['values'], $result['values'], $case['label'].' sales value');
            $this->assertSeries($case['expected']['quantities'], $result['quantities'], $case['label'].' sales quantity');
            $this->assertSeries($case['expected']['manufacturing_quantity'], $result['inventory']['manufacturing_quantity'], $case['label'].' units to make');
            $this->assertSeries($case['expected']['end_balance'], $result['inventory']['end_balance'], $case['label'].' closing stock');
        }
    }

    public function test_raw_material_purchases_and_supplier_payments_match_the_old_results(): void
    {
        foreach ($this->golden['materials'] as $case) {
            $timeline = Timeline::forStudy($case['study']['start'], $case['study']['duration'], $case['study']['operation_start']);
            $terms = $case['terms'];

            $result = RawMaterialCalculator::calculate(
                $timeline, $case['consumed'], $case['beginning_inventory'], $case['coverage_days'],
                $case['vat'], $case['withhold'], $terms[0] ?? [], $terms[1] ?? [],
            );

            $this->assertSeries($case['expected']['purchases'], $result['inventory']['manufacturing_quantity'], $case['label'].' purchases');
            $this->assertSeries($case['expected']['vat'], $result['payments']['monthly']['vat'], $case['label'].' VAT');
            $this->assertSeries($case['expected']['payment'], $result['payments']['monthly']['payment'], $case['label'].' payments');
            $this->assertSeries($case['expected']['withhold_amount'], $result['payments']['monthly']['withhold_amount'], $case['label'].' withholding');
            $this->assertSeries($case['expected']['end_balance'], $result['payments']['monthly']['end_balance'], $case['label'].' supplier balance');
        }
    }

    public function test_the_policy_helper_reads_the_old_stored_terms(): void
    {
        $terms = $this->golden['materials'][0]['terms'][0];

        $this->assertArrayHasKey(0, CollectionPolicy::dueDayRates($terms));
    }
}
