<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Staff positions: salaries, counts and the calculated monthly cost.
// Table: manpowers
class Manpower extends Model
{
    protected $table = 'manpowers';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hirings'                            => 'array',
            'salary_expenses'                    => 'array',
            'tax_and_social_insurance_statement' => 'array',
            'salary_payments'                    => 'array',
            'accumulated_manpower_counts'        => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
