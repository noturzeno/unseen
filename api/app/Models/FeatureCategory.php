<?php

namespace App\Models;

use Database\Factories\FeatureCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'position'])]
class FeatureCategory extends Model
{
    /** @use HasFactory<FeatureCategoryFactory> */
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class, 'category_id');
    }
}
