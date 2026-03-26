<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeArticleStoreRequest;
use App\Http\Requests\Admin\KnowledgeArticleUpdateRequest;
use App\Models\KnowledgeArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): View
    {
        $query = KnowledgeArticle::latest();

        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $articles = $query->paginate(25)->withQueryString();

        $categories = KnowledgeArticle::distinct('category')
            ->orderBy('category')
            ->pluck('category');

        return view('admin.chatbot.knowledge.index', compact('articles', 'categories'));
    }

    public function create(): View
    {
        $categories = KnowledgeArticle::distinct('category')
            ->orderBy('category')
            ->pluck('category');

        return view('admin.chatbot.knowledge.create', compact('categories'));
    }

    public function store(KnowledgeArticleStoreRequest $request): RedirectResponse
    {
        $article = KnowledgeArticle::create([
            'category'  => $request->input('category'),
            'title'     => $request->input('title'),
            'slug'      => KnowledgeArticle::generateSlug($request->input('title')),
            'content'   => $request->input('content'),
            'keywords'  => $this->parseKeywords($request->input('keywords', '')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('admin.chatbot.knowledge.index')
            ->with('success', "Artikel \"{$article->title}\" berhasil disimpan.");
    }

    public function edit(KnowledgeArticle $knowledgeArticle): View
    {
        $categories = KnowledgeArticle::distinct('category')
            ->orderBy('category')
            ->pluck('category');

        return view('admin.chatbot.knowledge.edit', compact('knowledgeArticle', 'categories'));
    }

    public function update(KnowledgeArticleUpdateRequest $request, KnowledgeArticle $knowledgeArticle): RedirectResponse
    {
        $knowledgeArticle->update([
            'category'  => $request->input('category'),
            'title'     => $request->input('title'),
            'slug'      => KnowledgeArticle::generateSlug($request->input('title'), $knowledgeArticle->id),
            'content'   => $request->input('content'),
            'keywords'  => $this->parseKeywords($request->input('keywords', '')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('admin.chatbot.knowledge.index')
            ->with('success', "Artikel \"{$knowledgeArticle->title}\" berhasil diperbarui.");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert comma-separated keyword string into a clean array.
     *
     * @return array<int, string>
     */
    private function parseKeywords(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $k) => trim($k))
            ->filter()
            ->values()
            ->all();
    }
}
