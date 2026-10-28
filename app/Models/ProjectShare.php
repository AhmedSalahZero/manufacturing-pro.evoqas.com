<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A study the owner shared with a colleague of the same company: view only or view and edit.
class ProjectShare extends Model
{
    protected $table = 'project_shares';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['can_edit' => 'boolean'];
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
