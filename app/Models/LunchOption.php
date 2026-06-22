<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LunchOption extends Model
{
    use HasFactory;

    protected $fillable = ['lunch_id', 'meal_option_id', 'enabled', 'default_count'];

    protected $casts = ['enabled' => 'boolean', 'default_count' => 'integer'];

    public function lunch(): BelongsTo
    {
        return $this->belongsTo(Lunch::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }
}
