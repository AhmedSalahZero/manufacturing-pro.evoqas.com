<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Opening balance: equity / paid-up capital.
// Table: equity_opening_balances
class EquityOpeningBalance extends Model
{
    protected $table = 'equity_opening_balances';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_up_capital_amount'   => 'decimal:2',
            'legal_reserve_extended'   => 'array',
            'paid_up_capital_extended' => 'array',
            'legal_reserve'            => 'decimal:2',
            'retained_earnings'        => 'decimal:2',
            'statement'                => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
