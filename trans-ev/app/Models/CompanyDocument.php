<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** KYC document of an operator. Stored on private disk; downloaded only through an authorised route. */
class CompanyDocument extends Model
{
    use LogsActivity;

    /** Document types (Admin → Settings → Lists & options → KYC document types). */
    public static function types(): array
    {
        return \App\Models\Lookup::options('kyc_document');
    }

    /** Types that must be on file before a company can be approved. */
    public static function required(): array
    {
        return \App\Models\Lookup::flagged('kyc_document', 'required');
    }

    public static function typeLabel(?string $type): ?string
    {
        return \App\Models\Lookup::label('kyc_document', $type);
    }

    public const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public static function maxKb(): int
    {
        return \App\Support\BusinessRules::int('kyc.max_file_mb') * 1024;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'size' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Store an upload privately with a random name, keeping a SHA-256 fingerprint for integrity. */
    public static function storeFor(Company $company, string $type, UploadedFile $file, ?int $userId): self
    {
        $ext = strtolower($file->guessExtension() ?: 'bin');
        $path = $file->storeAs("kyc/{$company->id}", Str::uuid().'.'.$ext, 'local');

        return static::create([
            'company_id' => $company->id,
            'type' => $type,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit(preg_replace('/[^\w.\- ]/u', '_', $file->getClientOriginalName()), 150, ''),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $userId,
        ]);
    }

    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'status', 'review_note'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
