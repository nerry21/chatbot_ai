<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'pickup_location',
        'pickup_full_address',
        'destination',
        'destination_full_address',
        'trip_key',
        'departure_date',
        'departure_time',
        'passenger_count',
        'selected_seats',
        'passenger_name',
        'passenger_names',
        'special_notes',
        'price_estimate',
        'payment_method',
        'contact_number',
        'contact_same_as_sender',
        'booking_status',
        'confirmed_at',
    ];

    protected $casts = [
        'departure_date'  => 'date',
        'passenger_count' => 'integer',
        'selected_seats'  => 'array',
        'passenger_names' => 'array',
        'price_estimate'  => 'decimal:2',
        'contact_same_as_sender' => 'boolean',
        'confirmed_at'    => 'datetime',
        'booking_status'  => BookingStatus::class,
    ];

    /**
     * Required fields a booking must have before it can be sent for confirmation.
     *
     * @var array<int, string>
     */
    private const REQUIRED_FOR_CONFIRMATION = [
        'passenger_count',
        'departure_date',
        'departure_time',
        'selected_seats',
        'pickup_location',
        'pickup_full_address',
        'destination',
        'destination_full_address',
        'passenger_name',
        'contact_number',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bookingIntents(): HasMany
    {
        return $this->hasMany(BookingIntent::class);
    }

    public function leadPipelines(): HasMany
    {
        return $this->hasMany(LeadPipeline::class);
    }

    public function seatReservations(): HasMany
    {
        return $this->hasMany(BookingSeatReservation::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('booking_status', [
            BookingStatus::Draft->value,
            BookingStatus::AwaitingConfirmation->value,
        ]);
    }

    public function scopeForConversation(
        \Illuminate\Database\Eloquent\Builder $query,
        int $conversationId,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('conversation_id', $conversationId);
    }

    // -------------------------------------------------------------------------
    // Entity fill helper
    // -------------------------------------------------------------------------

    /**
     * Merge AI-extracted entities into this booking record.
     * Only overwrites a field if the incoming value is non-null AND the current
     * value is null — preserving data already confirmed by the customer.
     *
     * @param  array<string, mixed>  $entities  Output of EntityExtractorService.
     */
    public function fillFromEntities(array $entities): void
    {
        $map = [
            'customer_name'   => 'passenger_name',
            'pickup_location' => 'pickup_location',
            'destination'     => 'destination',
            'departure_date'  => 'departure_date',
            'departure_time'  => 'departure_time',
            'passenger_count' => 'passenger_count',
            'payment_method'  => 'payment_method',
            'notes'           => 'special_notes',
        ];

        foreach ($map as $entityKey => $bookingField) {
            $value = $entities[$entityKey] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // Don't overwrite a field the customer has already provided
            if ($this->{$bookingField} !== null) {
                continue;
            }

            $this->{$bookingField} = $value;
        }
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    /**
     * Return the list of required field names that are still null.
     *
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $required = config('chatbot.booking.required_fields', self::REQUIRED_FOR_CONFIRMATION);

        return array_values(
            array_filter($required, fn (string $field) => blank($this->{$field}))
        );
    }

    public function isReadyForConfirmation(): bool
    {
        return empty($this->missingFields());
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    /**
     * Transition to awaiting_confirmation.
     * Does NOT call save() — the caller is responsible.
     */
    public function markAwaitingConfirmation(): void
    {
        $this->booking_status = BookingStatus::AwaitingConfirmation;
    }

    /**
     * Transition to confirmed and record the timestamp.
     * Does NOT call save() — the caller is responsible.
     */
    public function markConfirmed(): void
    {
        $this->booking_status = BookingStatus::Confirmed;
        $this->confirmed_at   = now();
    }

    /**
     * Transition to cancelled.
     * Does NOT call save() — the caller is responsible.
     */
    public function markCancelled(): void
    {
        $this->booking_status = BookingStatus::Cancelled;
    }

    /**
     * Reset back to draft (e.g. customer rejected the summary).
     * Does NOT call save() — the caller is responsible.
     */
    public function resetToDraft(): void
    {
        $this->booking_status = BookingStatus::Draft;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isAwaitingConfirmation(): bool
    {
        return $this->booking_status === BookingStatus::AwaitingConfirmation;
    }

    public function isDraft(): bool
    {
        return $this->booking_status === BookingStatus::Draft;
    }

    public function isTerminal(): bool
    {
        return $this->booking_status->isTerminal();
    }

    /**
     * @return array<int, string>
     */
    public function passengerNamesList(): array
    {
        if (is_array($this->passenger_names) && $this->passenger_names !== []) {
            return array_values(array_filter(array_map(
                fn (mixed $name) => is_string($name) ? trim($name) : null,
                $this->passenger_names,
            )));
        }

        if ($this->passenger_name === null || trim($this->passenger_name) === '') {
            return [];
        }

        return [trim($this->passenger_name)];
    }

    public function hasSelectedSeats(): bool
    {
        return is_array($this->selected_seats) && $this->selected_seats !== [];
    }
}
