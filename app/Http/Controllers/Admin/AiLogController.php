<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiLogController extends Controller
{
    /**
     * Valid quality label values for filtering.
     *
     * @var array<int, string>
     */
    private const QUALITY_LABEL_OPTIONS = [
        'low_confidence',
        'fallback',
        'knowledge_used',
        'faq_direct',
    ];

    public function index(Request $request): View
    {
        $query = AiLog::with('conversation')->latest();

        if ($taskType = $request->get('task_type')) {
            $query->where('task_type', $taskType);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($conversationId = $request->get('conversation_id')) {
            $query->where('conversation_id', (int) $conversationId);
        }

        // Tahap 10: quality filters
        if ($qualityLabel = $request->get('quality_label')) {
            if (in_array($qualityLabel, self::QUALITY_LABEL_OPTIONS, true)) {
                $query->where('quality_label', $qualityLabel);
            }
        }

        if ($request->boolean('has_knowledge_hits')) {
            $query->whereNotNull('knowledge_hits');
        }

        $logs = $query->paginate(30)->withQueryString();

        $taskTypeOptions = AiLog::distinct('task_type')
            ->orderBy('task_type')
            ->pluck('task_type');

        $statusOptions       = ['success', 'failed', 'skipped'];
        $qualityLabelOptions = self::QUALITY_LABEL_OPTIONS;

        return view('admin.chatbot.ai-logs.index', compact(
            'logs',
            'taskTypeOptions',
            'statusOptions',
            'qualityLabelOptions',
        ));
    }
}
