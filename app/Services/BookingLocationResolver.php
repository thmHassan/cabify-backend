<?php

namespace App\Services;

use App\Models\CompanyBooking;
use Illuminate\Http\Request;

class BookingLocationResolver
{
    public function __construct(
        private readonly MapifyReverseGeocodingService $mapifyReverseGeocoding
    ) {
    }

    public function resolveFromRequest(Request $request): void
    {
        if ($request->has('pickup_point') || $request->has('pickup_location')) {
            $request->merge([
                'pickup_location' => $this->mapifyReverseGeocoding->resolveDisplayName(
                    $request->input('pickup_location'),
                    $request->input('pickup_point')
                ),
            ]);
        }

        if ($request->has('destination_point') || $request->has('destination_location')) {
            $request->merge([
                'destination_location' => $this->mapifyReverseGeocoding->resolveDisplayName(
                    $request->input('destination_location'),
                    $request->input('destination_point')
                ),
            ]);
        }
    }

    public function resolveForBooking(CompanyBooking $booking, bool $persist = false): CompanyBooking
    {
        $originalPickup = $booking->pickup_location;
        $originalDestination = $booking->destination_location;

        $pickupLocation = $this->mapifyReverseGeocoding->resolveDisplayName(
            $booking->pickup_location,
            $booking->pickup_point
        );

        $destinationLocation = $this->mapifyReverseGeocoding->resolveDisplayName(
            $booking->destination_location,
            $booking->destination_point
        );

        if ($pickupLocation !== null) {
            $booking->pickup_location = $pickupLocation;
        }

        if ($destinationLocation !== null) {
            $booking->destination_location = $destinationLocation;
        }

        if ($persist && $booking->exists) {
            $pickupChanged = $pickupLocation !== null
                && $pickupLocation !== $originalPickup
                && $this->mapifyReverseGeocoding->looksLikeCoordinates($originalPickup);
            $destinationChanged = $destinationLocation !== null
                && $destinationLocation !== $originalDestination
                && $this->mapifyReverseGeocoding->looksLikeCoordinates($originalDestination);

            if ($pickupChanged || $destinationChanged) {
                $booking->save();
            }
        }

        return $booking;
    }

    /**
     * @param iterable<CompanyBooking> $bookings
     */
    public function resolveCollection(iterable $bookings, bool $persist = false): void
    {
        foreach ($bookings as $booking) {
            if ($booking instanceof CompanyBooking) {
                $this->resolveForBooking($booking, $persist);
            }
        }
    }
}
