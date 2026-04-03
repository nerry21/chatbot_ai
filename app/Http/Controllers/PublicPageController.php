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

    public function terms(): View
    {
        return view('public.terms', [
            'pageTitle' => 'Terms of Service | Chatbot AI',
            'metaDescription' => 'Syarat dan ketentuan penggunaan layanan Chatbot AI / Admin Jet di spesial.online untuk chatbot, live chat, omnichannel, dan integrasi WhatsApp.',
            'appName' => 'Chatbot AI / Admin Jet',
            'websiteUrl' => 'https://spesial.online',
            'adminEmail' => 'admin@spesial.online',
            'adminWhatsApp' => '+62xxxxxxxxxxx',
            'effectiveDate' => '03 April 2026',
        ]);
    }

    public function dataDeletion(): View
    {
        return view('public.data-deletion', [
            'pageTitle' => 'Data Deletion | Chatbot AI',
            'metaDescription' => 'Instruksi penghapusan data pengguna untuk layanan Chatbot AI / Admin Jet di spesial.online, termasuk pengajuan permintaan dan proses verifikasi.',
            'appName' => 'Chatbot AI / Admin Jet',
            'websiteUrl' => 'https://spesial.online',
            'adminEmail' => 'admin@spesial.online',
            'adminWhatsApp' => '+62xxxxxxxxxxx',
            'effectiveDate' => '03 April 2026',
        ]);
    }
}
