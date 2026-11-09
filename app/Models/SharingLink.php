<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Links used to share a project with other people: type view (the whole study, looking only) or fill (the customer fills in the input pages),
// optionally with an expiry date.
// Table: sharing_links
class SharingLink extends Model
{
    protected $table = 'sharing_links';

    /** Every column may be filled except the id. The screens always filter what they pass in (validated data only). */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'closed'     => 'boolean',
            'expires_at' => 'date',
        ];
    }

    public const VIEW = 'view';   // the whole study, looking only
    public const FILL = 'fill';   // the customer fills in the input pages

    public function isFill(): bool
    {
        return $this->type === self::FILL;
    }

    /** The expiry date is the last day the link works. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    /** Can a visitor use it right now? (not paused, not expired) */
    public function isActive(): bool
    {
        return ! $this->closed && ! $this->isExpired();
    }

    /** What the visitor of a link that cannot be used is told: 'expired' (past its date) or 'paused' (switched off). */
    public function unavailableReason(): string
    {
        return $this->isExpired() && ! $this->closed ? 'expired' : 'paused';
    }

    /** 'paused' (switched off by the owner), 'expired' (past its date) or 'active'. */
    public function status(): string
    {
        return $this->closed ? 'paused' : ($this->isExpired() ? 'expired' : 'active');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(SharingLinkVisitor::class, 'link_code', 'link_code');
    }
}
