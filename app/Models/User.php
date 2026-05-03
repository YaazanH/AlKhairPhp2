<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\AvatarDefaults;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'issued_password',
        'profile_photo_path',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'issued_password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'issued_password' => 'encrypted',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    public function profilePhotoPath(): ?string
    {
        return $this->studentProfile?->photo_path
            ?: ($this->teacherProfile?->photo_path ?: $this->profile_photo_path);
    }

    public function storeProfilePhotoUpload(mixed $upload): string
    {
        $this->loadMissing(['studentProfile', 'teacherProfile']);

        if ($this->studentProfile) {
            if ($this->studentProfile->photo_path) {
                Storage::disk('public')->delete($this->studentProfile->photo_path);
            }

            $path = $upload->store('students/photos/'.$this->studentProfile->id, 'public');
            $this->studentProfile->forceFill(['photo_path' => $path])->save();

            return $path;
        }

        if ($this->teacherProfile) {
            if ($this->teacherProfile->photo_path) {
                Storage::disk('public')->delete($this->teacherProfile->photo_path);
            }

            $path = $upload->store('teachers/photos/'.$this->teacherProfile->id, 'public');
            $this->teacherProfile->forceFill(['photo_path' => $path])->save();

            return $path;
        }

        if ($this->profile_photo_path) {
            Storage::disk('public')->delete($this->profile_photo_path);
        }

        $path = $upload->store('users/photos/'.$this->id, 'public');
        $this->forceFill(['profile_photo_path' => $path])->save();

        return $path;
    }

    public function profilePhotoUrl(): ?string
    {
        $path = $this->profilePhotoPath();

        if ($path) {
            return '/storage/'.ltrim($path, '/');
        }

        if ($this->studentProfile) {
            return AvatarDefaults::url('student');
        }

        if ($this->teacherProfile) {
            return AvatarDefaults::url('teacher');
        }

        if ($this->parentProfile) {
            return AvatarDefaults::url('parent');
        }

        return AvatarDefaults::url('user');
    }

    public function usesLinkedProfilePhoto(): bool
    {
        return (bool) ($this->studentProfile || $this->teacherProfile);
    }

    public function parentProfile(): HasOne
    {
        return $this->hasOne(ParentProfile::class);
    }

    public function activityExpenses(): HasMany
    {
        return $this->hasMany(ActivityExpense::class, 'entered_by');
    }

    public function financeCashBoxes(): BelongsToMany
    {
        return $this->belongsToMany(FinanceCashBox::class, 'finance_cash_box_user')->withTimestamps();
    }

    public function financeRequests(): HasMany
    {
        return $this->hasMany(FinanceRequest::class, 'requested_by');
    }

    public function financeTransactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'entered_by');
    }

    public function activityPayments(): HasMany
    {
        return $this->hasMany(ActivityPayment::class, 'entered_by');
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class, 'entered_by');
    }

    public function studentNotesAuthored(): HasMany
    {
        return $this->hasMany(StudentNote::class, 'author_id');
    }

    public function scopeOverrides(): HasMany
    {
        return $this->hasMany(UserScopeOverride::class);
    }

    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function studentAttendanceDays(): HasMany
    {
        return $this->hasMany(StudentAttendanceDay::class, 'created_by');
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function voidedPointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class, 'voided_by');
    }

    public function voidedActivityPayments(): HasMany
    {
        return $this->hasMany(ActivityPayment::class, 'voided_by');
    }

    public function voidedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'voided_by');
    }
}
