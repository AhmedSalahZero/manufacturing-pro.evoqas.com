<?php

namespace Tests\Concerns;

use App\Models\ExportMarket;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\Project;
use App\Models\SalesChannel;
use App\Services\ProjectService;

// A saved study with sales channels, export markets and exchange rates, and the product form as the screen sends it.
// The class using it must have `private User $owner` (set in setUp) and extend Tests\TestCase.
trait BuildsSalesStudy
{
    private function each(float|int $value): array
    {
        return array_fill_keys(range(0, 4), $value);
    }

    /** The study form: 5 years from 2026-01, one product (Valve, VAT 14 %, withholding 1 %) and one raw material. */
    private function form(array $sales = []): array
    {
        return [
            'name' => 'Plant A', 'new_company' => true, 'main_functional_currency' => 'egp', 'intro_paragraph' => 'A plant.', 'business_sector_id' => null,
            'start_date' => '2026-01', 'duration' => 5, 'operation_start_date' => '2026-01', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [['name' => 'Valve', 'measurement_unit' => 'ton', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
            'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ] + $sales;
    }

    private function salesSettings(array $override = []): array
    {
        return array_merge([
            'has_export_sales' => true,
            'sales_channels'   => [['name' => 'Retail'], ['name' => 'Wholesale'], ['name' => 'Distributors']],
            'fx_rates'         => [
                ['currency' => 'usd', 'rates' => [0 => 50, 1 => 54, 2 => 58, 3 => 60, 4 => 62]],
                ['currency' => 'euro', 'rates' => [0 => 55, 1 => 58, 2 => 61, 3 => 63, 4 => 65]],
            ],
            'export_markets'   => [['name' => 'Saudi Arabia', 'currency' => 'usd'], ['name' => 'UAE', 'currency' => 'usd'], ['name' => 'Italy', 'currency' => 'euro']],
        ], $override);
    }

    /** A saved study. With $sales = null it has no channels or markets (the old way of working). */
    private function study(?array $sales = []): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');
        $this->actingAs($this->owner)->put(route('projects.update', $project), $this->form($sales === null ? [] : $this->salesSettings($sales)))
            ->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        return $project->refresh();
    }

    private function valve(Project $project): Product
    {
        return $project->products()->where('name', 'Valve')->firstOrFail();
    }

    private function channel(Project $project, string $name): SalesChannel
    {
        return $project->salesChannels()->where('name', $name)->firstOrFail();
    }

    private function market(Project $project, string $name): ExportMarket
    {
        return $project->exportMarkets()->where('name', $name)->firstOrFail();
    }

    /** Retail 50 % (+10 %), Wholesale 30 % (0 %), Distributors 20 % (-5 %). */
    private function channelRows(Project $project, array $own = []): array
    {
        $rows = [];
        foreach (['Retail' => [50, 10], 'Wholesale' => [30, 0], 'Distributors' => [20, -5]] as $name => [$share, $difference]) {
            $rows[] = array_merge(['sales_channel_id' => $this->channel($project, $name)->id, 'allocation_percentages' => $this->each($share), 'price_differences' => $this->each($difference), 'uses_own_terms' => false], $own[$name] ?? []);
        }

        return $rows;
    }

    /** Saudi Arabia 50 % USD 230, UAE 30 % USD 220, Italy 20 % EUR 190. */
    private function marketRows(Project $project): array
    {
        $rows = [];
        foreach (['Saudi Arabia' => [50, 230], 'UAE' => [30, 220], 'Italy' => [20, 190]] as $name => [$share, $price]) {
            $rows[] = ['export_market_id' => $this->market($project, $name)->id, 'allocation_percentages' => $this->each($share), 'first_foreign_price' => $price, 'foreign_growth_rates' => $this->each(0), 'uses_own_terms' => false];
        }

        return $rows;
    }

    /** Capacity 12,000 tons a year, all of it sold, half local and half export. */
    private function payload(Project $project, array $override = []): array
    {
        $policy = [
            ['cash_payment' => 20, 'rate' => [50, 30], 'due_in_days' => [30, 60]],
            ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]],
        ];

        return array_merge([
            'max_capacity' => $this->each(12000), 'target_percentages' => $this->each(100), 'local_target_quantity_percentages' => $this->each(50),
            'local_growth_rates' => $this->each(0), 'local_price_per_unit' => $this->each(10000),
            'export_growth_rates' => $this->each(0), 'export_price_per_unit' => $this->each(0),
            'seasonality' => ['type' => 'flat'], 'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0, 'fg_inventory_coverage_days' => 0,
            'collection_policy_value' => ['local' => $policy, 'export' => $policy],
            'segments' => array_key_exists('segments', $override) ? $override['segments'] : ['local' => $this->channelRows($project), 'export' => $project->exportMarkets()->where('name', 'Italy')->exists() ? $this->marketRows($project) : []],
            'rawMaterials' => [], 'after' => 'stay',
        ], $override);
    }

    private function save(Project $project, array $override = [])
    {
        return $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $this->valve($project)]), $this->payload($project, $override));
    }

    private function saved(Project $project, array $override = []): void
    {
        $this->save($project, $override)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();
    }

    private function year(?array $monthly, int $year = 0): float
    {
        return array_sum(array_map('floatval', array_slice((array) $monthly, $year * 12, 12, true)));
    }

    private function segment(Project $project, string $kind, string $name): ProductSegment
    {
        $column = $kind === 'local' ? 'sales_channel_id' : 'export_market_id';
        $id = $kind === 'local' ? $this->channel($project, $name)->id : $this->market($project, $name)->id;

        return ProductSegment::where('product_id', $this->valve($project)->id)->where($column, $id)->firstOrFail();
    }
}
