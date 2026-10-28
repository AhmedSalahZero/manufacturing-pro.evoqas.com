<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Opening balance: other creditors.
// Table: other_credits_opening_balances
class OtherCreditsOpeningBalance extends Model
{
    protected $table = 'other_credits_opening_balances';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount'    => 'decimal:2',
            'payload'   => 'array',
            'statement' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
