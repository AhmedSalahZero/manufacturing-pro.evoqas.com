<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Opening balance: cash and bank.
// Table: cash_and_bank_opening_balances
class CashAndBankOpeningBalance extends Model
{
    protected $table = 'cash_and_bank_opening_balances';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cash_and_bank_amount'       => 'decimal:2',
            'customer_receivable_amount' => 'decimal:2',
            'inventory_amount'           => 'decimal:2',
            'payload'                    => 'array',
            'statement'                  => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
