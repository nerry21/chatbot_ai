@extends('admin.chatbot.layouts.app')
@section('title', 'Artikel Baru')

@section('content')

<div class="mb-4">
    <a href="{{ route('admin.chatbot.knowledge.index') }}" class="text-sm text-indigo-600 hover:underline">← Kembali ke Knowledge Base</a>
</div>

<div class="max-w-2xl">
    <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-5">Tambah Artikel Baru</h2>

        <form method="POST" action="{{ route('admin.chatbot.knowledge.store') }}" class="space-y-5">
            @csrf

            {{-- Category --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Kategori <span class="text-red-500">*</span></label>
                <input type="text" name="category" value="{{ old('category') }}"
                       list="category-list"
                       placeholder="e.g. faq, rute, harga, kebijakan"
                       class="w-full border @error('category') border-red-400 @else border-gray-300 @enderror rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <datalist id="category-list">
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}">
                    @endforeach
                </datalist>
                @error('category')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Title --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Judul <span class="text-red-500">*</span></label>
                <input type="text" name="title" value="{{ old('title') }}"
                       placeholder="Judul artikel"
                       class="w-full border @error('title') border-red-400 @else border-gray-300 @enderror rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                @error('title')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Content --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Konten <span class="text-red-500">*</span></label>
                <textarea name="content" rows="10"
                          placeholder="Tulis konten artikel di sini..."
                          class="w-full border @error('content') border-red-400 @else border-gray-300 @enderror rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 font-mono">{{ old('content') }}</textarea>
                @error('content')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            {{-- Keywords --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Keywords</label>
                <input type="text" name="keywords" value="{{ old('keywords') }}"
                       placeholder="booking, rute, pekanbaru (pisahkan dengan koma)"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <p class="mt-1 text-xs text-gray-400">Pisahkan setiap keyword dengan koma.</p>
            </div>

            {{-- Active --}}
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" id="is_active" value="1"
                       @checked(old('is_active', '1') === '1')
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <label for="is_active" class="text-sm text-gray-700">Aktif (dapat digunakan oleh chatbot)</label>
            </div>

            <div class="pt-2 flex gap-3">
                <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-5 py-2 rounded-md transition-colors">
                    Simpan Artikel
                </button>
                <a href="{{ route('admin.chatbot.knowledge.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700 px-4 py-2">Batal</a>
            </div>
        </form>
    </div>
</div>

@endsection
