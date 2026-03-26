@extends('admin.chatbot.layouts.app')
@section('title', 'Knowledge Base')

@section('content')

<div class="flex items-center justify-between mb-5">
    <form method="GET" class="flex gap-3 items-end">
        <div>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Cari judul / konten"
                   class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-indigo-300">
        </div>
        <div>
            <select name="category" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <option value="">Semua kategori</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm px-4 py-1.5 rounded-md">Filter</button>
        @if (request('search') || request('category'))
            <a href="{{ route('admin.chatbot.knowledge.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
        @endif
    </form>
    <a href="{{ route('admin.chatbot.knowledge.create') }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-1.5 rounded-md transition-colors">
        + Artikel Baru
    </a>
</div>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left">Judul</th>
                <th class="px-4 py-3 text-left">Kategori</th>
                <th class="px-4 py-3 text-left">Keywords</th>
                <th class="px-4 py-3 text-left">Aktif</th>
                <th class="px-4 py-3 text-left">Diperbarui</th>
                <th class="px-4 py-3 text-left">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($articles as $article)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800">{{ $article->title }}</div>
                        <div class="text-xs text-gray-400 font-mono">{{ $article->slug }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="bg-blue-50 text-blue-700 text-xs px-2 py-0.5 rounded">{{ $article->category }}</span>
                    </td>
                    {{-- Tahap 10: keyword badges with count --}}
                    <td class="px-4 py-3">
                        @php $keywords = $article->keywords ?? []; @endphp
                        @if (!empty($keywords))
                            <div class="flex flex-wrap gap-1">
                                @foreach (array_slice($keywords, 0, 4) as $kw)
                                    <span class="bg-gray-100 text-gray-600 text-xs px-1.5 py-0.5 rounded">{{ $kw }}</span>
                                @endforeach
                                @if (count($keywords) > 4)
                                    <span class="bg-gray-100 text-gray-400 text-xs px-1.5 py-0.5 rounded">+{{ count($keywords) - 4 }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="{{ $article->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }} text-xs px-2 py-0.5 rounded">
                            {{ $article->is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">{{ $article->updated_at->format('d M Y') }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.chatbot.knowledge.edit', $article) }}"
                           class="text-indigo-600 hover:underline text-xs">Edit →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">Belum ada artikel.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $articles->links() }}</div>

@endsection
