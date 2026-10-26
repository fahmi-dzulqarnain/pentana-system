<?php

namespace App\Models\Payment;

use App\Models\User;
use Database\Factories\BankStatementImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    use HasFactory;

    protected static function newFactory(): BankStatementImportFactory
    {
        return BankStatementImportFactory::new();
    }

    protected $fillable = [
        'uploaded_by', 'file_sha256', 'statement_from', 'statement_to',
        'lines_new', 'lines_duplicate', 'auto_verified', 'confirmed', 'unmatched',
    ];

    protected function casts(): array
    {
        return ['statement_from' => 'date', 'statement_to' => 'date'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }
}
