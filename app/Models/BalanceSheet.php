<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SAVED RESULT: balance sheet and working-capital series of a project.
// Table: balance_sheets
class BalanceSheet extends Model
{
    protected $table = 'balance_sheets';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'supplier_payables'                => 'array',
            'other_debtors'                    => 'array',
            'raw_material_inventory'           => 'array',
            'total_fgs'                        => 'array',
            'customer_receivables'             => 'array',
            'cash_and_banks'                   => 'array',
            'total_current_liabilities'        => 'array',
            'total_current_assets'             => 'array',
            'change_in_customer_receivables'   => 'array',
            'change_in_fg_inventory'           => 'array',
            'change_in_raw_material_inventory' => 'array',
            'change_in_other_debtors'          => 'array',
            'change_in_supplier_payables'      => 'array',
            'change_in_other_creditors'        => 'array',
            'net_change_in_working_capital'    => 'array',
            'debit_funding_percentages'        => 'array',
            'equity_funding_percentages'       => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
