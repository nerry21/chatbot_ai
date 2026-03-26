<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingLeadController extends Controller
{
    public function index(Request $request): View
    {
        $query = BookingRequest::with('customer')
            ->latest('updated_at');

        if ($status = $request->get('status')) {
            $query->where('booking_status', $status);
        }

        if ($search = $request->get('search')) {
            $query->whereHas('customer', function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%");
            });
        }

        $bookings = $query->paginate(25)->withQueryString();

        $statusOptions = [
            'draft',
            'awaiting_confirmation',
            'confirmed',
            'paid',
            'cancelled',
            'completed',
        ];

        return view('admin.chatbot.bookings.index', compact('bookings', 'statusOptions'));
    }
}
