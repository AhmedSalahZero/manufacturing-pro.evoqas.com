<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// How one product uses one sales channel (kind = local) or one export market (kind = export):
// share of the quantity per year, price difference / foreign price, optional own collection terms and the saved results.
// Table: product_segments
class ProductSegment extends Model
{
    public const LOCAL = 'local';

    public const EXPORT = 'export';

    protected $table = 'product_segments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allocation_percentages'  => 'array',
            'price_differences'       => 'array',
            'first_foreign_price'     => 'decimal:2',
            'foreign_growth_rates'    => 'array',
            'uses_own_terms'          => 'boolean',
            'collection_policy_value' => 'array',
            'monthly_quantities'      => 'array',
            'monthly_values'          => 'array',
            'collection_statement'    => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function exportMarket(): BelongsTo
    {
        return $this->belongsTo(ExportMarket::class);
    }
}
