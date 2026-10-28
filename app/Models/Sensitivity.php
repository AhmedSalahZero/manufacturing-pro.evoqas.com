<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// What-if settings of a project: price, quantity and cost changes.
// Table: sensitivities
class Sensitivity extends Model
{
    protected $table = 'sensitivities';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'target_product_first'  => 'decimal:2',
            'target_product_second' => 'decimal:2',
            'target_product_third'  => 'decimal:2',
            'target_product_fourth' => 'decimal:2',
            'target_product_fifth'  => 'decimal:2',
            'rm_product_first'      => 'decimal:2',
            'rm_product_second'     => 'decimal:2',
            'rm_product_third'      => 'decimal:2',
            'rm_product_fourth'     => 'decimal:2',
            'rm_product_fifth'      => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
