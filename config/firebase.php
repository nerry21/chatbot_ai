<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Project ID
    |--------------------------------------------------------------------------
    |
    | Project ID dari Firebase Console. Bisa ditemukan di:
    | Firebase Console → Project Settings → General → Project ID
    |
    | Contoh: 'what-jet-chatbot'
    |
    */

    'project_id' => env('FIREBASE_PROJECT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Service Account Credentials File
    |--------------------------------------------------------------------------
    |
    | Path ke file JSON service account yang didownload dari Firebase Console.
    | Bisa path relatif (dari root project Laravel) atau path absolut.
    |
    | Contoh:
    |   'firebase-service-account.json'      → di root project
    |   'storage/firebase-credentials.json'  → di folder storage
    |   '/home/user/firebase-sa.json'        → path absolut
    |
    | PENTING: File ini berisi private key! Jangan commit ke Git.
    |          Tambahkan ke .gitignore: firebase-service-account.json
    |
    */

    'credentials_file' => env('FIREBASE_CREDENTIALS_FILE', 'firebase-service-account.json'),

    /*
    |--------------------------------------------------------------------------
    | FCM Notification Defaults
    |--------------------------------------------------------------------------
    */

    'notification' => [
        // Apakah push notification aktif. Set false untuk disable sementara.
        'enabled' => (bool) env('FCM_NOTIFICATIONS_ENABLED', true),

        // Android notification channel ID (harus cocok dengan Flutter code).
        'android_channel_id' => 'whatjet_chat_messages',
    ],

];