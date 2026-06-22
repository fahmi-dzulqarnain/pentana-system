<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LunchResponse extends Model
{
    use HasFactory;

    protected $fillable = ['lunch_id', 'user_id', 'meal_option_id'];

    protected $casts = ['meal_option_id' => 'integer'];

    public function lunch(): BelongsTo
    {
        return $this->belongsTo(Lunch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }
}
