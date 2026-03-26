<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $query = Customer::withCount(['conversations', 'tags'])
            ->latest('last_interaction_at');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $customers = $query->paginate(25)->withQueryString();

        return view('admin.chatbot.customers.index', compact('customers'));
    }

    public function show(Customer $customer): View
    {
        $customer->load([
            'aliases',
            'tags',
            'crmContact',
            'leadPipelines' => fn ($q) => $q->latest()->limit(10),
        ]);

        $conversations = $customer->conversations()
            ->latest('last_message_at')
            ->limit(10)
            ->get();

        $bookings = $customer->bookingRequests()
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.chatbot.customers.show', compact('customer', 'conversations', 'bookings'));
    }
}
