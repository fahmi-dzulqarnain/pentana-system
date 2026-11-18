<?php

namespace App\Models;

use App\Support\TimeFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'overview',
        'description',
        'starts_at',
        'timeframe',
        'location',
        'status',
        'capacity',
        'registration_closes_at',
        'ends_at',
        'reminder_sent_at',
        'registration_fields',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'capacity' => 'integer',
        'registration_fields' => 'array',
    ];

    /** Longest plain-text overview, in characters (the admin form enforces the same limit). */
    public const OVERVIEW_MAX = 200;

    /**
     * Plain-text excerpt of rich HTML: tags stripped, entities decoded (so "Tom's" never reads
     * "Tom&#39;s"), whitespace collapsed, cut at a word boundary. Null when there's no text,
     * e.g. a description that is only an image.
     */
    public static function plainTextExcerpt(?string $html, int $limit = self::OVERVIEW_MAX): ?string
    {
        if (blank($html)) {
            return null;
        }

        // Keep block boundaries as spaces so "<p>A</p><p>B</p>" reads "A B", not "AB".
        $html = preg_replace('~<(?:br|/p|/li|/h[1-6]|/div|/blockquote)\b[^>]*>~i', '$0 ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut, " ,.;:-").'…';
    }

    /** The short summary for lists and cards: the admin's overview, else an excerpt of the full description. */
    public function overviewText(): ?string
    {
        return filled($this->overview) ? $this->overview : self::plainTextExcerpt($this->description);
    }

    /**
     * True when the full description has real content. An editor left empty still saves
     * "<p></p>", which must not count (no empty "View details", null in the API).
     */
    public function hasFullDescription(): bool
    {
        return filled($this->description)
            && (self::plainTextExcerpt($this->description) !== null || stripos($this->description, '<img') !== false);
    }

    /**
     * The full description HTML, sanitised (no scripts or event handlers, for the web page and
     * any app WebView alike), with every uploaded image pointed at its current public-disk URL
     * (rebuilt from the stored `data-id` path, so a changed APP_URL never breaks old images).
     */
    public function fullDescriptionHtml(): ?string
    {
        if (! $this->hasFullDescription()) {
            return null;
        }

        $html = preg_replace_callback('~<img\b[^>]*>~i', function (array $match): string {
            $tag = $match[0];

            if (! preg_match('~\sdata-id\s*=\s*"([^"]*)"~i', $tag, $id) || $id[1] === '') {
                return $tag;
            }

            $src = 'src="'.e(Storage::disk('public')->url(html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5))).'"';

            return preg_match('~\ssrc\s*=\s*"[^"]*"~i', $tag)
                ? preg_replace('~(\s)src\s*=\s*"[^"]*"~i', '$1'.$src, $tag, 1)
                : preg_replace('~^<img\b~i', '<img '.$src, $tag, 1);
        }, $this->description);

        return (string) str($html)->sanitizeHtml();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    /** True when the admin has attached at least one registration question. */
    public function hasRegistrationFields(): bool
    {
        return ! empty($this->registration_fields);
    }

    public function registeredCount(): int
    {
        return $this->registrations->where('status', 'registered')->count();
    }

    public function waitlistCount(): int
    {
        return $this->registrations->where('status', 'waitlisted')->count();
    }

    public function attendedCount(): int
    {
        return $this->registrations->where('attended', true)->count();
    }

    /** Not yet scheduled: only a rough timeframe is known, so it has no start time. */
    public function isComingSoon(): bool
    {
        return $this->starts_at === null;
    }

    /** "Sunday, 14 Dec · 9:00 AM", or "Coming soon · December 2026" before the date is set. */
    public function whenLabel(): string
    {
        if ($this->starts_at) {
            return $this->starts_at->translatedFormat('l, j M · '.TimeFormat::time());
        }

        return $this->timeframe
            ? __('Coming soon · :timeframe', ['timeframe' => $this->timeframe])
            : __('Coming soon');
    }

    /** Not yet started, including coming-soon activities - dated ones first, soonest first. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            // Portable NULLS LAST (Postgres and SQLite disagree on the default).
            ->orderByRaw('starts_at is null')
            ->orderBy('starts_at');
    }

    /**
     * Registration closes at an explicit deadline if set, otherwise when the activity starts.
     * Null for a coming-soon activity without a deadline: it stays open until scheduled.
     */
    public function registrationClosesAt(): ?Carbon
    {
        return $this->registration_closes_at ?? $this->starts_at;
    }

    /** Free seats remaining, or null when capacity is unlimited. */
    public function spotsLeft(): ?int
    {
        return $this->capacity === null
            ? null
            : max(0, $this->capacity - $this->registeredCount());
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->registeredCount() >= $this->capacity;
    }

    public function isPast(): bool
    {
        return $this->starts_at !== null && now()->gte($this->starts_at);
    }

    /** Members may register only while published and before registration closes. */
    public function isOpenForRegistration(): bool
    {
        $closesAt = $this->registrationClosesAt();

        return $this->status === 'published' && ($closesAt === null || now()->lt($closesAt));
    }
}
