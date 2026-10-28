<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SAVED RESULT: income statement series of a project.
// Table: income_statements
class IncomeStatement extends Model
{
    protected $table = 'income_statements';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_sales_revenues'                      => 'array',
            'annually_sales_revenues_growth_rates'      => 'array',
            'gross_profit'                              => 'array',
            'annually_gross_profit_revenue_percentages' => 'array',
            'ebitda'                                    => 'array',
            'annually_ebitda_revenue_percentages'       => 'array',
            'ebit'                                      => 'array',
            'annually_ebit_revenue_percentages'         => 'array',
            'ebt'                                       => 'array',
            'monthly_ebt'                               => 'array',
            'annually_ebt_revenue_percentages'          => 'array',
            'net_profit'                                => 'array',
            'annually_net_profit'                       => 'array',
            'annually_net_profit_revenue_percentages'   => 'array',
            'accumulated_retained_earnings'             => 'array',
            'total_depreciation'                        => 'array',
            'total_cogs'                                => 'array',
            'total_percentages_cogs'                    => 'array',
            'sganda'                                    => 'array',
            'sganda_revenues_percentages'               => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
