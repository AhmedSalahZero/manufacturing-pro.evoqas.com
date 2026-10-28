<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SAVED RESULT: cash-in / cash-out statement of a project.
// Table: cash_in_out_statements
class CashInOutStatement extends Model
{
    protected $table = 'cash_in_out_statements';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cash_end_balance'          => 'array',
            'working_capital_injection' => 'array',
            'equity_injection'          => 'array',
            'loan_withdrawal'           => 'array',
            'customer_collection'       => 'array',
            'supplier_payments'         => 'array',
            'taxes'                     => 'array',
            'expenses'                  => 'array',
            'fixed_asset_payments'      => 'array',
            'loan_installments'         => 'array',
            'total_cash_out'            => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
