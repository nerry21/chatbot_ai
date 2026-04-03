<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PublicPageController extends Controller
{
    public function privacyPolicy(): View
    {
        return view('public.privacy-policy', [
            'pageTitle' => 'Privacy Policy | Chatbot AI',
            'metaDescription' => 'Kebijakan Privasi Chatbot AI / Admin Jet di spesial.online untuk layanan chatbot, omnichannel, dan integrasi WhatsApp.',
            'appName' => 'Chatbot AI / Admin Jet',
            'domain' => 'spesial.online',
            'adminEmail' => 'admin@spesial.online',
            'adminWhatsApp' => '+62xxxxxxxxxxx',
            'effectiveDate' => '03 April 2026',
        ]);
    }
}
