<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Who opened a shared link.
// Table: sharing_links_visitors
class SharingLinkVisitor extends Model
{
    protected $table = 'sharing_links_visitors';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(SharingLink::class, 'link_code', 'link_code');
    }
}
